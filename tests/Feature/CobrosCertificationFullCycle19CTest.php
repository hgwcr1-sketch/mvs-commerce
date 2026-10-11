<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosReferralBonusCredit;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\Contracts\PaymentEventTypes;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CollectorManagementService;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\CobrosPaymentConfirmedHandler;
use App\Services\Cobros\ReferralAttributionService;
use App\Services\Cobros\ReferralBonusService;
use App\Services\Cobros\ReferralCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 19C-2B — certificación E2E del ciclo completo:
 * registro → referido comercial → primer pago confirmado → bono 10 % →
 * activación → renovación (sin segundo bono) → suspensión aislada.
 *
 * Detecta bonificaciones duplicadas, distingue beneficiario comercial frente
 * a crecimiento del equipo y verifica idempotencia de aprobaciones/webhooks.
 * Proveedores simulados locales; sin llamadas externas ni Stripe LIVE.
 */
class CobrosCertificationFullCycle19CTest extends TestCase
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

    /** @return array{0: Company, 1: User, 2: User} empresa (dueño=pagador), admin Maestro */
    private function context(): array
    {
        $owner = User::factory()->create(['is_active' => true, 'email' => 'ciclo.dueno.'.uniqid().'@e19c.test']);
        $company = Company::create([
            'trade_name' => '19C '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $owner->id,
        ]);

        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueno '.uniqid(), 'is_active' => true]);
        foreach (['cobros.portal_dueno', 'cobros.acceder'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        return [$company, $owner, $admin];
    }

    private function openContract(Company $company, User $payer, User $admin, int $months = 12): CobrosBillingContract
    {
        return app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addMonthsNoOverflow($months)->startOfMonth(), $admin,
        );
    }

    private function bonusCount(?int $confirmationId = null): int
    {
        $query = CobrosReferralBonusCredit::query()->where('type', CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT);
        if ($confirmationId !== null) {
            $query->where('source_confirmation_id', $confirmationId);
        }

        return $query->count();
    }

    public function test_full_lifecycle_referral_activation_renewal_suspension_without_duplicate_bonus(): void
    {
        [$company, $owner, $admin] = $this->context();

        // Referido comercial: el dueño llegó por el código de un referente.
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'ciclo.referente.'.uniqid().'@e19c.test']);
        $this->attributions->attribute($owner, $this->codes->codeFor($referrer), $company);

        // Contrato a 13 meses: la renovación (siguiente período) cae dentro
        // del término contractual.
        $contract = $this->openContract($company, $owner, $admin, 13);

        // 1) Primer pago confirmado (SINPE aprobado por Maestro).
        $pending = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $owner);
        $confirmed = app(CobrosPaymentConfirmationService::class)->approveSinpe($pending, $admin);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $confirmed->status);

        // 2) Activación del contrato y licencia del pagador.
        $this->assertSame('active', $contract->fresh()->status);
        $license = CobrosCollectorLicense::query()
            ->where('company_id', $company->id)->where('user_id', $owner->id)->sole();
        $this->assertSame('active', app(CollectorLicenseService::class)->stateOf($license, $company));

        // 3) Bono del 10 % como CRÉDITO futuro (no dinero entregado) y solo
        //    para el referente comercial — sin bono de crecimiento.
        $credit = CobrosReferralBonusCredit::query()
            ->where('source_confirmation_id', $confirmed->id)
            ->where('type', CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT)
            ->sole();
        $expected = $this->bonuses->bonusAmount((string) $confirmed->amount);
        $this->assertSame($referrer->id, $credit->beneficiary_user_id);
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $credit->status);
        $this->assertSame(0, bccomp($expected, (string) $credit->amount, 4));
        $this->assertSame(0, CobrosReferralBonusCredit::query()
            ->where('type', CobrosReferralBonusCredit::TYPE_GROWTH_COLLABORATOR)->count());

        // 4) Idempotencia de aprobación: segunda aprobación no duplica
        //    confirmación, licencia ni bono.
        app(CobrosPaymentConfirmationService::class)->approveSinpe($confirmed->fresh(), $admin);
        $this->assertSame(1, CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)->count());
        $this->assertSame(1, $this->bonusCount());
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());

        // 5) Renovación del período siguiente: extiende la vigencia y NO
        //    genera un segundo bono para el mismo referido.
        $expiresBefore = CobrosCollectorLicense::query()->where('user_id', $owner->id)->sole()->expires_at;
        $renewalPending = app(CobrosPaymentConfirmationService::class)->prepareRenewal($contract->fresh(), $admin);
        $renewal = app(CobrosPaymentConfirmationService::class)->confirm(
            $contract->fresh(), $admin, $renewalPending->transaction_id,
            (string) $renewalPending->amount, $renewalPending->period_year,
            $renewalPending->period_month, 'internal',
        );
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $renewal->status);
        $this->assertSame(1, $this->bonusCount(), 'La renovación no debe emitir un segundo bono de referido.');
        $this->assertTrue(
            CobrosCollectorLicense::query()->where('user_id', $owner->id)->sole()->expires_at->greaterThan($expiresBefore),
            'La renovación debe extender la vigencia de la licencia.'
        );

        // 6) Cobrador cuyo prestamista paga: NO ve información financiera
        //    (puerta de membresía/pago reservada al pagador).
        $this->applyIndividualMode($company, $admin);
        $collector = app(CollectorManagementService::class)->createCollector(
            $company,
            ['email' => 'ciclo.cobrador.'.uniqid().'@e19c.test', 'name' => 'Cobrador Ciclo'],
            $admin,
            true,
        )['user'];

        $this->actingAs($collector)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertForbidden();

        // 7) Suspensión por vencimiento de la licencia del cobrador: bloquea
        //    solo a ese cobrador; el pagador renovado sigue operando.
        CobrosCollectorLicense::query()->where('user_id', $collector->id)->sole()->update([
            'expires_at' => now()->subDay(),
        ]);
        $this->actingAs($collector)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertForbidden();
        $this->assertSame(
            CobrosCollectorLicense::STATE_ACTIVE,
            app(CollectorLicenseService::class)->stateOf(
                CobrosCollectorLicense::query()->where('user_id', $owner->id)->sole(), $company
            )
        );
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.owner.dashboard'));
    }

    public function test_stripe_webhook_replay_keeps_single_bonus_and_confirmation(): void
    {
        [$company, $owner, $admin] = $this->context();
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'ciclo.stripe.'.uniqid().'@e19c.test']);
        $this->attributions->attribute($owner, $this->codes->codeFor($referrer), $company);

        $contract = $this->openContract($company, $owner, $admin);

        // Borrador de registro enlazado al contrato: condición del handler
        // Stripe para activar (patrón PRE-19B-3).
        $draft = \App\Models\CobrosRegistrationDraft::create([
            'email' => 'ciclo.stripe.'.uniqid().'@e19c.test',
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'prestamista',
                'personal' => ['name' => 'Ciclo Stripe', 'email' => 'ciclo.stripe.'.uniqid().'@e19c.test', 'phone' => '8888-8888'],
                'portfolio_plan' => 'manual',
                'routes' => [],
            ],
            'last_saved_at' => now(),
        ]);
        $draft->update(['billing_contract_id' => $contract->id]);

        $event = new PaymentEvent(
            type: PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: 'pi_19c_'.uniqid(),
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
        $handler->handle($event); // replay del webhook

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame(1, CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)->count());
        $this->assertSame(1, $this->bonusCount());
        $credit = CobrosReferralBonusCredit::query()
            ->where('type', CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT)->sole();
        $this->assertSame($referrer->id, $credit->beneficiary_user_id);
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $credit->status);
        $this->assertSame('1000.0000', (string) $credit->amount);
    }

    public function test_growth_beneficiary_is_team_owner_not_commercialreferrer(): void
    {
        // Colaborador sin referido comercial: el bono de crecimiento es del
        // titular del equipo (owner_user_id), nunca de un referente ajeno.
        $titular = User::factory()->create(['is_active' => true, 'email' => 'ciclo.titular.'.uniqid().'@e19c.test']);
        $company = Company::create([
            'trade_name' => '19C-G '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $titular->id,
        ]);
        $titularRole = Role::create(['company_id' => $company->id, 'name' => 'Titular '.uniqid(), 'is_active' => true]);
        $titularRole->permissions()->attach(Permission::firstOrCreate(
            ['name' => 'cobros.acceder'], ['label' => 'cobros.acceder', 'module' => 'CobrOS', 'is_active' => true]
        )->id);
        $titular->companies()->attach($company->id, ['role_id' => $titularRole->id]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $collaborator = User::factory()->create(['is_active' => true, 'email' => 'ciclo.colab.'.uniqid().'@e19c.test']);
        $company->users()->attach($collaborator->id);

        $contract = $this->openContract($company, $collaborator, $admin);
        $pending = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $collaborator);
        $confirmed = app(CobrosPaymentConfirmationService::class)->approveSinpe($pending, $admin);

        $growth = CobrosReferralBonusCredit::query()
            ->where('source_confirmation_id', $confirmed->id)
            ->where('type', CobrosReferralBonusCredit::TYPE_GROWTH_COLLABORATOR)
            ->sole();
        $this->assertSame($titular->id, $growth->beneficiary_user_id);
        $this->assertSame(CobrosReferralBonusCredit::STATUS_CREDIT, $growth->status);
        $this->assertSame(0, $this->bonusCount(), 'Sin atribución comercial no hay bono de referente.');
    }

    private function applyIndividualMode(Company $company, User $admin): void
    {
        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])
            ->assertSessionHasNoErrors();

        $company->refresh();
    }
}
