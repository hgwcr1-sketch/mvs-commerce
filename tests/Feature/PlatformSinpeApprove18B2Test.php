<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 18B-2 — aprobar/rechazar SINPE desde el Panel Maestro existente.
 *
 * Cubre: aprobación válida con verificación explícita y activación sin
 * duplicados, motivo obligatorio de rechazo conservando el registro, solicitud
 * ajena (404), usuario sin autorización Maestro (403) y doble envío idempotente.
 */
class PlatformSinpeApprove18B2Test extends TestCase
{
    use RefreshDatabase;

    private CobrosPaymentConfirmationService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = app(CobrosPaymentConfirmationService::class);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
    }

    /** @return array{0: Company, 1: User, 2: CobrosBillingContract} */
    private function company(): array
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pagador.'.uniqid().'@cobros.test']);
        $company = Company::create([
            'trade_name' => 'Prestamista '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $payer->id,
        ]);

        $contract = CobrosBillingContract::create([
            'company_id' => $company->id,
            'payer_user_id' => $payer->id,
            'billing_mode' => CobrosBillingContract::MODE_INDIVIDUAL,
            'modality' => 'prestamista',
            'country_iso' => 'CR',
            'currency' => 'CRC',
            'status' => 'draft',
            'seats_total' => 1,
            'monthly_amount' => '10000.0000',
            'admin_included' => false,
            'valid_from' => now()->startOfMonth()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);

        return [$company, $payer, $contract];
    }

    public function test_valid_approval_activates_license_with_audit(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();
        $pending = $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($admin)
            ->from(route('platform.companies.show', $company))
            ->post(route('platform.sinpe.approve', [$company, $pending]), ['verified_transfer' => '1'])
            ->assertRedirect(route('platform.companies.show', $company))
            ->assertSessionHas('success');

        $confirmation = $pending->fresh();
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $confirmation->status);
        $this->assertSame($admin->id, $confirmation->confirmed_by_user_id);
        $this->assertNotNull($confirmation->confirmed_at);
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    public function test_approval_requires_explicit_verification_checkbox(): void
    {
        [$company, $payer, $contract] = $this->company();
        $pending = $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($this->platformAdmin())
            ->from(route('platform.companies.show', $company))
            ->post(route('platform.sinpe.approve', [$company, $pending]), [])
            ->assertRedirect(route('platform.companies.show', $company))
            ->assertSessionHasErrors('verified_transfer');

        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    public function test_rejection_requires_motive_and_keeps_record_without_activation(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();
        $pending = $this->payments->requestSinpe($contract, $payer);

        // Sin motivo: rechazado en servidor.
        $this->actingAs($admin)
            ->from(route('platform.companies.show', $company))
            ->post(route('platform.sinpe.reject', [$company, $pending]), [])
            ->assertSessionHasErrors('notes');
        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pending->fresh()->status);

        // Con motivo: rechazo auditable, registro conservado, sin activación.
        $this->actingAs($admin)
            ->from(route('platform.companies.show', $company))
            ->post(route('platform.sinpe.reject', [$company, $pending]), ['notes' => 'No coincide con la referencia.'])
            ->assertRedirect(route('platform.companies.show', $company))
            ->assertSessionHas('success');

        $rejected = $pending->fresh();
        $this->assertSame(CobrosPaymentConfirmation::STATUS_REJECTED, $rejected->status);
        $this->assertSame($admin->id, $rejected->confirmed_by_user_id);
        $this->assertStringContainsString('No coincide', (string) $rejected->notes);
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame('draft', $contract->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    public function test_foreign_request_is_not_found(): void
    {
        [$companyA] = $this->company();
        [$companyB, $payerB, $contractB] = $this->company();
        $foreign = $this->payments->requestSinpe($contractB, $payerB);

        $this->actingAs($this->platformAdmin())
            ->from(route('platform.companies.show', $companyA))
            ->post(route('platform.sinpe.approve', [$companyA, $foreign]), ['verified_transfer' => '1'])
            ->assertNotFound();

        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $foreign->fresh()->status);
    }

    public function test_non_platform_admin_is_forbidden(): void
    {
        [$company, $payer, $contract] = $this->company();
        $pending = $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($payer)
            ->post(route('platform.sinpe.approve', [$company, $pending]), ['verified_transfer' => '1'])
            ->assertForbidden();

        $this->actingAs($payer)
            ->post(route('platform.sinpe.reject', [$company, $pending]), ['notes' => 'Intento no autorizado.'])
            ->assertForbidden();

        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_double_submission_does_not_duplicate_licenses(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();
        $pending = $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($admin)
            ->from(route('platform.companies.show', $company))
            ->post(route('platform.sinpe.approve', [$company, $pending]), ['verified_transfer' => '1'])
            ->assertSessionHas('success');

        // Doble envío de aprobación: idempotente, sin duplicar licencias.
        $this->actingAs($admin)
            ->from(route('platform.companies.show', $company))
            ->post(route('platform.sinpe.approve', [$company, $pending]), ['verified_transfer' => '1'])
            ->assertRedirect(route('platform.companies.show', $company));

        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);

        // Rechazo posterior a una aprobación: error controlado, estado intacto.
        $this->actingAs($admin)
            ->from(route('platform.companies.show', $company))
            ->post(route('platform.sinpe.reject', [$company, $pending]), ['notes' => 'Duplicado.'])
            ->assertSessionHasErrors('status');
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $pending->fresh()->status);
    }
}
