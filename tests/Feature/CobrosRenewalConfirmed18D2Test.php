<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosRegistrationDraft;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Contracts\PaymentEventTypes;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Cobros\CobrosPaymentConfirmedHandler;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 18D-2 — renovación mensual tras pagos confirmados legítimamente.
 *
 * Cubre: Stripe (webhook verificado) y SINPE (aprobado por Maestro) renuevan
 * la licencia del período; una confirmación/aprobación repetida NO extiende
 * nuevamente la vigencia ni duplica períodos o licencias; applyRenewal tras
 * la activación de activateContract() no es una segunda renovación. Sin
 * cambios de precios, cargos ni suscripciones.
 */
class CobrosRenewalConfirmed18D2Test extends TestCase
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

    private function periodEnd(): string
    {
        return now()->startOfMonth()->endOfMonth()->toDateTimeString();
    }

    public function test_stripe_confirmed_payment_renews_license_and_replay_does_not_extend_twice(): void
    {
        [$company, $payer, $contract] = $this->company();

        // Borrador vinculado: el handler exige asociación verificable.
        CobrosRegistrationDraft::create([
            'email' => $payer->email,
            'payload' => ['country_iso' => 'CR', 'modality' => 'prestamista'],
            'billing_contract_id' => $contract->id,
            'last_saved_at' => now(),
        ]);

        $event = new PaymentEvent(
            type: PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: 'pi_18d2_renovacion',
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            currency: 'CRC',
            amount: '10000.0000',
            periodYear: now()->year,
            periodMonth: now()->month,
        );

        $handler = app(CobrosPaymentConfirmedHandler::class);
        $handler->handle($event);

        $license = CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole();
        $this->assertSame($this->periodEnd(), $license->expires_at?->toDateTimeString());
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, CobrosPaymentConfirmation::query()->sole()->status);
        $this->assertSame('stripe', CobrosPaymentConfirmation::query()->sole()->provider);

        // Reintento del webhook (mismo payment_intent.id): sin nueva extensión.
        $handler->handle($event);

        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(
            $this->periodEnd(),
            CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->expires_at?->toDateTimeString(),
            'La confirmación repetida no extiende nuevamente la vigencia.'
        );
    }

    public function test_sinpe_approval_renews_license_and_double_approval_does_not_extend_again(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();

        $this->payments->requestSinpe($contract, $payer);
        $pending = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_PENDING)
            ->sole();

        $first = $this->payments->approveSinpe($pending, $admin);
        $license = CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole();
        $this->assertSame($this->periodEnd(), $license->expires_at?->toDateTimeString());
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);

        // Doble aprobación: idempotente, sin segunda renovación.
        $second = $this->payments->approveSinpe($first->fresh(), $admin);

        $this->assertSame($first->confirmed_at?->getTimestamp(), $second->confirmed_at?->getTimestamp());
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(
            $this->periodEnd(),
            CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->expires_at?->toDateTimeString(),
            'La doble aprobación no extiende nuevamente la vigencia.'
        );
    }

    public function test_repeated_confirm_with_same_transaction_id_keeps_single_period_and_expiry(): void
    {
        [$company, $payer, $contract] = $this->company();

        $this->payments->confirm(
            $contract, $payer, 'pi_18d2_misma_transaccion', '10000.0000',
            now()->year, now()->month, 'stripe',
        );
        $firstExpiry = CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->expires_at?->toDateTimeString();

        // Reintento del mismo transaction_id: no duplica fila ni extiende.
        $this->payments->confirm(
            $contract, $payer, 'pi_18d2_misma_transaccion', '10000.0000',
            now()->year, now()->month, 'stripe',
        );

        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame($firstExpiry, CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->expires_at?->toDateTimeString());
        $this->assertSame($this->periodEnd(), $firstExpiry);
    }

    public function test_apply_renewal_after_activation_is_not_a_second_renewal(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();

        $this->payments->requestSinpe($contract, $payer);
        $pending = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_PENDING)
            ->sole();
        $approved = $this->payments->approveSinpe($pending, $admin);

        $afterActivation = CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->expires_at?->toDateTimeString();

        // applyRenewal reutiliza activateContract() con el MISMO período:
        // actualiza la licencia existente, no una segunda extensión.
        $this->payments->applyRenewal($approved->fresh());

        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(
            $afterActivation,
            CobrosCollectorLicense::query()->where('user_id', $payer->id)->sole()->expires_at?->toDateTimeString(),
            'applyRenewal tras activateContract no duplica la renovación.'
        );
    }

    public function test_renewal_requires_confirmed_payment_and_stripe_or_sinpe_provider(): void
    {
        [$company, $payer, $contract] = $this->company();

        $this->payments->requestSinpe($contract, $payer);
        $pending = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_PENDING)
            ->sole();

        try {
            $this->payments->applyRenewal($pending);
            $this->fail('Una confirmación pendiente no puede renovar.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }
}
