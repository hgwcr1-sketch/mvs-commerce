<?php

namespace Tests\Feature;

use App\Models\CobrosRegistrationDraft;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosActivationContract;
use App\Services\Cobros\CobrosActivationContractService;
use App\Services\Cobros\LaborInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BLOQUE 16C-2B f2 — contrato transaccional de activación futura.
 *
 * Cubre: contrato de solo lectura con clave idempotente por borrador, bloqueo
 * de execute() hasta la confirmación de pago (cero aprovisionamiento), consumo
 * atómico de la invitación con reutilización/vencimiento/revocación bloqueados,
 * vínculo con el prestamista correcto, una sola cuenta por cobrador y carteras
 * aisladas por prestamista.
 */
class CobrosActivationContractTest extends TestCase
{
    use RefreshDatabase;

    private CobrosActivationContractService $service;

    private LaborInvitationService $invitations;

    /** Tablas que SOLO el bloque 17 (post-pago) podrá escribir. */
    private const PROVISIONING_TABLES = [
        'companies', 'company_user', 'company_licenses', 'company_modules',
        'cobros_routes', 'cobros_route_user', 'cobros_collector_licenses',
        'cobros_route_licenses', 'cobros_assignments', 'roles',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CobrosActivationContractService::class);
        $this->invitations = app(LaborInvitationService::class);
    }

    private function lender(string $email): array
    {
        $lender = User::factory()->create(['is_active' => true, 'email' => $email]);
        $company = Company::create([
            'trade_name' => 'Prestamista '.explode('@', $email)[0],
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);

        return [$company, $lender];
    }

    private function provisioningSnapshot(): array
    {
        return collect(self::PROVISIONING_TABLES)
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }

    private function assertNoProvisioning(array $snapshot): void
    {
        $this->assertSame($snapshot, $this->provisioningSnapshot());
    }

    /** Borrador completo con referencia real de invitación (sin consumir). */
    private function linkedDraft(array $issued, array $overrides = []): CobrosRegistrationDraft
    {
        $email = $issued['invitation']->recipient_email;
        $payload = array_replace_recursive([
            'country_iso' => 'CR',
            'modality' => 'cobrador_vinculado',
            'personal' => ['name' => 'Cobrador Contratado', 'email' => $email, 'phone' => '8888-8888'],
            'portfolio_plan' => 'manual',
            'routes' => [[
                'id' => (string) Str::uuid(),
                'name' => 'Ruta Norte',
                'code' => 'RN',
            ]],
            'labor_invitation' => [
                'id' => $issued['invitation']->id,
                'token_hash' => $issued['invitation']->token_hash,
                'invited_by' => $issued['lender']->email,
                'lender_name' => $issued['lender']->name,
                'company' => $issued['company']->trade_name,
            ],
        ], $overrides);

        return CobrosRegistrationDraft::create([
            'email' => $email,
            'payload' => $payload,
            'last_saved_at' => now(),
        ]);
    }

    private function issue(Company $company, User $lender, string $email): array
    {
        $issued = $this->invitations->issue($company, $lender, $email);
        $issued['company'] = $company;
        $issued['lender'] = $lender;

        return $issued;
    }

    private function expectBlocked(callable $action, string $needle): void
    {
        try {
            $action();
            $this->fail('La operación debió bloquearse.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($needle, implode(' ', \Illuminate\Support\Arr::flatten($exception->errors())));
        }
    }

    public function test_contract_is_read_only_with_stable_idempotency_key_and_zero_provisioning(): void
    {
        [$company, $lender] = $this->lender('presta@contrato.test');
        $issued = $this->issue($company, $lender, 'cobrador@contrato.test');
        $draft = $this->linkedDraft($issued);
        $snapshot = $this->provisioningSnapshot();

        $contract = $this->service->prepare($draft);
        $again = $this->service->prepare($draft->refresh());

        $this->assertSame($contract->idempotencyKey, $again->idempotencyKey, 'El reintento produce la misma clave.');
        $this->assertSame($contract->toArray(), $again->toArray());
        $this->assertSame(CobrosActivationContract::STATUS_PENDING_PAYMENT, $contract->status);
        $this->assertFalse($contract->paymentConfirmed);
        $this->assertSame($draft->id, $contract->draftId);
        $this->assertSame('cobrador_vinculado', $contract->modality);
        $this->assertCount(1, $contract->routes);

        $this->assertSame($company->id, $contract->linkage['company_id']);
        $this->assertSame($lender->id, $contract->linkage['lender_user_id']);
        $this->assertSame($lender->email, $contract->linkage['lender_email']);
        $this->assertSame('cobrador@contrato.test', $contract->linkage['recipient_email']);

        $this->assertNull($issued['invitation']->fresh()->used_at, 'La preparación no consume la invitación.');
        $this->assertNoProvisioning($snapshot);
        $this->assertSame(1, CobrosRegistrationDraft::count());
    }

    public function test_execute_is_blocked_until_payment_confirmation_and_writes_nothing(): void
    {
        [$company, $lender] = $this->lender('presta@bloqueo.test');
        $issued = $this->issue($company, $lender, 'cobrador@bloqueo.test');
        $draft = $this->linkedDraft($issued);
        $contract = $this->service->prepare($draft);
        $snapshot = $this->provisioningSnapshot();

        $this->expectBlocked(fn () => $this->service->execute($contract), 'confirmación de pago');

        $this->assertNoProvisioning($snapshot);
        $this->assertNull($issued['invitation']->fresh()->used_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertDatabaseCount('company_licenses', 0);
        $this->assertDatabaseCount('company_modules', 0);
    }

    public function test_invitation_consumption_is_atomic_and_idempotent_for_the_same_draft(): void
    {
        [$company, $lender] = $this->lender('presta@consumo.test');
        $issued = $this->issue($company, $lender, 'cobrador@consumo.test');
        $draft = $this->linkedDraft($issued);
        $snapshot = $this->provisioningSnapshot();

        $first = $this->service->consumeInvitation($draft);

        $this->assertFalse($first['already_consumed']);
        $this->assertSame($company->id, $first['company_id']);
        $this->assertSame($lender->id, $first['lender_user_id']);

        $usedAt = $issued['invitation']->fresh()->used_at;
        $this->assertNotNull($usedAt);

        // Reintento del MISMO borrador: idempotente, sin nuevo consumo.
        $retry = $this->service->consumeInvitation($draft->refresh());

        $this->assertTrue($retry['already_consumed']);
        $this->assertSame($first['invitation_id'], $retry['invitation_id']);
        $this->assertTrue($usedAt->equalTo($issued['invitation']->fresh()->used_at));

        $this->assertNoProvisioning($snapshot);
        $this->assertSame(1, CobrosRegistrationDraft::count());
    }

    public function test_consumption_blocks_reuse_by_another_draft_revocation_and_expiry(): void
    {
        // Reutilización por otro borrador: bloqueada.
        [$companyA, $lenderA] = $this->lender('presta.a@reuso.test');
        $issuedA = $this->issue($companyA, $lenderA, 'cobrador@reuso.test');
        $owner = $this->linkedDraft($issuedA);
        $this->service->consumeInvitation($owner);

        $impostor = $this->linkedDraft($issuedA);
        $this->expectBlocked(fn () => $this->service->consumeInvitation($impostor), 'ya fue utilizada');
        $this->assertSame(0, User::where('email', 'cobrador@reuso.test')->count(),
            'La reutilización no debe crear otra cuenta.');

        // Revocación: bloqueada y sin consumir.
        [$companyB, $lenderB] = $this->lender('presta.b@reuso.test');
        $issuedB = $this->issue($companyB, $lenderB, 'cobrador2@reuso.test');
        $revoked = $this->linkedDraft($issuedB);
        $this->invitations->revoke($companyB, $lenderB, $issuedB['invitation']->id);
        $this->expectBlocked(fn () => $this->service->consumeInvitation($revoked), 'revocada');
        $this->assertNull($issuedB['invitation']->fresh()->used_at);

        // Vencimiento: bloqueado y sin consumir.
        [$companyC, $lenderC] = $this->lender('presta.c@reuso.test');
        $issuedC = $this->issue($companyC, $lenderC, 'cobrador3@reuso.test');
        $expired = $this->linkedDraft($issuedC);
        $this->travel(1441)->minutes();
        $this->expectBlocked(fn () => $this->service->consumeInvitation($expired), 'caducó');
        $this->assertNull($issuedC['invitation']->fresh()->used_at);
    }

    public function test_contract_links_the_exact_lender_while_one_collector_keeps_one_account(): void
    {
        [$companyA, $lenderA] = $this->lender('presta.a@vinculo.test');
        [$companyB, $lenderB] = $this->lender('presta.b@vinculo.test');
        $issuedA = $this->issue($companyA, $lenderA, 'cobrador@vinculo.test');
        $issuedB = $this->issue($companyB, $lenderB, 'cobrador@vinculo.test');
        $draft = $this->linkedDraft($issuedA);
        $snapshot = $this->provisioningSnapshot();

        $withA = $this->service->prepare($draft);
        $this->assertSame($companyA->id, $withA->linkage['company_id']);
        $this->assertSame($lenderA->id, $withA->linkage['lender_user_id']);

        // El mismo cobrador cambia a la invitación del otro prestamista.
        $payload = $draft->payload;
        $payload['labor_invitation'] = [
            'id' => $issuedB['invitation']->id,
            'token_hash' => $issuedB['invitation']->token_hash,
            'invited_by' => $lenderB->email,
            'lender_name' => $lenderB->name,
            'company' => $companyB->trade_name,
        ];
        $draft->update(['payload' => $payload]);

        $withB = $this->service->prepare($draft->refresh());

        $this->assertSame($companyB->id, $withB->linkage['company_id']);
        $this->assertSame($lenderB->id, $withB->linkage['lender_user_id']);
        $this->assertSame($withA->idempotencyKey, $withB->idempotencyKey,
            'La clave es POR BORRADOR: cambia el prestamista, no la identidad de la cuenta.');

        $this->assertSame(1, CobrosRegistrationDraft::count(), 'Una sola cuenta/borrador por cobrador.');
        $this->assertSame(0, User::where('email', 'cobrador@vinculo.test')->count());
        $this->assertNull($issuedA['invitation']->fresh()->used_at);
        $this->assertNull($issuedB['invitation']->fresh()->used_at);
        $this->assertNoProvisioning($snapshot);
    }

    public function test_multiprestamista_contract_keeps_routes_and_portfolios_isolated_per_lender(): void
    {
        $snapshot = $this->provisioningSnapshot();
        $email = 'multi@aislamiento.test';

        $first = CobrosRegistrationDraft::create([
            'email' => $email,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_multiprestamista',
                'personal' => ['name' => 'Cobrador Multi', 'email' => $email, 'phone' => null],
                'portfolio_plan' => 'manual',
                'routes' => [
                    ['id' => (string) Str::uuid(), 'name' => 'Ruta Norte', 'lender' => 'Prestamista Norte'],
                    ['id' => (string) Str::uuid(), 'name' => 'Ruta Sur', 'lender' => 'Prestamista Sur'],
                ],
            ],
            'last_saved_at' => now(),
        ]);

        $contract = $this->service->prepare($first);

        $this->assertNull($contract->linkage, 'Sin invitación no se crea vínculo con ningún prestamista.');
        $this->assertSame(
            ['Prestamista Norte', 'Prestamista Sur'],
            array_column($contract->routes, 'lender'),
            'Cada ruta conserva su propio prestamista: las carteras no se mezclan.'
        );

        // Otro cobrador: borrador y contrato independientes, sin solape.
        $otherEmail = 'otro@aislamiento.test';
        $second = CobrosRegistrationDraft::create([
            'email' => $otherEmail,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_multiprestamista',
                'personal' => ['name' => 'Otro Cobrador', 'email' => $otherEmail, 'phone' => null],
                'portfolio_plan' => 'manual',
                'routes' => [
                    ['id' => (string) Str::uuid(), 'name' => 'Ruta Este', 'lender' => 'Prestamista Este'],
                ],
            ],
            'last_saved_at' => now(),
        ]);

        $other = $this->service->prepare($second);

        $this->assertNotSame($contract->idempotencyKey, $other->idempotencyKey);
        $this->assertSame(['Prestamista Este'], array_column($other->routes, 'lender'));
        $this->assertSame(0, User::where('email', $email)->count());
        $this->assertSame(0, User::where('email', $otherEmail)->count());
        $this->assertNoProvisioning($snapshot);
    }

    public function test_missing_or_foreign_invitation_blocks_the_contract_and_consumption(): void
    {
        [$company, $lender] = $this->lender('presta@ajena.test');
        $issued = $this->issue($company, $lender, 'invitado@ajena.test');
        $draft = $this->linkedDraft($issued);
        $snapshot = $this->provisioningSnapshot();

        // Destinatario distinto: ni contrato ni consumo.
        $payload = $draft->payload;
        $payload['personal']['email'] = 'otro@ajena.test';
        $draft->update(['payload' => $payload]);

        $this->expectBlocked(fn () => $this->service->prepare($draft->refresh()), 'otro correo electrónico');
        $this->expectBlocked(fn () => $this->service->consumeInvitation($draft->refresh()), 'otro correo electrónico');

        // Sin referencia de invitación: tampoco.
        $payload['personal']['email'] = 'invitado@ajena.test';
        unset($payload['labor_invitation']);
        $draft->update(['payload' => $payload]);

        $this->expectBlocked(fn () => $this->service->prepare($draft->refresh()), 'invitación laboral');
        $this->expectBlocked(fn () => $this->service->consumeInvitation($draft->refresh()), 'invitación laboral');

        $this->assertNull($issued['invitation']->fresh()->used_at);
        $this->assertNoProvisioning($snapshot);
    }
}
