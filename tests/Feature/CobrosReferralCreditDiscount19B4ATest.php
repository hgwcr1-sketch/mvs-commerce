<?php

namespace Tests\Feature;

use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosReferralBonusCredit;
use App\Models\CobrosReferralCreditReservation;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\ReferralCreditDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * PRE-19B-4A — cálculo seguro del descuento por saldo a favor de referidos.
 * Solo servicio interno: sin Stripe/SINPE ni activación.
 */
class CobrosReferralCreditDiscount19B4ATest extends TestCase
{
    use RefreshDatabase;

    private ReferralCreditDiscountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReferralCreditDiscountService::class);
    }

    private function companyFor(User $owner): Company
    {
        return Company::create([
            'trade_name' => 'B4A '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $owner->id,
        ]);
    }

    private function openContract(Company $company, User $payer): mixed
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        return app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
    }

    private function fixtureConfirmation(Company $company, mixed $contract, string $amount, string $currency = 'CRC'): CobrosPaymentConfirmation
    {
        return CobrosPaymentConfirmation::create([
            'transaction_id' => 'b4a-'.Str::lower(Str::random(12)),
            'cobros_billing_contract_id' => $contract->id,
            'company_id' => $company->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'amount' => $amount,
            'currency' => $currency,
            'status' => CobrosPaymentConfirmation::STATUS_PENDING,
            'provider' => 'sinpe',
        ]);
    }

    private function bonusCredit(Company $company, User $beneficiary, mixed $contract, string $amount, string $currency = 'CRC', ?CobrosPaymentConfirmation $confirmation = null): CobrosReferralBonusCredit
    {
        $confirmation ??= $this->fixtureConfirmation($company, $contract, $amount, $currency);

        return CobrosReferralBonusCredit::create([
            'company_id' => $company->id,
            'beneficiary_user_id' => $beneficiary->id,
            'referred_user_id' => User::factory()->create(['is_active' => true])->id,
            'type' => CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT,
            'source_contract_id' => $contract->id,
            'source_confirmation_id' => $confirmation->id,
            'percent' => '10.0000',
            'amount' => $amount,
            'currency' => $currency,
            'status' => CobrosReferralBonusCredit::STATUS_CREDIT,
            'event_key' => 'b4a-'.Str::lower(Str::random(24)),
        ]);
    }

    public function test_saldo_parcial_aplica_minimo_y_conserva_total_a_pagar(): void
    {
        $plan = $this->service->calculate('10000.0000', '3000.0000', 'CRC');

        $this->assertSame('10000.0000', $plan->totalOriginal);
        $this->assertSame('3000.0000', $plan->saldoDisponible);
        $this->assertSame('3000.0000', $plan->creditoAplicable);
        $this->assertSame('7000.0000', $plan->totalAPagar);
        $this->assertSame('0.0000', $plan->saldoRemanente);
        $this->assertFalse($plan->requiereLiquidacionInterna);
    }

    public function test_saldo_total_cubre_mensualidad_con_liquidacion_interna(): void
    {
        $plan = $this->service->calculate('10000.0000', '10000.0000', 'CRC');

        $this->assertSame('10000.0000', $plan->creditoAplicable);
        $this->assertSame('0.0000', $plan->totalAPagar);
        $this->assertSame('0.0000', $plan->saldoRemanente);
        $this->assertTrue($plan->requiereLiquidacionInterna);
    }

    public function test_saldo_excedente_conserva_diferencia_y_reserva_es_idempotente(): void
    {
        // 11 bonos de ₡1.000 cubren ₡10.000 y conservan ₡1.000 (regla 5).
        $owner = User::factory()->create(['is_active' => true, 'email' => 'owner.b4a@ref.test']);
        $company = $this->companyFor($owner);
        $contract = $this->openContract($company, $owner);
        $confirmation = $this->fixtureConfirmation($company, $contract, '1000.0000');
        for ($i = 0; $i < 11; $i++) {
            $this->bonusCredit($company, $owner, $contract, '1000.0000', 'CRC', $confirmation);
        }

        $plan = $this->service->plan($contract, now()->year, now()->month);
        $this->assertSame('10000.0000', $plan->totalOriginal);
        $this->assertSame('11000.0000', $plan->saldoDisponible);
        $this->assertSame('10000.0000', $plan->creditoAplicable);
        $this->assertSame('0.0000', $plan->totalAPagar);
        $this->assertSame('1000.0000', $plan->saldoRemanente);
        $this->assertTrue($plan->requiereLiquidacionInterna);

        $first = $this->service->reserve($contract, now()->year, now()->month);
        $this->assertSame(10, $first->count());
        $this->assertTrue(bccomp('10000.0000', (string) $first->sum('amount'), 4) === 0);

        // Idempotencia: repetir la reserva no crea filas nuevas ni doble descuento.
        $second = $this->service->reserve($contract, now()->year, now()->month);
        $this->assertSame($first->count(), $second->count());
        $this->assertSame(10, CobrosReferralCreditReservation::query()->count());

        // El saldo disponible refleja la reserva; el bono sigue intacto.
        $this->assertSame('1000.0000', $this->service->availableBalance($company->id, $owner->id, 'CRC'));
        $this->assertSame(11, CobrosReferralBonusCredit::query()
            ->where('status', CobrosReferralBonusCredit::STATUS_CREDIT)->count());
    }

    public function test_factura_en_cero_prepara_liquidacion_interna_sin_pago_externo(): void
    {
        $plan = $this->service->calculate('0.0000', '5000.0000', 'CRC');

        $this->assertSame('0.0000', $plan->creditoAplicable);
        $this->assertSame('0.0000', $plan->totalAPagar);
        $this->assertTrue($plan->requiereLiquidacionInterna);

        // Sin saldo tampoco deja pago externo pendiente de ₡0.
        $empty = $this->service->calculate('0.0000', '0.0000', 'CRC');
        $this->assertSame('0.0000', $empty->totalAPagar);
        $this->assertTrue($empty->requiereLiquidacionInterna);
    }

    public function test_moneda_incorrecta_no_aplica_credito(): void
    {
        $owner = User::factory()->create(['is_active' => true, 'email' => 'owner.usd@ref.test']);
        $company = $this->companyFor($owner);
        $contract = $this->openContract($company, $owner);
        $this->bonusCredit($company, $owner, $contract, '50000.0000', 'USD');

        // Bono en USD jamás cubre mensualidad CRC.
        $this->assertSame('0.0000', $this->service->availableBalance($company->id, $owner->id, 'CRC'));
        $plan = $this->service->plan($contract, now()->year, now()->month);
        $this->assertSame('0.0000', $plan->creditoAplicable);
        $this->assertSame('10000.0000', $plan->totalAPagar);
        $this->assertSame(0, $this->service->reserve($contract, now()->year, now()->month)->count());
    }

    public function test_saldo_de_usuario_ajeno_nunca_se_aplica(): void
    {
        $owner = User::factory()->create(['is_active' => true, 'email' => 'owner.aj@ref.test']);
        $other = User::factory()->create(['is_active' => true, 'email' => 'otro.aj@ref.test']);
        $company = $this->companyFor($owner);
        $contract = $this->openContract($company, $owner);
        $this->bonusCredit($company, $other, $contract, '20000.0000');

        $this->assertSame('0.0000', $this->service->availableBalance($company->id, $owner->id, 'CRC'));
        $plan = $this->service->plan($contract, now()->year, now()->month);
        $this->assertSame('0.0000', $plan->creditoAplicable);
        $this->assertSame('10000.0000', $plan->totalAPagar);
        $this->assertSame(0, $this->service->reserve($contract, now()->year, now()->month)->count());
    }

    public function test_periodo_invalido_es_rechazado(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $company = $this->companyFor($owner);
        $contract = $this->openContract($company, $owner);

        $this->expectException(ValidationException::class);
        $this->service->plan($contract, now()->year, 13);
    }
}
