<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 18B-1 — solicitudes SINPE pendientes en el Panel Maestro existente.
 *
 * Cubre: sección visible solo para platform admin, datos de la solicitud
 * (fecha, referencia, pagador, contrato, importe, moneda, estado), estado
 * vacío y aislamiento estricto entre empresas.
 */
class PlatformSinpePending18B1Test extends TestCase
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

    public function test_platform_admin_sees_pending_sinpe_section_with_details(): void
    {
        [$company, $payer, $contract] = $this->company();
        $pending = $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Pagos CobrOS')
            ->assertSee('Pendientes SINPE')
            ->assertSee($pending->transaction_id, false)
            ->assertSee($payer->name)
            ->assertSee(number_format((float) $pending->amount, 2))
            ->assertSee('CRC')
            ->assertSee('Pendiente')
            ->assertSee('Aprobar')
            ->assertSee('Rechazar');
    }

    public function test_empty_state_is_shown_when_there_are_no_requests(): void
    {
        [$company] = $this->company();

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Pagos CobrOS')
            ->assertSee('No hay solicitudes SINPE pendientes para esta empresa.');
    }

    public function test_pending_sinpe_requests_are_isolated_between_companies(): void
    {
        [$companyA, $payerA, $contractA] = $this->company();
        [$companyB] = $this->company();
        $pendingA = $this->payments->requestSinpe($contractA, $payerA);

        // Empresa B: no ve las solicitudes de la empresa A.
        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $companyB))
            ->assertOk()
            ->assertSee('No hay solicitudes SINPE pendientes para esta empresa.')
            ->assertDontSee($pendingA->transaction_id, false);

        // Empresa A: solo ve las suyas.
        $this->assertSame(1, CobrosPaymentConfirmation::sinpePending($companyA->id)->count());
        $this->assertSame(0, CobrosPaymentConfirmation::sinpePending($companyB->id)->count());
    }

    public function test_non_platform_admin_cannot_see_the_section(): void
    {
        [$company, $payer, $contract] = $this->company();
        $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($payer)
            ->get(route('platform.companies.show', $company))
            ->assertForbidden();
    }
}
