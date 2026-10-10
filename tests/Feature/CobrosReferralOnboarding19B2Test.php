<?php

namespace Tests\Feature;

use App\Models\CobrosReferralAttribution;
use App\Models\CobrosRegistrationDraft;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosActivationContractService;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\CobrosPostPaymentActivationService;
use App\Services\Cobros\ReferralCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRE-19B-2 — enlaces personales de referido conectados al onboarding V1.
 *
 * Flujo: enlace personal válido (?ref=) → atribución temporal en sesión →
 * el registro conserva la referencia en el borrador (comportamiento ya
 * existente) → al COMPLETAR el registro (activación tras pago confirmado)
 * se persiste la relación con ReferralAttributionService. Sin conexión a
 * Stripe/SINPE (pago simulado con servicios reales).
 */
class CobrosReferralOnboarding19B2Test extends TestCase
{
    use RefreshDatabase;

    private ReferralCodeService $codes;

    private CobrosPostPaymentActivationService $postPayment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codes = app(ReferralCodeService::class);
        $this->postPayment = app(CobrosPostPaymentActivationService::class);
    }

    private function billingContext(): array
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pagador.'.uniqid().'@ref.test']);
        $company = Company::create([
            'trade_name' => 'ONB '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $payer->id,
        ]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $contract = app(CobrosBillingContractService::class)->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
        $pending = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $payer);
        $confirmed = app(CobrosPaymentConfirmationService::class)->approveSinpe($pending, $admin);

        return [$confirmed, $payer, $admin];
    }

    private function draftWithEmail(string $email, ?array $referral, int $billingContractId): CobrosRegistrationDraft
    {
        $draft = CobrosRegistrationDraft::create([
            'email' => $email,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'prestamista',
                'personal' => ['name' => 'Nuevo Cobrador', 'email' => $email, 'phone' => '8888-8888'],
                'portfolio_plan' => 'manual',
                'routes' => [['id' => (string) Str::uuid(), 'name' => 'Ruta', 'code' => 'RN']],
                'referral' => $referral,
            ],
            'last_saved_at' => now(),
        ]);
        $draft->update(['billing_contract_id' => $billingContractId]);

        return $draft;
    }

    public function test_personal_referral_link_stores_session_and_persists_at_registration_completion(): void
    {
        $referrer = User::factory()->create(['is_active' => true, 'email' => 'referente@ref.test']);
        $code = $this->codes->codeFor($referrer);

        // 1) Enlace personal válido: atribución temporal en sesión, sin
        //    exponer el código en la página.
        $landing = $this->get('/bienvenida-cobros?ref='.urlencode($code));
        $landing->assertOk()->assertDontSee($code);
        $stored = session('cobros.referral');
        $this->assertIsArray($stored);
        $this->assertSame($code, $stored['code']);

        // 2) Registro completado (activación tras pago confirmado): la
        //    relación se persiste de forma permanente.
        [$confirmation] = $this->billingContext();
        $draft = $this->draftWithEmail(
            'nuevo.'.uniqid().'@ref.test',
            ['code' => $code, 'captured_at' => $stored['captured_at'] ?? now()->toIso8601String()],
            $confirmation->cobros_billing_contract_id,
        );

        $result = $this->postPayment->activateFromPayment($confirmation, $draft);

        $attribution = CobrosReferralAttribution::query()
            ->where('referred_user_id', $result['user']->id)
            ->sole();
        $this->assertSame($referrer->id, $attribution->referrer_user_id);
        $this->assertSame($code, $attribution->code);
        $this->assertSame($result['company']->id, $attribution->company_id);
        $this->assertNotNull($attribution->captured_at);

        // 3) Reintento de activación: idempotente, una sola atribución.
        $again = $this->postPayment->activateFromPayment($confirmation->fresh(), $draft);
        $this->assertSame($result['user']->id, $again['user']->id);
        $this->assertSame(1, CobrosReferralAttribution::query()->count());
    }

    public function test_unresolvable_referral_code_does_not_block_registration_activation(): void
    {
        [$confirmation] = $this->billingContext();
        $email = 'sinref.'.uniqid().'@ref.test';
        // Formato válido pero sin dueño resoluble (mejor esfuerzo).
        $draft = $this->draftWithEmail(
            $email,
            ['code' => 'MVSSINDUENO99', 'captured_at' => now()->toIso8601String()],
            $confirmation->cobros_billing_contract_id,
        );

        $result = $this->postPayment->activateFromPayment($confirmation, $draft);

        $this->assertNotNull($result['user']);
        $this->assertSame($email, $result['user']->email);
        $this->assertSame(0, CobrosReferralAttribution::query()->count());
    }
}
