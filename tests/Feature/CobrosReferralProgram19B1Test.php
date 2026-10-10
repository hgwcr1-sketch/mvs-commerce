<?php

namespace Tests\Feature;

use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosReferralBonusCredit;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\ReferralAttributionService;
use App\Services\Cobros\ReferralBonusService;
use App\Services\Cobros\ReferralCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * PRE-19B-1 — pruebas focales de la base del programa de referidos V1:
 * unicidad de código, atribución permanente, beneficiarios correctos y
 * anti-duplicados. Pagos 100 % simulados con servicios reales; sin
 * Stripe/SINPE ni landing/onboarding.
 */
class CobrosReferralProgram19B1Test extends TestCase
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

    /** @return array{0: Company, 1: User, 2: User} empresa (titular), pagador, admin Maestro */
    private function context(?User $owner = null): array
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pagador.'.uniqid().'@ref.test']);
        $owner ??= User::factory()->create(['is_active' => true, 'email' => 'titular.'.uniqid().'@ref.test']);
        $company = Company::create([
            'trade_name' => 'REF '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $owner->id,
        ]);
        $company->users()->attach($payer->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        return [$company, $payer, $admin, $owner];
    }

    private function confirmedPayment(Company $company, User $payer, User $admin): CobrosPaymentConfirmation
    {
        $contract = app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
        $pending = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $payer);

        return app(CobrosPaymentConfirmationService::class)->approveSinpe($pending, $admin);
    }

    public function test_referral_code_is_unique_stable_and_resolves_active_owners_only(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);

        $code = $this->codes->codeFor($owner);
        $this->assertSame($code, $this->codes->codeFor($owner), 'El código personal es estable.');
        $this->assertSame(12, strlen($code));
        $this->assertNotSame($code, $this->codes->codeFor($other));
        $this->assertSame($owner->id, $this->codes->resolve($code)?->id);
        $this->assertNull($this->codes->resolve('x'), 'Formato inválido se ignora.');
        $this->assertNull($this->codes->resolve('MVSNOEXISTE99'));

        $inactive = User::factory()->create(['is_active' => false]);
        $inactiveCode = $this->codes->codeFor($inactive);
        $this->assertNull($this->codes->resolve($inactiveCode), 'Inactivos no resuelven.');
    }

    public function test_attribution_is_permanent_single_and_rejects_self_referral(): void
    {
        $referrer = User::factory()->create(['is_active' => true]);
        $code = $this->codes->codeFor($referrer);
        $referred = User::factory()->create(['is_active' => true]);

        $attribution = $this->attributions->attribute($referred, $code);
        $this->assertSame($referrer->id, $attribution->referrer_user_id);
        $this->assertSame($referred->id, $attribution->referred_user_id);

        // Atribución permanente: un segundo código no la cambia ni duplica.
        $otherCode = $this->codes->codeFor(User::factory()->create(['is_active' => true]));
        $again = $this->attributions->attribute($referred, $otherCode);
        $this->assertSame($attribution->id, $again->id);
        $this->assertSame($referrer->id, $again->referrer_user_id);

        // Sin auto-referido ni códigos inválidos.
        try {
            $this->attributions->attribute($referrer, $code);
            $this->fail('Se esperaba rechazo de auto-referido.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, $attribution->newQuery()->count());
    }

    public function test_first_payment_bonus_credits_referrer_only_after_confirmed_payment(): void
    {
        $referrer = User::factory()->create(['is_active' => true]);
        $code = $this->codes->codeFor($referrer);
        [$company, $payer, $admin] = $this->context();
        $this->attributions->attribute($payer, $code, $company);

        $contract = app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
        $pending = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $payer);

        // Regla: sin pago legítimamente confirmado no hay bonificación.
        $this->assertNull($this->bonuses->grantFirstPaymentBonus($pending));

        $confirmed = app(CobrosPaymentConfirmationService::class)->approveSinpe($pending, $admin);
        $credit = $this->bonuses->grantFirstPaymentBonus($confirmed);

        $this->assertNotNull($credit);
        $this->assertSame(CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT, $credit->type);
        $this->assertSame($referrer->id, $credit->beneficiary_user_id);
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $credit->status);
        $this->assertSame('10.0000', (string) $credit->percent);
        $this->assertSame('1000.0000', (string) $credit->amount, '10 % de 10000.0000 como saldo a favor.');
        $this->assertSame('CRC', $credit->currency);

        // Idempotente: reintentos no duplican la bonificación.
        $this->assertSame($credit->id, $this->bonuses->grantFirstPaymentBonus($confirmed->fresh())?->id);
        $this->assertSame(1, CobrosReferralBonusCredit::query()->count());
    }

    public function test_growth_bonus_always_benefits_team_owner_and_never_duplicates_an_alta(): void
    {
        // Equipo: el titular del equipo es el dueño de la empresa; el
        // "enviante de invitación" es otro usuario que NO debe cobrar.
        $titular = User::factory()->create(['is_active' => true, 'email' => 'titular.equipo@ref.test']);
        $invitationSender = User::factory()->create(['is_active' => true, 'email' => 'emisor@ref.test']);
        [$company, $collaborator, $admin] = $this->context($titular);

        $confirmed = $this->confirmedPayment($company, $collaborator, $admin);

        // Crecimiento: beneficiario = titular del equipo, nunca el emisor.
        $growth = $this->bonuses->grantGrowthCollaboratorBonus($collaborator, $confirmed);
        $this->assertNotNull($growth);
        $this->assertSame(CobrosReferralBonusCredit::TYPE_GROWTH_COLLABORATOR, $growth->type);
        $this->assertSame($titular->id, $growth->beneficiary_user_id);
        $this->assertNotSame($invitationSender->id, $growth->beneficiary_user_id);
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $growth->status);
        $this->assertSame('1000.0000', (string) $growth->amount);

        // Misma alta + mismo pago: el referido comercial NO puede pagar una
        // segunda bonificación (anti-duplicado global).
        $referrer = User::factory()->create(['is_active' => true]);
        $this->attributions->attribute($collaborator, $this->codes->codeFor($referrer), $company);
        $this->assertNull($this->bonuses->grantFirstPaymentBonus($confirmed->fresh()));
        $this->assertSame(1, CobrosReferralBonusCredit::query()->count());

        // Orden inverso en otra empresa: el referido comercial gana primero
        // y el crecimiento no duplica.
        $titular2 = User::factory()->create(['is_active' => true, 'email' => 'titular2@ref.test']);
        [$company2, $collaborator2, $admin2] = $this->context($titular2);
        $referrer2 = User::factory()->create(['is_active' => true]);
        $this->attributions->attribute($collaborator2, $this->codes->codeFor($referrer2), $company2);
        $confirmed2 = $this->confirmedPayment($company2, $collaborator2, $admin2);

        $first = $this->bonuses->grantFirstPaymentBonus($confirmed2);
        $this->assertNotNull($first);
        $this->assertSame($referrer2->id, $first->beneficiary_user_id);
        $this->assertNull($this->bonuses->grantGrowthCollaboratorBonus($collaborator2, $confirmed2->fresh()));
        $this->assertSame(2, CobrosReferralBonusCredit::query()->count());
    }

    public function test_unique_keys_block_duplicate_rows_at_persistence_level(): void
    {
        $referrer = User::factory()->create(['is_active' => true]);
        [$company, $payer, $admin] = $this->context();
        $this->attributions->attribute($payer, $this->codes->codeFor($referrer), $company);
        $confirmed = $this->confirmedPayment($company, $payer, $admin);

        $credit = $this->bonuses->grantFirstPaymentBonus($confirmed);
        $this->assertNotNull($credit);

        // event_key y (alta, pago) son únicos a nivel de persistencia.
        try {
            CobrosReferralBonusCredit::create([
                'company_id' => $company->id,
                'beneficiary_user_id' => $referrer->id,
                'referred_user_id' => $payer->id,
                'type' => CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT,
                'source_contract_id' => $credit->source_contract_id,
                'source_confirmation_id' => $confirmed->id,
                'percent' => '10.0000',
                'amount' => '1000.0000',
                'currency' => 'CRC',
                'status' => CobrosReferralBonusCredit::STATUS_CREDIT,
                'event_key' => $credit->event_key,
            ]);
            $this->fail('Se esperaba violación de unicidad por event_key.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        }
    }
}
