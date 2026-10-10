<?php

namespace Tests\Feature;

use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosReferralBonusCredit;
use App\Models\CobrosReferralCreditReservation;
use App\Models\CobrosRegistrationDraft;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Contracts\PaymentEventTypes;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmedHandler;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\ReferralCreditDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRE-19B-4B — integración del saldo a favor con MVS Billing, sin cargos
 * reales ni red. Cubre parcial, total, excedente, doble confirmación y
 * fallo de pago con el mismo server-side que Checkout/SINPE/webhook.
 */
class CobrosReferralCreditIntegration19B4BTest extends TestCase
{
    use RefreshDatabase;

    private ReferralCreditDiscountService $credits;

    private CobrosPaymentConfirmationService $confirmations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credits = app(ReferralCreditDiscountService::class);
        $this->confirmations = app(CobrosPaymentConfirmationService::class);
    }

    private function companyFor(User $owner): Company
    {
        return Company::create([
            'trade_name' => 'B4B '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $owner->id,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    private function openContract(Company $company, User $payer): mixed
    {
        return app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $this->admin(),
        );
    }

    private function seedCredits(Company $company, User $beneficiary, mixed $contract, int $count, string $amount): void
    {
        // Fixture de confirmación en un período ANTERIOR: no debe interferir
        // con la unicidad de solicitudes del período en curso.
        $previous = now()->subMonthNoOverflow();
        $confirmation = CobrosPaymentConfirmation::create([
            'transaction_id' => 'b4b-'.Str::lower(Str::random(12)),
            'cobros_billing_contract_id' => $contract->id,
            'company_id' => $company->id,
            'period_year' => $previous->year,
            'period_month' => $previous->month,
            'amount' => $amount,
            'currency' => 'CRC',
            'status' => CobrosPaymentConfirmation::STATUS_PENDING,
            'provider' => 'sinpe',
        ]);

        for ($i = 0; $i < $count; $i++) {
            CobrosReferralBonusCredit::create([
                'company_id' => $company->id,
                'beneficiary_user_id' => $beneficiary->id,
                'referred_user_id' => User::factory()->create(['is_active' => true])->id,
                'type' => CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT,
                'source_contract_id' => $contract->id,
                'source_confirmation_id' => $confirmation->id,
                'percent' => '10.0000',
                'amount' => $amount,
                'currency' => 'CRC',
                'status' => CobrosReferralBonusCredit::STATUS_CREDIT,
                'event_key' => 'b4b-'.Str::lower(Str::random(24)),
            ]);
        }
    }

    public function test_saldo_parcial_sinpe_usa_importe_neto_y_consume_reserva_al_confirmar(): void
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.par@b4b.test']);
        $company = $this->companyFor($payer);
        $contract = $this->openContract($company, $payer);
        $this->seedCredits($company, $payer, $contract, 3, '1000.0000');

        $this->credits->reserve($contract, now()->year, now()->month);
        $pending = $this->confirmations->requestSinpe($contract, $payer);

        // SINPE solicita exactamente el neto (10.000 − 3.000 = 7.000).
        $this->assertSame('7000.0000', (string) $pending->amount);

        $confirmed = $this->confirmations->approveSinpe($pending, $this->admin());
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame('7000.0000', (string) $confirmed->amount);

        // Reserva consumida una sola vez.
        $this->assertSame(3, CobrosReferralCreditReservation::query()
            ->where('status', CobrosReferralCreditReservation::STATUS_SETTLED)->count());
        $this->assertSame('0.0000', $this->credits->availableBalance($company->id, $payer->id, 'CRC'));
    }

    public function test_saldo_total_liquida_internamente_sin_cargo_externo_e_idempotente(): void
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.tot@b4b.test']);
        $company = $this->companyFor($payer);
        $contract = $this->openContract($company, $payer);
        $this->seedCredits($company, $payer, $contract, 10, '1000.0000');

        $this->credits->reserve($contract, now()->year, now()->month);
        $this->assertSame('0.0000', $this->credits->netAmountForPeriod($contract, now()->year, now()->month));

        $liquidation = $this->confirmations->liquidateCreditInternal($contract, $payer, now()->year, now()->month);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $liquidation->status);
        $this->assertSame('0.0000', (string) $liquidation->amount);
        $this->assertSame('credit', $liquidation->provider);

        // Idempotente: repetir devuelve la misma confirmación y no duplica consumo.
        $again = $this->confirmations->liquidateCreditInternal($contract, $payer, now()->year, now()->month);
        $this->assertSame($liquidation->id, $again->id);
        $this->assertSame(10, CobrosReferralCreditReservation::query()
            ->where('status', CobrosReferralCreditReservation::STATUS_SETTLED)->count());
    }

    public function test_saldo_excedente_conserva_remanente_tras_liquidacion(): void
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.exc@b4b.test']);
        $company = $this->companyFor($payer);
        $contract = $this->openContract($company, $payer);
        $this->seedCredits($company, $payer, $contract, 11, '1000.0000');

        $plan = $this->credits->plan($contract, now()->year, now()->month);
        $this->assertSame('1000.0000', $plan->saldoRemanente);

        $this->credits->reserve($contract, now()->year, now()->month);
        $this->confirmations->liquidateCreditInternal($contract, $payer, now()->year, now()->month);

        // ₡1.000 de remanente sigue disponible tras la liquidación.
        $this->assertSame('1000.0000', $this->credits->availableBalance($company->id, $payer->id, 'CRC'));
        $this->assertSame(11, CobrosReferralBonusCredit::query()
            ->where('status', CobrosReferralBonusCredit::STATUS_CREDIT)->count());
    }

    public function test_doble_confirmacion_no_duplica_consumo_de_reserva(): void
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.dob@b4b.test']);
        $company = $this->companyFor($payer);
        $contract = $this->openContract($company, $payer);
        $this->seedCredits($company, $payer, $contract, 3, '1000.0000');

        $this->credits->reserve($contract, now()->year, now()->month);
        $first = $this->credits->settleReservations($contract, now()->year, now()->month);
        $second = $this->credits->settleReservations($contract, now()->year, now()->month);

        $this->assertSame(3, $first);
        $this->assertSame(0, $second);
        $this->assertSame(3, CobrosReferralCreditReservation::query()
            ->where('status', CobrosReferralCreditReservation::STATUS_SETTLED)->count());
    }

    public function test_fallo_de_pago_conserva_reserva_y_saldo_disponible(): void
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.fal@b4b.test']);
        $company = $this->companyFor($payer);
        $contract = $this->openContract($company, $payer);
        $this->seedCredits($company, $payer, $contract, 3, '1000.0000');

        $this->credits->reserve($contract, now()->year, now()->month);
        $pending = $this->confirmations->requestSinpe($contract, $payer);
        $this->confirmations->rejectSinpe($pending, $this->admin(), 'Monto no coincide.');

        // Ante fallo: la reserva sigue activa, el saldo no se pierde y
        // ninguna licencia se activó por este pago.
        $this->assertSame(3, CobrosReferralCreditReservation::query()
            ->where('status', CobrosReferralCreditReservation::STATUS_RESERVED)->count());
        $this->assertSame('0.0000', $this->credits->availableBalance($company->id, $payer->id, 'CRC'));
        $this->assertSame(0, CobrosReferralCreditReservation::query()
            ->where('status', CobrosReferralCreditReservation::STATUS_SETTLED)->count());
    }

    public function test_webhook_stripe_valida_importe_neto_y_no_difiere_de_checkout(): void
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.whk@b4b.test']);
        $company = $this->companyFor($payer);
        $contract = $this->openContract($company, $payer);
        $this->seedCredits($company, $payer, $contract, 3, '1000.0000');

        $draft = CobrosRegistrationDraft::create([
            'email' => 'whk.'.uniqid().'@b4b.test',
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'prestamista',
                'personal' => ['name' => 'WH', 'email' => 'whk.'.uniqid().'@b4b.test', 'phone' => '8888-8888'],
                'portfolio_plan' => 'manual',
                'routes' => [['id' => (string) Str::uuid(), 'name' => 'R', 'code' => 'RW']],
            ],
            'last_saved_at' => now(),
        ]);
        $draft->update(['billing_contract_id' => $contract->id]);

        $this->credits->reserve($contract, now()->year, now()->month);

        $event = new PaymentEvent(
            type: PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: 'pi_b4b_'.uniqid(),
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            currency: 'CRC',
            amount: '7000.0000',
            periodYear: now()->year,
            periodMonth: now()->month,
        );

        // Importe NETO (igual que Checkout/SINPE): el webhook lo acepta.
        app(CobrosPaymentConfirmedHandler::class)->handle($event);
        $confirmation = CobrosPaymentConfirmation::query()
            ->where('transaction_id', $event->externalId)->first();
        $this->assertNotNull($confirmation);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $confirmation->status);
        $this->assertSame(3, CobrosReferralCreditReservation::query()
            ->where('status', CobrosReferralCreditReservation::STATUS_SETTLED)->count());

        // Importe BRUTO con saldo reservado: el webhook lo ignora (sin
        // diferencia entre Checkout, confirmación y webhook).
        $gross = new PaymentEvent(
            type: PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: 'pi_b4b_gross_'.uniqid(),
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            currency: 'CRC',
            amount: '10000.0000',
            periodYear: now()->year,
            periodMonth: now()->month,
        );
        app(CobrosPaymentConfirmedHandler::class)->handle($gross);
        $this->assertNull(CobrosPaymentConfirmation::query()
            ->where('transaction_id', $gross->externalId)->first());
    }
}
