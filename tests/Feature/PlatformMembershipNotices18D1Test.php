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
 * BLOQUE 18D-1 — avisos de renovación y vencimiento en la sección
 * «Membresía CobrOS» del Panel Maestro existente.
 *
 * Cubre: aviso de licencia inicial pendiente (sin primer pago confirmado,
 * no es licencia vencida), aviso de vencimiento tras haber operado,
 * renovación pendiente de pago y aviso de renovación próxima. Solo lectura;
 * no modifica pagos ni licencias.
 */
class PlatformMembershipNotices18D1Test extends TestCase
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
    private function company(array $contractOverrides = []): array
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pagador.'.uniqid().'@cobros.test']);
        $company = Company::create([
            'trade_name' => 'Prestamista '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $payer->id,
        ]);

        $contract = CobrosBillingContract::create(array_merge([
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
        ], $contractOverrides));

        return [$company, $payer, $contract];
    }

    /** Aprueba el SINPE del período actual y devuelve la licencia activada. */
    private function activate(CobrosBillingContract $contract, User $payer): CobrosCollectorLicense
    {
        $this->payments->requestSinpe($contract, $payer);
        $pending = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_PENDING)
            ->sole();
        $this->payments->approveSinpe($pending, $this->platformAdmin());

        return CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole();
    }

    public function test_initial_pending_license_is_distinguished_from_expired_license(): void
    {
        [$company, $payer, $contract] = $this->company();
        $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Licencia inicial pendiente')
            ->assertSee('Pendiente de pago')
            ->assertDontSee('Aviso de vencimiento');
    }

    public function test_initial_pending_shows_even_when_contract_expired_without_first_payment(): void
    {
        [$company] = $this->company(['valid_until' => now()->subDay()->toDateString()]);

        // Contrato vencido sin ningún pago: nunca hubo licencia, no es
        // «licencia vencida».
        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Licencia inicial pendiente')
            ->assertDontSee('Aviso de vencimiento');
    }

    public function test_expiration_notice_after_license_actually_expired(): void
    {
        [$company, $payer, $contract] = $this->company();
        $this->activate($contract, $payer);
        CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->update([
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Aviso de vencimiento')
            ->assertDontSee('Licencia inicial pendiente');
    }

    public function test_renewal_pending_notice_when_activated_license_lacks_current_period(): void
    {
        [$company, $payer, $contract] = $this->company();
        $this->activate($contract, $payer);

        // Período confirmado movido al pasado: la renovación queda pendiente.
        CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_CONFIRMED)
            ->sole()
            ->update([
                'period_year' => now()->subMonth()->year,
                'period_month' => now()->subMonth()->month,
            ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Renovación pendiente de pago')
            ->assertDontSee('Licencia inicial pendiente')
            ->assertDontSee('Aviso de vencimiento');
    }

    public function test_renewal_notice_when_license_expires_within_warning_window(): void
    {
        [$company, $payer, $contract] = $this->company();
        $this->activate($contract, $payer);
        $expiresOn = now()->addDays(5);
        CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->update([
            'expires_at' => $expiresOn,
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Aviso de renovación')
            ->assertSee($expiresOn->format('Y-m-d'))
            ->assertDontSee('Licencia inicial pendiente')
            ->assertDontSee('Aviso de vencimiento');
    }

    public function test_no_notices_when_membership_is_fully_current(): void
    {
        [$company, $payer, $contract] = $this->company();
        $this->activate($contract, $payer);
        CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->update([
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Vigente')
            ->assertDontSee('Licencia inicial pendiente')
            ->assertDontSee('Aviso de vencimiento')
            ->assertDontSee('Aviso de renovación')
            ->assertDontSee('Renovación pendiente de pago');
    }

    public function test_notices_are_isolated_between_companies(): void
    {
        [$companyA, $payerA, $contractA] = $this->company();
        $this->activate($contractA, $payerA);
        CobrosCollectorLicense::query()->where('user_id', $payerA->id)->sole()->update([
            'expires_at' => now()->subDay(),
        ]);

        $payerB = User::factory()->create(['is_active' => true, 'email' => 'sincontrato.'.uniqid().'@cobros.test']);
        $companyB = Company::create([
            'trade_name' => 'Empresa B '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $payerB->id,
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $companyB))
            ->assertOk()
            ->assertSee('Esta empresa no tiene un contrato de membresía CobrOS.')
            ->assertDontSee('Aviso de vencimiento')
            ->assertDontSee($payerA->email);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $companyA))
            ->assertOk()
            ->assertSee('Aviso de vencimiento')
            ->assertSee($payerA->email);
    }
}
