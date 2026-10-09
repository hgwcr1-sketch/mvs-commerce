<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosRegistrationDraft;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosActivationContractService;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\CobrosPostPaymentActivationService;
use App\Services\Cobros\LaborInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BLOQUE 17A-2 f2 — activación posterior al pago.
 *
 * Cubre: pago confirmado activa el borrador, pago duplicado no duplica nada,
 * invitación inválida bloquea, rollback sin perder el pago, reintento tras
 * fallo, y aislamiento empresarial.
 */
class CobrosPostPaymentActivation17A2Test extends TestCase
{
    use RefreshDatabase;

    private CobrosPostPaymentActivationService $service;

    private CobrosActivationContractService $activation;

    private CobrosPaymentConfirmationService $payments;

    private CobrosBillingContractService $billingContracts;

    private LaborInvitationService $invitations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CobrosPostPaymentActivationService::class);
        $this->activation = app(CobrosActivationContractService::class);
        $this->payments = app(CobrosPaymentConfirmationService::class);
        $this->billingContracts = app(CobrosBillingContractService::class);
        $this->invitations = app(LaborInvitationService::class);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
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

    /** Borrador linked completo con referencia real de invitación. */
    private function linkedDraft(array $issued): CobrosRegistrationDraft
    {
        $email = $issued['invitation']->recipient_email;

        return CobrosRegistrationDraft::create([
            'email' => $email,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_vinculado',
                'personal' => ['name' => 'Cobrador Activo', 'email' => $email, 'phone' => '8888-8888'],
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
            ],
            'last_saved_at' => now(),
        ]);
    }

    /** Borrador independiente (sin invitación). */
    private function independentDraft(string $email): CobrosRegistrationDraft
    {
        return CobrosRegistrationDraft::create([
            'email' => $email,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_independiente',
                'personal' => ['name' => 'Cobrador Solo', 'email' => $email, 'phone' => null],
                'portfolio_plan' => 'manual',
                'routes' => [[
                    'id' => (string) Str::uuid(),
                    'name' => 'Ruta Este',
                ]],
            ],
            'last_saved_at' => now(),
        ]);
    }

    /** Confirmación de pago CONFIRMADA para el contrato de una empresa. */
    private function confirmedPayment(Company $company, User $payer, User $admin): CobrosPaymentConfirmation
    {
        $contract = $this->billingContracts->createIndividual(
            $company, 'cobrador_independiente', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        return $this->payments->confirmInternal(
            $contract, $admin, 'tx-'.Str::uuid(), '10000.0000',
            now()->year, now()->month,
        );
    }

    public function test_confirmed_payment_activates_linked_collector_draft(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@activacion.test');
        $admin = $this->platformAdmin();

        $issued = $this->invitations->issue($lenderCompany, $lender, 'cobrador@activacion.test');
        $issued['company'] = $lenderCompany;
        $issued['lender'] = $lender;
        $draft = $this->linkedDraft($issued);

        $confirmation = $this->confirmedPayment($lenderCompany, $lender, $admin);

        $result = $this->service->activateFromPayment($confirmation, $draft);

        // Usuario creado y vinculado a la empresa del prestamista.
        $this->assertSame('cobrador@activacion.test', $result['user']->email);
        $this->assertSame($lenderCompany->id, $result['company']->id);

        // Ruta creada.
        $this->assertNotNull($result['route']);
        $this->assertSame($lenderCompany->id, $result['route']->company_id);

        // Invitación consumida DESPUÉS del pago.
        $this->assertNotNull($issued['invitation']->fresh()->used_at);

        // Membresía con modalidad asignada.
        $membership = DB::table('company_user')
            ->where('company_id', $lenderCompany->id)
            ->where('user_id', $result['user']->id)
            ->first();
        $this->assertNotNull($membership);
        $this->assertSame('cobrador_vinculado', $membership->cobros_modality);
    }

    public function test_duplicate_payment_does_not_duplicate_users_companies_routes_or_licenses(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@dup.test');
        $admin = $this->platformAdmin();

        $issued = $this->invitations->issue($lenderCompany, $lender, 'cobrador@dup.test');
        $issued['company'] = $lenderCompany;
        $issued['lender'] = $lender;
        $draft = $this->linkedDraft($issued);

        $confirmation = $this->confirmedPayment($lenderCompany, $lender, $admin);

        $first = $this->service->activateFromPayment($confirmation, $draft);
        $second = $this->service->activateFromPayment($confirmation, $draft->refresh());

        // Mismo usuario, misma empresa, misma ruta: sin duplicados.
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertSame($first['company']->id, $second['company']->id);
        $this->assertSame($first['route']->id, $second['route']->id);

        $this->assertSame(1, User::where('email', 'cobrador@dup.test')->count());
        $this->assertSame(1, CobrosRoute::forCompany($lenderCompany->id)->count());
    }

    public function test_invalid_invitation_blocks_activation_after_payment(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@invalida.test');
        $admin = $this->platformAdmin();

        $issued = $this->invitations->issue($lenderCompany, $lender, 'cobrador@invalida.test');
        $issued['company'] = $lenderCompany;
        $issued['lender'] = $lender;
        $draft = $this->linkedDraft($issued);

        // Revoca la invitación DESPUÉS de emitirla.
        $this->invitations->revoke($lenderCompany, $lender, $issued['invitation']->id);

        $confirmation = $this->confirmedPayment($lenderCompany, $lender, $admin);

        try {
            $this->service->activateFromPayment($confirmation, $draft);
            $this->fail('La invitación revocada debió bloquear la activación.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'revocada',
                implode(' ', \Illuminate\Support\Arr::flatten($exception->errors()))
            );
        }

        // La invitación NO se consumió y no se creó ningún usuario.
        $this->assertNull($issued['invitation']->fresh()->used_at);
        $this->assertSame(0, User::where('email', 'cobrador@invalida.test')->count());
    }

    public function test_provisioning_failure_keeps_payment_confirmed_and_contract_not_active(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@rollback.test');
        $admin = $this->platformAdmin();

        $issued = $this->invitations->issue($lenderCompany, $lender, 'cobrador@rollback.test');
        $issued['company'] = $lenderCompany;
        $issued['lender'] = $lender;
        $draft = $this->linkedDraft($issued);

        $confirmation = $this->confirmedPayment($lenderCompany, $lender, $admin);

        // Simula fallo de aprovisionamiento: revoca la invitación justo antes.
        $this->invitations->revoke($lenderCompany, $lender, $issued['invitation']->id);

        try {
            $this->service->activateFromPayment($confirmation, $draft);
            $this->fail('El fallo de aprovisionamiento debió propagarse.');
        } catch (ValidationException) {
            // Esperado.
        }

        // El pago confirmado NO se pierde.
        $this->assertSame(
            CobrosPaymentConfirmation::STATUS_CONFIRMED,
            $confirmation->fresh()->status
        );

        // La invitación NO se consumió (rollback completo).
        $this->assertNull($issued['invitation']->fresh()->used_at);

        // No se creó ningún usuario.
        $this->assertSame(0, User::where('email', 'cobrador@rollback.test')->count());
    }

    public function test_retry_after_failure_succeeds_with_new_valid_invitation(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@reintento.test');
        $admin = $this->platformAdmin();

        $issued = $this->invitations->issue($lenderCompany, $lender, 'cobrador@reintento.test');
        $issued['company'] = $lenderCompany;
        $issued['lender'] = $lender;
        $draft = $this->linkedDraft($issued);

        $confirmation = $this->confirmedPayment($lenderCompany, $lender, $admin);

        // Primer intento falla (invitación revocada).
        $this->invitations->revoke($lenderCompany, $lender, $issued['invitation']->id);

        try {
            $this->service->activateFromPayment($confirmation, $draft);
            $this->fail('El primer intento debió fallar.');
        } catch (ValidationException) {
            // Esperado.
        }

        // Emite una NUEVA invitación válida y actualiza el borrador.
        $newIssued = $this->invitations->issue($lenderCompany, $lender, 'cobrador@reintento.test');
        $payload = $draft->payload;
        $payload['labor_invitation'] = [
            'id' => $newIssued['invitation']->id,
            'token_hash' => $newIssued['invitation']->token_hash,
            'invited_by' => $lender->email,
            'lender_name' => $lender->name,
            'company' => $lenderCompany->trade_name,
        ];
        $draft->update(['payload' => $payload]);

        // Reintento con el MISMO pago confirmado: ahora tiene éxito.
        $result = $this->service->activateFromPayment($confirmation, $draft->refresh());

        $this->assertSame('cobrador@reintento.test', $result['user']->email);
        $this->assertSame($lenderCompany->id, $result['company']->id);
        $this->assertNotNull($newIssued['invitation']->fresh()->used_at);
    }

    public function test_enterprise_activation_is_isolated_between_companies(): void
    {
        [$companyA, $payerA] = $this->lender('presta.a@aislamiento.test');
        [$companyB, $payerB] = $this->lender('presta.b@aislamiento.test');
        $admin = $this->platformAdmin();

        // Borrador independiente para A.
        $draftA = $this->independentDraft('cobrador.a@aislamiento.test');
        $confirmationA = $this->confirmedPayment($companyA, $payerA, $admin);

        $resultA = $this->service->activateFromPayment($confirmationA, $draftA);

        // Borrador independiente para B.
        $draftB = $this->independentDraft('cobrador.b@aislamiento.test');
        $confirmationB = $this->confirmedPayment($companyB, $payerB, $admin);

        $resultB = $this->service->activateFromPayment($confirmationB, $draftB);

        // Aislamiento: cada uno tiene su propia empresa (independientes crean la suya).
        $this->assertNotSame($resultA['company']->id, $resultB['company']->id);
        $this->assertNotSame($resultA['user']->id, $resultB['user']->id);
        $this->assertNotSame($companyA->id, $resultA['company']->id, 'Independiente crea su propia empresa.');
        $this->assertNotSame($companyB->id, $resultB['company']->id);

        // Cada empresa nueva tiene exactamente una ruta.
        $this->assertSame(1, CobrosRoute::forCompany($resultA['company']->id)->count());
        $this->assertSame(1, CobrosRoute::forCompany($resultB['company']->id)->count());

        // Los usuarios no se cruzan entre empresas.
        $this->assertFalse($resultA['user']->companies()->where('company_id', $resultB['company']->id)->exists());
        $this->assertFalse($resultB['user']->companies()->where('company_id', $resultA['company']->id)->exists());
    }
}
