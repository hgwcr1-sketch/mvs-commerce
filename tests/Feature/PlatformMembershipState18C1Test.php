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
 * BLOQUE 18C-1 — estado de membresías CobrOS en el Panel Maestro existente.
 *
 * Cubre: sección con estados (vigente/próxima a vencer/vencida/pendiente de
 * pago) basada en contrato + licencias, estado vacío sin contrato y
 * aislamiento estricto entre empresas. Solo lectura para platform admin.
 */
class PlatformMembershipState18C1Test extends TestCase
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

    public function test_membership_state_is_shown_with_payer_and_dates(): void
    {
        [$company, $payer, $contract] = $this->company();

        // Solicitud SINPE pendiente: estado pendiente de pago con aviso "Sí".
        $this->payments->requestSinpe($contract, $payer);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Membresía CobrOS')
            ->assertSee('Pendiente de pago')
            ->assertSee($payer->name)
            ->assertSee('#'.$contract->id)
            ->assertSee('Sí'); // solicitud de pago pendiente

        // Pago aprobado + licencia vigente lejana: vigente.
        $pendingRow = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_PENDING)
            ->sole();
        $this->payments->approveSinpe($pendingRow, $this->platformAdmin());
        CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->update([
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Vigente')
            ->assertSee(now()->addDays(30)->format('Y-m-d'));

        // Licencia vencida con contrato aún vigente: vencida (no se infiere
        // únicamente de la fecha del contrato).
        CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->update([
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Vencida');
    }

    public function test_empty_state_when_there_is_no_contract(): void
    {
        $payer = User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'trade_name' => 'Sin contrato '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $payer->id,
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Membresía CobrOS')
            ->assertSee('Esta empresa no tiene un contrato de membresía CobrOS.');
    }

    public function test_membership_state_is_isolated_between_companies(): void
    {
        [$companyA, $payerA] = $this->company();
        $payerB = User::factory()->create(['is_active' => true, 'email' => 'sincontrato.'.uniqid().'@cobros.test']);
        $companyB = Company::create([
            'trade_name' => 'Empresa B '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $payerB->id,
        ]);

        // Empresa B: no ve la membresía ni el pagador de la empresa A.
        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $companyB))
            ->assertOk()
            ->assertSee('Esta empresa no tiene un contrato de membresía CobrOS.')
            ->assertDontSee($payerA->email);

        // Empresa A: sí ve la suya.
        $this->actingAs($this->platformAdmin())
            ->get(route('platform.companies.show', $companyA))
            ->assertOk()
            ->assertSee($payerA->email)
            ->assertSee('Pendiente de pago');
    }
}
