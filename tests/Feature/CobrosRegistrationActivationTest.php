<?php

namespace Tests\Feature;

use App\Models\CobrosRegistrationDraft;
use App\Services\Cobros\CobrosRegistrationActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BLOQUE 16C-1 — Preparación de la activación del borrador de registro.
 *
 * Cubre: validación integral, clave idempotente por borrador, aislamiento
 * entre borradores y AUSENCIA total de escrituras operativas (empresas,
 * usuarios, rutas, clientes, créditos, CxC, membresías y licencias).
 */
class CobrosRegistrationActivationTest extends TestCase
{
    use RefreshDatabase;

    private CobrosRegistrationActivationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->service = app(CobrosRegistrationActivationService::class);
    }

    /** Borrador completo y válido; $overrides se fusionan en el payload. */
    private function draft(array $overrides = [], string $email = 'owner@activacion.test'): CobrosRegistrationDraft
    {
        $payload = array_replace_recursive([
            'country_iso' => 'CR',
            'modality' => 'prestamista',
            'personal' => [
                'name' => 'Dueño de Activación',
                'email' => $email,
                'phone' => '8888-8888',
            ],
            'portfolio_plan' => 'import',
            'referral' => ['code' => 'REF-abc_12345', 'captured_at' => '2026-10-09T10:00:00+00:00'],
            'routes' => [
                ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Ruta Norte', 'code' => 'RN'],
            ],
        ], $overrides);

        return CobrosRegistrationDraft::create([
            'email' => $email,
            'payload' => $payload,
            'last_saved_at' => now(),
        ]);
    }

    /** Archivo privado real en disco + registro de upload en el borrador. */
    private function attachUpload(CobrosRegistrationDraft $draft, string $kind = 'customers'): CobrosRegistrationDraft
    {
        $routeId = $draft->payload['routes'][0]['id'];
        $path = 'registration-uploads/'.$draft->id.'/cartera.xlsx';

        Storage::disk('local')->put($path, 'contenido-xlsx-sin-procesar');

        $payload = $draft->payload;
        $payload['uploads'] = [[
            'kind' => $kind,
            'route_id' => $routeId,
            'path' => $path,
            'original_name' => 'cartera.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rows' => 12,
            'sha256' => hash('sha256', 'contenido-xlsx-sin-procesar'),
            'uploaded_at' => now()->toIso8601String(),
            'status' => 'Pendiente de activación',
        ]];
        $draft->update(['payload' => $payload]);

        return $draft->refresh();
    }

    private function assertNoOperationalWrites(int $expectedUsers = 0): void
    {
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', $expectedUsers);
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);
        $this->assertDatabaseCount('cobros_credits', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('company_licenses', 0);
        $this->assertDatabaseCount('company_modules', 0);
    }

    public function test_complete_draft_validates_clean_and_builds_plan_without_processing_files(): void
    {
        $draft = $this->attachUpload($this->draft());

        $this->assertSame([], $this->service->validate($draft));

        $plan = $this->service->plan($draft);

        $this->assertSame($draft->payload['country_iso'], $plan['country_iso']);
        $this->assertSame('prestamista', $plan['modality']);
        $this->assertSame('owner@activacion.test', $plan['owner']['email']);
        $this->assertCount(1, $plan['routes']);
        $this->assertSame('Ruta Norte', $plan['routes'][0]['name']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $plan['routes'][0]['draft_route_id']);
        $this->assertSame('REF-abc_12345', $plan['referral']);

        $upload = $plan['routes'][0]['uploads'][0];
        $this->assertSame('customers', $upload['kind']);
        $this->assertSame('registration-uploads/'.$draft->id.'/cartera.xlsx', $upload['path']);
        $this->assertSame(12, $upload['rows']);
        $this->assertSame('Pendiente de activación', $upload['status']);
        $this->assertFalse($plan['processes_files'], 'El plan asocia archivos, no los procesa.');

        Storage::disk('local')->assertExists('registration-uploads/'.$draft->id.'/cartera.xlsx');
        $this->assertNoOperationalWrites();
    }

    public function test_incomplete_draft_reports_every_missing_section(): void
    {
        $draft = CobrosRegistrationDraft::create([
            'email' => 'incompleto@activacion.test',
            'payload' => [
                'country_iso' => 'XX',
                'modality' => 'inventada',
                'personal' => ['name' => 'A', 'email' => 'no-es-correo'],
                'routes' => [],
            ],
            'last_saved_at' => now(),
        ]);

        $errors = $this->service->validate($draft);

        $this->assertArrayHasKey('country_iso', $errors);
        $this->assertArrayHasKey('modality', $errors);
        $this->assertArrayHasKey('personal.name', $errors);
        $this->assertArrayHasKey('personal.email', $errors);
        $this->assertArrayHasKey('portfolio_plan', $errors);
        $this->assertArrayHasKey('routes', $errors);
        $this->assertNoOperationalWrites();
    }

    public function test_email_already_registered_blocks_activation(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['email' => 'ocupado@activacion.test']));

        $draft = $this->draft([], 'ocupado@activacion.test');

        $errors = $this->service->validate($draft);

        $this->assertArrayHasKey('personal.email', $errors);
        $this->assertStringContainsString('Ya existe una cuenta', $errors['personal.email']);
        $this->assertNoOperationalWrites(1);
    }

    public function test_multiprestamista_routes_require_lender_association(): void
    {
        $draft = $this->draft(['modality' => 'cobrador_multiprestamista']);

        $errors = $this->service->validate($draft);
        $this->assertArrayHasKey('routes.0.lender', $errors);

        $payload = $draft->payload;
        $payload['routes'][0]['lender'] = 'Prestamista Asociado';
        $draft->update(['payload' => $payload]);

        $this->assertSame([], $this->service->validate($draft->refresh()));
        $this->assertNoOperationalWrites();
    }

    public function test_linked_collector_without_valid_job_invitation_returns_controlled_error(): void
    {
        $draft = $this->draft([
            'modality' => 'cobrador_vinculado',
            'personal' => ['email' => 'cobrador@activacion.test'],
        ], 'cobrador@activacion.test');

        $errors = $this->service->validate($draft);

        $this->assertArrayHasKey('labor_invitation', $errors);
        $this->assertStringContainsString('invitación laboral', $errors['labor_invitation']);

        $this->expectException(ValidationException::class);
        try {
            $this->service->assertReady($draft);
        } finally {
            $this->assertNoOperationalWrites();
        }
    }

    public function test_linked_collector_with_valid_job_invitation_is_ready(): void
    {
        $lender = \App\Models\User::factory()->create([
            'is_active' => true, 'email' => 'prestamista@empleador.test',
        ]);
        $company = \App\Models\Company::create([
            'trade_name' => 'Prestamista Empleador', 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);
        $issued = app(\App\Services\Cobros\LaborInvitationService::class)
            ->issue($company, $lender, 'cobrador@activacion.test');

        $draft = $this->draft([
            'modality' => 'cobrador_vinculado',
            'personal' => ['email' => 'cobrador@activacion.test'],
            'labor_invitation' => [
                'id' => $issued['invitation']->id,
                'token_hash' => $issued['invitation']->token_hash,
                'invited_by' => $lender->email,
                'lender_name' => $lender->name,
                'company' => $company->trade_name,
            ],
        ], 'cobrador@activacion.test');

        $this->assertSame([], $this->service->validate($draft));

        // La preparación solo verifica: la invitación sigue sin consumirse.
        $this->assertNull($issued['invitation']->fresh()->used_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('cobros_routes', 0);
    }

    public function test_invitation_reference_in_plan_exposes_no_token_material(): void
    {
        $lender = \App\Models\User::factory()->create(['is_active' => true, 'email' => 'otro@empleador.test']);
        $company = \App\Models\Company::create([
            'trade_name' => 'Otro Prestamista', 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);
        $issued = app(\App\Services\Cobros\LaborInvitationService::class)
            ->issue($company, $lender, 'cobrador2@activacion.test');

        $draft = $this->draft([
            'modality' => 'cobrador_vinculado',
            'personal' => ['email' => 'cobrador2@activacion.test'],
            'labor_invitation' => [
                'id' => $issued['invitation']->id,
                'token_hash' => $issued['invitation']->token_hash,
                'invited_by' => $lender->email,
                'lender_name' => $lender->name,
                'company' => $company->trade_name,
            ],
        ], 'cobrador2@activacion.test');

        $plan = $this->service->plan($draft);

        $this->assertSame($issued['invitation']->id, $plan['labor_invitation']['id']);
        $this->assertSame($lender->email, $plan['labor_invitation']['invited_by']);
        $this->assertArrayNotHasKey('token_hash', $plan['labor_invitation']);
        $this->assertStringNotContainsString($issued['plain'], json_encode($plan));
        $this->assertNull($issued['invitation']->fresh()->used_at);
    }

    public function test_invalid_job_invitation_is_rejected_without_inventing_linkages(): void
    {
        $draft = $this->draft([
            'modality' => 'cobrador_vinculado',
            'personal' => ['email' => 'cobrador@activacion.test'],
            'labor_invitation' => ['code' => 'x', 'invited_by' => 'sin-correo'],
        ], 'cobrador@activacion.test');

        $errors = $this->service->validate($draft);

        $this->assertArrayHasKey('labor_invitation', $errors);
        $this->assertNoOperationalWrites();
    }

    public function test_idempotency_key_is_stable_per_draft_and_distinct_across_drafts(): void
    {
        $first = $this->draft([], 'uno@activacion.test');
        $second = $this->draft([], 'dos@activacion.test');

        $key = $this->service->idempotencyKey($first);

        $this->assertSame($key, $this->service->idempotencyKey($first->fresh()), 'El reintento produce la misma clave.');
        $this->assertSame($key, $this->service->plan($first)['idempotency_key']);
        $this->assertSame(64, strlen($key));
        $this->assertNotSame($key, $this->service->idempotencyKey($second));
        $this->assertNoOperationalWrites();
    }

    public function test_plan_is_isolated_from_other_drafts_and_orphan_files(): void
    {
        $mine = $this->attachUpload($this->draft([], 'mio@activacion.test'));
        $other = $this->attachUpload($this->draft([], 'otro@activacion.test'));

        $plan = $this->service->plan($mine);

        $paths = array_column($plan['routes'][0]['uploads'], 'path');
        $this->assertSame(['registration-uploads/'.$mine->id.'/cartera.xlsx'], $paths);
        $this->assertNotContains('registration-uploads/'.$other->id.'/cartera.xlsx', $paths);

        // Un upload apuntando al directorio de otro borrador invalida el plan.
        $payload = $mine->payload;
        $payload['uploads'][0]['path'] = 'registration-uploads/'.$other->id.'/cartera.xlsx';
        $mine->update(['payload' => $payload]);

        $errors = $this->service->validate($mine->refresh());
        $this->assertArrayHasKey('uploads.0.path', $errors);

        // Un archivo borrado del disco privado también invalida la activación.
        $payload = $mine->payload;
        $payload['uploads'][0]['path'] = 'registration-uploads/'.$mine->id.'/cartera.xlsx';
        $mine->update(['payload' => $payload]);
        Storage::disk('local')->delete('registration-uploads/'.$mine->id.'/cartera.xlsx');

        $errors = $this->service->validate($mine->refresh());
        $this->assertArrayHasKey('uploads.0.path', $errors);
        $this->assertNoOperationalWrites();
    }

    public function test_upload_referencing_removed_route_blocks_activation(): void
    {
        $draft = $this->attachUpload($this->draft());
        $payload = $draft->payload;
        $payload['routes'] = [[
            'id' => '99999999-9999-9999-9999-999999999999',
            'name' => 'Ruta Reemplazada',
        ]];
        $draft->update(['payload' => $payload]);

        $errors = $this->service->validate($draft->refresh());

        $this->assertArrayHasKey('uploads.0.route_id', $errors);
        $this->assertNoOperationalWrites();
    }
}
