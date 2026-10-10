<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CobrosMembershipLifecycleService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BLOQUE 17B-6A — renovaciones y vencimientos (solo servicios internos).
 *
 * Cubre: estados de membresía (vigente/próxima a vencer/vencida/pendiente de
 * pago), renovación solo con pago legítimo confirmado, idempotencia por
 * contrato/período sin duplicar licencias consolidadas, visibilidad solo del
 * pagador y bloqueo genérico sin datos financieros.
 */
class CobrosMembershipLifecycle17B6ATest extends TestCase
{
    use RefreshDatabase;

    private CobrosPaymentConfirmationService $payments;

    private CobrosMembershipLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = app(CobrosPaymentConfirmationService::class);
        $this->lifecycle = app(CobrosMembershipLifecycleService::class);
    }

    /** @return array{0: Company, 1: User, 2: CobrosBillingContract} Empresa + pagador + contrato individual. */
    private function company(string $modality = 'prestamista'): array
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
            'modality' => $modality,
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

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    /** Pago SINPE aprobado legítimamente por Maestro (estado confirmed). */
    private function approvedSinpe(CobrosBillingContract $contract, User $payer): CobrosPaymentConfirmation
    {
        $pending = $this->payments->requestSinpe($contract, $payer);

        return $this->payments->approveSinpe($pending, $this->platformAdmin());
    }

    public function test_membership_states_pending_vigente_expiring_and_expired(): void
    {
        [, $payer, $contract] = $this->company();

        // Sin pagos: pendiente de pago (sin solicitud).
        $state = $this->lifecycle->evaluate($contract);
        $this->assertSame(CobrosMembershipLifecycleService::STATUS_PENDIENTE_DE_PAGO, $state['status']);
        $this->assertFalse($state['has_pending_request']);
        // Nunca expone montos: solo estos campos.
        $this->assertSame(['status', 'expires_on', 'has_pending_request'], array_keys($state));

        // Con solicitud SINPE pendiente.
        $this->payments->requestSinpe($contract, $payer);
        $this->assertTrue($this->lifecycle->evaluate($contract)['has_pending_request']);

        // Pago aprobado: vigente (vigencia de licencia forzada fuera de la ventana).
        $this->approvedSinpe($contract, $payer);
        $license = CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole();
        $license->update(['expires_at' => now()->addDays(30)]);
        $state = $this->lifecycle->evaluate($contract);
        $this->assertSame(CobrosMembershipLifecycleService::STATUS_VIGENTE, $state['status']);
        $this->assertNotNull($state['expires_on']);

        // Próxima a vencer: dentro de la ventana de 7 días.
        $license->update(['expires_at' => now()->addDays(3)]);
        $state = $this->lifecycle->evaluate($contract);
        $this->assertSame(CobrosMembershipLifecycleService::STATUS_PROXIMA_A_VENCER, $state['status']);

        // Vencida: licencia vencida.
        $license->update(['expires_at' => now()->subDay()]);
        $this->assertSame(
            CobrosMembershipLifecycleService::STATUS_VENCIDA,
            $this->lifecycle->evaluate($contract)['status']
        );

        // Vencida: fuera de vigencia contractual.
        $contract->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->assertSame(
            CobrosMembershipLifecycleService::STATUS_VENCIDA,
            $this->lifecycle->evaluate($contract)['status']
        );
    }

    public function test_renewal_requires_legitimate_confirmed_payment(): void
    {
        [, $payer, $contract] = $this->company();

        // Solicitud pendiente: no renueva.
        $pending = $this->payments->requestSinpe($contract, $payer);
        try {
            $this->payments->applyRenewal($pending);
            $this->fail('Se esperaba ValidationException para pago no confirmado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Confirmación interna (no Stripe webhook ni SINPE Maestro): no renueva.
        $next = now()->addMonthNoOverflow();
        $internal = CobrosPaymentConfirmation::create([
            'transaction_id' => 'COB-'.uniqid(),
            'cobros_billing_contract_id' => $contract->id,
            'company_id' => $contract->company_id,
            'period_year' => $next->year,
            'period_month' => $next->month,
            'amount' => '10000.0000',
            'currency' => 'CRC',
            'status' => CobrosPaymentConfirmation::STATUS_CONFIRMED,
            'provider' => 'internal',
            'confirmed_at' => now(),
        ]);

        try {
            $this->payments->applyRenewal($internal);
            $this->fail('Se esperaba ValidationException para proveedor no legítimo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('provider', $e->errors());
        }

        // El pago SINPE aprobado sí renueva.
        $approved = $this->approvedSinpe($contract, $payer);
        $renewed = $this->payments->applyRenewal($approved);
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $renewed->status);
    }

    public function test_renewal_is_idempotent_per_contract_period(): void
    {
        [$company, $payer, $contract] = $this->company();
        $approved = $this->approvedSinpe($contract, $payer);

        $first = $this->payments->applyRenewal($approved);
        $licenseAfterFirst = CobrosCollectorLicense::query()->where('company_id', $company->id)->sole();
        $second = $this->payments->applyRenewal($approved);
        $licenseAfterSecond = CobrosCollectorLicense::query()->where('company_id', $company->id)->sole();

        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $first->status);
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $second->status);
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(
            $licenseAfterFirst->expires_at?->getTimestamp(),
            $licenseAfterSecond->expires_at?->getTimestamp()
        );
        $this->assertSame(CobrosCollectorLicense::STATUS_ACTIVE, $licenseAfterSecond->status);
    }

    public function test_prestamista_cobrador_consolidated_license_is_not_duplicated(): void
    {
        [$company, $payer, $contract] = $this->company('prestamista_cobrador');

        $approved = $this->approvedSinpe($contract, $payer);
        $this->payments->applyRenewal($approved);
        $this->payments->applyRenewal($approved);

        // Tarifa combinada: UNA sola licencia consolidada, sin filas ni
        // confirmaciones duplicadas (sin cobros automáticos en este bloque).
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, CobrosPaymentConfirmation::query()->where('cobros_billing_contract_id', $contract->id)->count());
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);
    }

    public function test_membership_is_only_visible_to_payer_and_block_is_generic(): void
    {
        [$company, $payer, $contract] = $this->company();
        $collector = User::factory()->create(['is_active' => true]);
        $approved = $this->approvedSinpe($contract, $payer);

        // El pagador también opera cobros (prestamista-cobrador): su licencia individual aplica.
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'cobros.cobrar', 'module' => 'Test', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $payer->companies()->attach($company->id, ['role_id' => $role->id]);

        // r7: solo el pagador ve membresía/precios/vencimientos.
        $this->assertTrue($this->lifecycle->canViewMembership($payer, $contract));
        $this->assertFalse($this->lifecycle->canViewMembership($collector, $contract));

        // Sin vencimiento: sin bloqueo.
        $this->assertNull($this->lifecycle->blockingStateFor($payer, $company));

        // Vencida: bloqueo con aviso genérico, sin datos financieros.
        CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->update([
            'expires_at' => now()->subDay(),
        ]);
        $block = $this->lifecycle->blockingStateFor($payer, $company);
        $this->assertNotNull($block);
        $this->assertTrue($block['blocked']);
        $this->assertSame('expired', $block['reason']);
        $this->assertSame(0, preg_match('/\d/', $block['message']));
        $this->assertStringNotContainsString('CRC', $block['message']);
        $this->assertStringNotContainsString('10000', $block['message']);
    }
}
