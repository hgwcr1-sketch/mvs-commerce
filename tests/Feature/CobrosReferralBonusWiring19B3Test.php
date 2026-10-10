<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosReferralAttribution;
use App\Models\CobrosReferralBonusCredit;
use App\Models\CobrosRegistrationDraft;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\CobrosPaymentConfirmedHandler;
use App\Services\Cobros\ReferralAttributionService;
use App\Services\Cobros\ReferralBonusService;
use App\Services\Cobros\ReferralCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRE-19B-3 — emisión idempotente de bonificaciones conectada al primer pago
 * confirmado. Focal: Stripe confirmado, SINPE aprobado, pendiente, repetición
 * e incorporación laboral. Sin descuentos, desembolsos ni facturación neta.
 */
class CobrosReferralBonusWiring19B3Test extends TestCase
{
    use RefreshDatabase;

    private ReferralCodeService $codes;

    private ReferralAttributionService $attributions;

    private ReferralBonusService $bonuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codes = app(ReferralCodeService::class);
        $this->attributions = app(ReferralAttributionService::class);
        $this->bonuses = app(ReferralBonusService::class);
    }

    private function companyFor(User $owner): Company
    {
        return Company::create([
            'trade_name' => 'WIRE '.uniqid(),
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

    private function confirmedOnContract(CobrosBillingContract $contract, User $payer, User $admin): CobrosPaymentConfirmation
    {
        $pending = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $payer);

        return app(CobrosPaymentConfirmationService::class)->approveSinpe($pending, $admin);
    }

    private function confirmedSinpe(Company $company, User $payer, User $admin): CobrosPaymentConfirmation
    {
        $contract = $this->openContract($company, $payer, $admin);

        return $this->confirmedOnContract($contract, $payer, $admin);
    }

    private function openContract(Company $company, User $payer, User $admin): CobrosBillingContract
    {
        return app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
    }

    public function test_sinpe_approval_emits_bonus_for_attributed_referrer_and_team_owner(): void
    {
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'ref.sinpe@ref.test']);
        $titular = User::factory()->create(['is_active' => true, 'email' => 'titular.sinpe@ref.test']);
        $company = $this->companyFor($titular);
        $collaborator = User::factory()->create(['is_active' => true, 'email' => 'colab.sinpe@ref.test']);
        $company->users()->attach($collaborator->id);
        $this->attributions->attribute($collaborator, $this->codes->codeFor($referrer), $company);

        $confirmed = $this->confirmedSinpe($company, $collaborator, $this->admin());

        $credit = CobrosReferralBonusCredit::query()
            ->where('source_confirmation_id', $confirmed->id)
            ->where('type', CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT)
            ->sole();
        $this->assertSame($referrer->id, $credit->beneficiary_user_id);
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $credit->status);
        $this->assertSame('1000.0000', (string) $credit->amount);
    }

    public function test_pending_sinpe_request_emits_no_bonus(): void
    {
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'ref.pend@ref.test']);
        $titular = User::factory()->create(['is_active' => true]);
        $company = $this->companyFor($titular);
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.pend@ref.test']);
        $company->users()->attach($payer->id);
        $this->attributions->attribute($payer, $this->codes->codeFor($referrer), $company);

        $contract = app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $this->admin(),
        );
        $pending = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $payer);

        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pending->status);
        $this->assertSame(0, CobrosReferralBonusCredit::query()->count());
    }

    public function test_double_sinpe_approval_emits_bonus_exactly_once(): void
    {
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'ref.doble@ref.test']);
        $titular = User::factory()->create(['is_active' => true]);
        $company = $this->companyFor($titular);
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.doble@ref.test']);
        $company->users()->attach($payer->id);
        $this->attributions->attribute($payer, $this->codes->codeFor($referrer), $company);

        $confirmed = $this->confirmedSinpe($company, $payer, $this->admin());
        app(CobrosPaymentConfirmationService::class)->approveSinpe($confirmed->fresh(), $this->admin());

        $this->assertSame(1, CobrosReferralBonusCredit::query()
            ->where('type', CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT)->count());
    }

    public function test_stripe_confirmed_webhook_emits_bonus_for_attributed_referrer(): void
    {
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'ref.stripe@ref.test']);
        $titular = User::factory()->create(['is_active' => true]);
        $company = $this->companyFor($titular);
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.stripe@ref.test']);
        $company->users()->attach($payer->id);
        $this->attributions->attribute($payer, $this->codes->codeFor($referrer), $company);

        $contract = app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $this->admin(),
        );

        $draft = CobrosRegistrationDraft::create([
            'email' => 'stripe.'.uniqid().'@ref.test',
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'prestamista',
                'personal' => ['name' => 'Stripe Ref', 'email' => 'stripe.'.uniqid().'@ref.test', 'phone' => '8888-8888'],
                'portfolio_plan' => 'manual',
                'routes' => [['id' => (string) Str::uuid(), 'name' => 'Ruta', 'code' => 'RS']],
            ],
            'last_saved_at' => now(),
        ]);
        $draft->update(['billing_contract_id' => $contract->id]);

        $event = new \App\Services\Billing\DTOs\PaymentEvent(
            type: \App\Services\Billing\Contracts\PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: 'pi_wire_'.uniqid(),
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            currency: 'CRC',
            amount: '10000.0000',
            periodYear: now()->year,
            periodMonth: now()->month,
        );

        app(CobrosPaymentConfirmedHandler::class)->handle($event);

        $credit = CobrosReferralBonusCredit::query()
            ->where('type', CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT)
            ->where('beneficiary_user_id', $referrer->id)
            ->sole();
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $credit->status);

        // Repetición del mismo webhook: no duplica la bonificación.
        app(CobrosPaymentConfirmedHandler::class)->handle($event);
        $this->assertSame(1, CobrosReferralBonusCredit::query()
            ->where('type', CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT)->count());
    }

    public function test_labor_incorporation_growth_credits_team_owner_on_first_paid_license(): void
    {
        // Titular del equipo incorpora un colaborador cuya primera licencia
        // es pagada por él (crecimiento). Beneficiario = owner_user_id.
        $titular = User::factory()->create(['is_active' => true, 'email' => 'titular.crec@ref.test']);
        $emisor = User::factory()->create(['is_active' => true, 'email' => 'emisor.crec@ref.test']);
        $company = $this->companyFor($titular);
        $colaborador = User::factory()->create(['is_active' => true, 'email' => 'colab.crec@ref.test']);
        $company->users()->attach($colaborador->id);

        $confirmed = $this->confirmedSinpe($company, $colaborador, $this->admin());

        $growth = CobrosReferralBonusCredit::query()
            ->where('source_confirmation_id', $confirmed->id)
            ->where('type', CobrosReferralBonusCredit::TYPE_GROWTH_COLLABORATOR)
            ->sole();
        $this->assertSame($titular->id, $growth->beneficiary_user_id);
        $this->assertNotSame($emisor->id, $growth->beneficiary_user_id);
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $growth->status);
        $this->assertSame('1000.0000', (string) $growth->amount);
    }

    public function test_renewal_or_second_confirmed_payment_emits_no_new_bonus(): void
    {
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'ref.ren@ref.test']);
        $titular = User::factory()->create(['is_active' => true]);
        $company = $this->companyFor($titular);
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pag.ren@ref.test']);
        $company->users()->attach($payer->id);
        $this->attributions->attribute($payer, $this->codes->codeFor($referrer), $company);

        // Contrato a 13 meses para que la renovación (siguiente período)
        // caiga dentro del término contractual.
        $contract = app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addMonthsNoOverflow(13)->startOfMonth(), $this->admin(),
        );

        $first = $this->confirmedOnContract($contract, $payer, $this->admin());
        $beforeCount = CobrosReferralBonusCredit::query()->count();

        // Un segundo pago confirmado (renovación de otro período) no emite
        // una nueva bonificación del programa.
        $renewalPending = app(CobrosPaymentConfirmationService::class)
            ->prepareRenewal($contract->fresh(), $this->admin());
        $second = app(CobrosPaymentConfirmationService::class)->confirm(
            $contract->fresh(), $this->admin(), $renewalPending->transaction_id,
            (string) $renewalPending->amount, $renewalPending->period_year,
            $renewalPending->period_month, 'internal',
        );

        $this->assertSame($beforeCount, CobrosReferralBonusCredit::query()->count());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $second->status);
    }
}
