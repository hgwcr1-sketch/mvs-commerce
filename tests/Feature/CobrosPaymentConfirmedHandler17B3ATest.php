<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosRegistrationDraft;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Contracts\PaymentEventTypes;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Cobros\CobrosPaymentConfirmedHandler;
use App\Services\Cobros\CobrosPostPaymentActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BLOQUE 17B-3A — pruebas focales del handler CobrOS para pagos confirmados.
 *
 * Verifica que eventos Stripe confirmados activan la lógica de CobrOS
 * sin duplicar activaciones ni fallar silenciosamente.
 */
class CobrosPaymentConfirmedHandler17B3ATest extends TestCase
{
    use RefreshDatabase;

    private CobrosPaymentConfirmedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(CobrosPaymentConfirmedHandler::class);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    private function lender(string $email): array
    {
        $lender = User::factory()->create(['is_active' => true, 'email' => $email]);
        $company = Company::create([
            'trade_name' => 'Prestamista '.explode('@', $email)[0],
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);

        return [$company, $lender];
    }

    private function linkedDraft(array $issued, string $cobradorEmail): CobrosRegistrationDraft
    {
        $invitationId = $issued['invitation']?->id ?? 0;
        $invitationTokenHash = $issued['invitation']?->token_hash ?? '';
        $invitedBy = $issued['lender']->email ?? '';
        $lenderName = $issued['lender']->name ?? '';
        $companyName = $issued['company']->trade_name ?? '';

        return CobrosRegistrationDraft::create([
            'email' => $cobradorEmail,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_vinculado',
                'personal' => ['name' => 'Cobrador Activo', 'email' => $cobradorEmail, 'phone' => '8888-8888'],
                'portfolio_plan' => 'manual',
                'routes' => [[
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => 'Ruta Norte',
                    'code' => 'RN',
                ]],
                'labor_invitation' => [
                    'id' => $invitationId,
                    'token_hash' => $invitationTokenHash,
                    'invited_by' => $invitedBy,
                    'lender_name' => $lenderName,
                    'company' => $companyName,
                ],
            ],
            'last_saved_at' => now(),
        ]);
    }

    private function createContractAndConfirmation(Company $company, User $payer, User $admin): CobrosBillingContract
    {
        $billingContracts = app(\App\Services\Cobros\CobrosBillingContractService::class);
        $payments = app(\App\Services\Cobros\CobrosPaymentConfirmationService::class);

        $contract = $billingContracts->createIndividual(
            $company, 'cobrador_vinculado', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        return $contract;
    }

    private function confirmedPaymentEvent(
        string $externalId,
        string $companyId,
        string $contractReference,
        string $amount = '10000.0000',
        int $periodYear = 2026,
        int $periodMonth = 10,
    ): PaymentEvent {
        return new PaymentEvent(
            type: PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: $externalId,
            product: 'cobros',
            companyId: $companyId,
            contractReference: $contractReference,
            currency: 'CRC',
            amount: $amount,
            periodYear: $periodYear,
            periodMonth: $periodMonth,
        );
    }

    // ------------------------------------------------------------------
    // Pago confirmado válido → activación
    // ------------------------------------------------------------------

    public function test_confirmed_stripe_payment_activates_linked_collector(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@handler.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);

        // Borrador vinculado al cobrador (email DIFERENTE del prestamista)
        $cobradorEmail = 'cobrador@handler.test';

        // Crear invitación laboral REAL para que la activación la valide
        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issued = $invitationService->issue($lenderCompany, $lender, $cobradorEmail, 1440);
        $invitation = $issued['invitation'];

        $draft = $this->linkedDraft([
            'invitation' => $invitation,
            'lender' => $lender,
            'company' => $lenderCompany,
        ], $cobradorEmail);

        $event = $this->confirmedPaymentEvent(
            'pi_test_activacion_123',
            (string) $lenderCompany->id,
            (string) $contract->id,
            '10000.0000',
            2026,
            10,
        );

        $this->handler->handle($event);

        // Verificar activación: usuario creado con email del cobrador, vinculado a empresa del prestamista
        $user = User::where('email', 'cobrador@handler.test')->first();
        $this->assertNotNull($user, 'Usuario cobrador debe ser creado/activado');
        $this->assertTrue($user->companies()->where('company_id', $lenderCompany->id)->exists());
    }

    // ------------------------------------------------------------------
    // Pago duplicado → sin doble activación
    // ------------------------------------------------------------------

    public function test_duplicate_stripe_payment_no_double_activation(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@dup-handler.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);
        $cobradorEmail = 'cobrador@dup-handler.test';

        // Crear invitación laboral REAL para que la activación la valide
        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issued = $invitationService->issue($lenderCompany, $lender, $cobradorEmail, 1440);
        $invitation = $issued['invitation'];

        $draft = $this->linkedDraft([
            'invitation' => $invitation,
            'lender' => $lender,
            'company' => $lenderCompany,
        ], $cobradorEmail);

        // Vincular borrador al contrato para que el handler lo encuentre
        $draft->update(['billing_contract_id' => $contract->id]);

        $event = $this->confirmedPaymentEvent(
            'pi_test_dup_123',
            (string) $lenderCompany->id,
            (string) $contract->id,
        );

        // Primera llamada
        $this->handler->handle($event);
        $userCount1 = User::where('email', 'cobrador@dup-handler.test')->count();
        $routeCount1 = \App\Models\CobrosRoute::forCompany($lenderCompany->id)->count();

        // Segunda llamada con mismo payment_intent.id
        $this->handler->handle($event);
        $userCount2 = User::where('email', 'cobrador@dup-handler.test')->count();
        $routeCount2 = \App\Models\CobrosRoute::forCompany($lenderCompany->id)->count();

        // No duplicados
        $this->assertSame($userCount1, $userCount2);
        $this->assertSame($routeCount1, $routeCount2);
        $this->assertSame(1, $userCount1);
    }

    // ------------------------------------------------------------------
    // Importe incorrecto → rechazo
    // ------------------------------------------------------------------

    public function test_wrong_amount_rejected(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@amount.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);
        $cobradorEmail = 'cobrador@amount.test';

        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issued = $invitationService->issue($lenderCompany, $lender, $cobradorEmail, 1440);
        $invitation = $issued['invitation'];

        $draft = $this->linkedDraft([
            'invitation' => $invitation,
            'lender' => $lender,
            'company' => $lenderCompany,
        ], $cobradorEmail);

        $draft->update(['billing_contract_id' => $contract->id]);

        $event = $this->confirmedPaymentEvent(
            'pi_test_amount_123',
            (string) $lenderCompany->id,
            (string) $contract->id,
            '9999.0000', // Importe incorrecto
        );

        $this->handler->handle($event);

        // No debe activar
        $this->assertSame(0, User::where('email', 'cobrador@amount.test')->count());
    }

    // ------------------------------------------------------------------
    // Moneda incorrecta → rechazo
    // ------------------------------------------------------------------

    public function test_wrong_currency_rejected(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@currency.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);
        $cobradorEmail = 'cobrador@currency.test';

        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issued = $invitationService->issue($lenderCompany, $lender, $cobradorEmail, 1440);
        $invitation = $issued['invitation'];

        $draft = $this->linkedDraft([
            'invitation' => $invitation,
            'lender' => $lender,
            'company' => $lenderCompany,
        ], $cobradorEmail);

        $draft->update(['billing_contract_id' => $contract->id]);

        $event = $this->confirmedPaymentEvent(
            'pi_test_currency_123',
            (string) $lenderCompany->id,
            (string) $contract->id,
            '10000.0000',
            2026,
            10,
        );
        // Forzar moneda diferente en el evento
        $event = new PaymentEvent(
            type: PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: 'pi_test_currency_123',
            product: 'cobros',
            companyId: (string) $lenderCompany->id,
            contractReference: (string) $contract->id,
            currency: 'USD', // Moneda incorrecta
            amount: '10000.0000',
            periodYear: 2026,
            periodMonth: 10,
        );

        $this->handler->handle($event);

        // No debe activar
        $this->assertSame(0, User::where('email', 'cobrador@currency.test')->count());
    }

    // ------------------------------------------------------------------
    // Contrato incorrecto → rechazo
    // ------------------------------------------------------------------

    public function test_wrong_contract_reference_rejected(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@contract.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);
        $cobradorEmail = 'cobrador@contract.test';

        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issued = $invitationService->issue($lenderCompany, $lender, $cobradorEmail, 1440);
        $invitation = $issued['invitation'];

        $draft = $this->linkedDraft([
            'invitation' => $invitation,
            'lender' => $lender,
            'company' => $lenderCompany,
        ], $cobradorEmail);

        $draft->update(['billing_contract_id' => $contract->id]);

        $event = $this->confirmedPaymentEvent(
            'pi_test_contract_123',
            (string) $lenderCompany->id,
            '999999', // contractReference inexistente
        );

        $this->handler->handle($event);

        // No debe activar
        $this->assertSame(0, User::where('email', 'cobrador@contract.test')->count());
    }

    // ------------------------------------------------------------------
    // Contrato empresarial → cupos correctos (activación sin duplicar)
    // ------------------------------------------------------------------

    public function test_enterprise_contract_activates_company_cupo(): void
    {
        [$company, $payer] = $this->lender('presta@enterprise.test');
        $admin = $this->platformAdmin();

        $billingContracts = app(\App\Services\Cobros\CobrosBillingContractService::class);

        $contract = $billingContracts->createEnterprise(
            $company, 3, 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        // Borrador independiente (crea su propia empresa)
        $draft = CobrosRegistrationDraft::create([
            'email' => 'cobrador.enterprise@test.com',
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_independiente',
                'personal' => ['name' => 'Cobrador Solo', 'email' => 'cobrador.enterprise@test.com', 'phone' => null],
                'portfolio_plan' => 'manual',
                'routes' => [[
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => 'Ruta Este',
                ]],
            ],
            'last_saved_at' => now(),
        ]);

        // Vincular borrador al contrato
        $draft->update(['billing_contract_id' => $contract->id]);

        $event = $this->confirmedPaymentEvent(
            'pi_test_enterprise_123',
            (string) $company->id,
            (string) $contract->id,
            $contract->monthly_amount,
            2026,
            10,
        );

        $this->handler->handle($event);

        // Usuario creado con su propia empresa
        $user = User::where('email', 'cobrador.enterprise@test.com')->first();
        $this->assertNotNull($user);
        $this->assertNotSame($company->id, $user->companies()->first()->id, 'Independiente crea su propia empresa');
    }

    // ------------------------------------------------------------------
    // Fallo interno → recuperable (pago confirmado permanece)
    // ------------------------------------------------------------------

    public function test_internal_failure_keeps_payment_confirmed(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@fail.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);

        // Borrador con invitación INVÁLIDA (id=0, token_hash vacío) → la activación fallará internamente
        $cobradorEmail = 'cobrador@fail.test';
        $draft = CobrosRegistrationDraft::create([
            'email' => $cobradorEmail,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_vinculado',
                'personal' => ['name' => 'Cobrador Fail', 'email' => $cobradorEmail, 'phone' => '8888-8888'],
                'portfolio_plan' => 'manual',
                'routes' => [[
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => 'Ruta Fail',
                ]],
                'labor_invitation' => [
                    'id' => 0,
                    'token_hash' => '',
                    'invited_by' => $lender->email,
                    'lender_name' => $lender->name,
                    'company' => $lenderCompany->trade_name,
                ],
            ],
            'last_saved_at' => now(),
        ]);

        // Vincular borrador al contrato para que el handler lo encuentre
        $draft->update(['billing_contract_id' => $contract->id]);

        $event = $this->confirmedPaymentEvent(
            'pi_test_fail_123',
            (string) $lenderCompany->id,
            (string) $contract->id,
        );

        $this->handler->handle($event);

        // La confirmación de pago CobrOS debe existir (idempotente)
        $confirmation = CobrosPaymentConfirmation::query()
            ->where('transaction_id', 'pi_test_fail_123')
            ->first();
        $this->assertNotNull($confirmation);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $confirmation->status);
    }

    // ------------------------------------------------------------------
    // Ninguna activación con pago pendiente o fallido
    // ------------------------------------------------------------------

    public function test_pending_payment_no_activation(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@pending.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);
        $cobradorEmail = 'cobrador@pending.test';

        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issued = $invitationService->issue($lenderCompany, $lender, $cobradorEmail, 1440);
        $invitation = $issued['invitation'];

        $draft = $this->linkedDraft([
            'invitation' => $invitation,
            'lender' => $lender,
            'company' => $lenderCompany,
        ], $cobradorEmail);

        $draft->update(['billing_contract_id' => $contract->id]);

        $event = new PaymentEvent(
            type: PaymentEventTypes::PENDING,
            provider: 'stripe',
            externalId: 'pi_test_pending_123',
            product: 'cobros',
            companyId: (string) $lenderCompany->id,
            contractReference: (string) $contract->id,
            currency: 'CRC',
            amount: '10000.0000',
            periodYear: 2026,
            periodMonth: 10,
        );

        $this->handler->handle($event);

        // No debe activar
        $this->assertSame(0, User::where('email', 'cobrador@pending.test')->count());
    }

    public function test_failed_payment_no_activation(): void
    {
        [$lenderCompany, $lender] = $this->lender('presta@failed.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($lenderCompany, $lender, $admin);
        $cobradorEmail = 'cobrador@failed.test';

        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issued = $invitationService->issue($lenderCompany, $lender, $cobradorEmail, 1440);
        $invitation = $issued['invitation'];

        $draft = $this->linkedDraft([
            'invitation' => $invitation,
            'lender' => $lender,
            'company' => $lenderCompany,
        ], $cobradorEmail);

        $draft->update(['billing_contract_id' => $contract->id]);

        $event = new PaymentEvent(
            type: PaymentEventTypes::FAILED,
            provider: 'stripe',
            externalId: 'pi_test_failed_123',
            product: 'cobros',
            companyId: (string) $lenderCompany->id,
            contractReference: (string) $contract->id,
            currency: 'CRC',
            amount: '10000.0000',
            periodYear: 2026,
            periodMonth: 10,
        );

        $this->handler->handle($event);

        // No debe activar
        $this->assertSame(0, User::where('email', 'cobrador@failed.test')->count());
    }

    // ------------------------------------------------------------------
    // Aislamiento entre empresas
    // ------------------------------------------------------------------

    public function test_activation_isolated_between_companies(): void
    {
        [$companyA, $payerA] = $this->lender('presta.a@isolate.test');
        [$companyB, $payerB] = $this->lender('presta.b@isolate.test');
        $admin = $this->platformAdmin();

        $contractA = $this->createContractAndConfirmation($companyA, $payerA, $admin);
        $contractB = $this->createContractAndConfirmation($companyB, $payerB, $admin);

        $cobradorEmailA = 'cobrador.a@isolate.test';
        $cobradorEmailB = 'cobrador.b@isolate.test';

        // Crear invitaciones reales para ambas empresas
        $invitationService = app(\App\Services\Cobros\LaborInvitationService::class);
        $issuedA = $invitationService->issue($companyA, $payerA, $cobradorEmailA, 1440);
        $issuedB = $invitationService->issue($companyB, $payerB, $cobradorEmailB, 1440);

        $draftA = $this->linkedDraft([
            'invitation' => $issuedA['invitation'],
            'lender' => $payerA,
            'company' => $companyA,
        ], $cobradorEmailA);
        $draftB = $this->linkedDraft([
            'invitation' => $issuedB['invitation'],
            'lender' => $payerB,
            'company' => $companyB,
        ], $cobradorEmailB);

        // Vincular borradores a sus respectivos contratos
        $draftA->update(['billing_contract_id' => $contractA->id]);
        $draftB->update(['billing_contract_id' => $contractB->id]);

        $eventA = $this->confirmedPaymentEvent(
            'pi_test_iso_a_123',
            (string) $companyA->id,
            (string) $contractA->id,
        );
        $eventB = $this->confirmedPaymentEvent(
            'pi_test_iso_b_123',
            (string) $companyB->id,
            (string) $contractB->id,
        );

        $this->handler->handle($eventA);
        $this->handler->handle($eventB);

        // Cada empresa tiene su propio usuario/activación
        $this->assertSame(1, User::where('email', 'cobrador.a@isolate.test')->count());
        $this->assertSame(1, User::where('email', 'cobrador.b@isolate.test')->count());

        // Aislamiento total
        $this->assertFalse(User::where('email', 'cobrador.a@isolate.test')->first()->companies()->where('company_id', $companyB->id)->exists());
    }

    // ------------------------------------------------------------------
    // 19D-2N — activación SIN borrador (contratos de administración P22)
    // ------------------------------------------------------------------

    public function test_confirmed_payment_without_draft_activates_contract_and_license(): void
    {
        [$company, $payer] = $this->lender('presta@nodraft.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($company, $payer, $admin);
        $this->assertSame('draft', $contract->status);
        $this->assertSame(0, \App\Models\CobrosPaymentConfirmation::count());

        $event = $this->confirmedPaymentEvent(
            'pi_test_nodraft_123',
            (string) $company->id,
            (string) $contract->id,
        );

        $this->handler->handle($event);

        $contract->refresh();
        $this->assertSame('active', $contract->status);
        $this->assertSame(1, \App\Models\CobrosPaymentConfirmation::count());
        $this->assertSame(1, \App\Models\CobrosPaymentConfirmation::query()
            ->where('transaction_id', 'pi_test_nodraft_123')
            ->where('status', 'confirmed')
            ->count());
        $this->assertSame(1, \App\Models\CobrosCollectorLicense::query()
            ->where('company_id', $company->id)
            ->where('user_id', $payer->id)
            ->count());
    }

    public function test_recover_from_billing_record_is_idempotent(): void
    {
        [$company, $payer] = $this->lender('presta@recover.test');
        $admin = $this->platformAdmin();

        $contract = $this->createContractAndConfirmation($company, $payer, $admin);

        // Registro CONFIRMED en Billing sin activación CobrOS (caso 19D-2M)
        $record = \App\Models\BillingPaymentRecord::create([
            'provider' => 'stripe',
            'external_id' => 'pi_test_recover_123',
            'product' => 'cobros',
            'company_id' => $company->id,
            'contract_reference' => (string) $contract->id,
            'currency' => 'CRC',
            'amount' => '10000.0000',
            'period_year' => 2026,
            'period_month' => 10,
            'status' => 'confirmed',
            'raw_payload' => null,
            'confirmed_at' => now(),
        ]);

        $this->handler->recoverFromBillingRecord($record);
        $contract->refresh();
        $this->assertSame('active', $contract->status);
        $confirmations1 = \App\Models\CobrosPaymentConfirmation::count();
        $licenses1 = \App\Models\CobrosCollectorLicense::query()->where('company_id', $company->id)->count();

        // Reintento: cero duplicados
        $this->handler->recoverFromBillingRecord($record);
        $this->handler->recoverFromBillingRecord($record);

        $this->assertSame($confirmations1, \App\Models\CobrosPaymentConfirmation::count());
        $this->assertSame($licenses1, \App\Models\CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, $confirmations1);
        $this->assertSame(1, $licenses1);
    }

    public function test_recover_from_billing_record_respects_company_isolation(): void
    {
        [$companyA, $payerA] = $this->lender('presta.rec.a@iso.test');
        [$companyB, $payerB] = $this->lender('presta.rec.b@iso.test');
        $admin = $this->platformAdmin();

        $contractA = $this->createContractAndConfirmation($companyA, $payerA, $admin);
        $contractB = $this->createContractAndConfirmation($companyB, $payerB, $admin);

        // Registro de empresa A con referencia de contrato de empresa B: no activa B
        $recordForeign = \App\Models\BillingPaymentRecord::create([
            'provider' => 'stripe',
            'external_id' => 'pi_test_rec_foreign_123',
            'product' => 'cobros',
            'company_id' => $companyA->id,
            'contract_reference' => (string) $contractB->id,
            'currency' => 'CRC',
            'amount' => '10000.0000',
            'period_year' => 2026,
            'period_month' => 10,
            'status' => 'confirmed',
            'raw_payload' => null,
            'confirmed_at' => now(),
        ]);

        $this->handler->recoverFromBillingRecord($recordForeign);

        $this->assertSame('draft', $contractB->refresh()->status);
        $this->assertSame(0, \App\Models\CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contractB->id)
            ->count());

        // Registro legítimo de empresa B: activa solo B
        $recordB = \App\Models\BillingPaymentRecord::create([
            'provider' => 'stripe',
            'external_id' => 'pi_test_rec_b_123',
            'product' => 'cobros',
            'company_id' => $companyB->id,
            'contract_reference' => (string) $contractB->id,
            'currency' => 'CRC',
            'amount' => '10000.0000',
            'period_year' => 2026,
            'period_month' => 10,
            'status' => 'confirmed',
            'raw_payload' => null,
            'confirmed_at' => now(),
        ]);

        $this->handler->recoverFromBillingRecord($recordB);

        $this->assertSame('active', $contractB->refresh()->status);
        $this->assertSame('draft', $contractA->refresh()->status);
    }

    public function test_recover_ignores_non_confirmed_or_foreign_product_records(): void
    {
        $pending = \App\Models\BillingPaymentRecord::create([
            'provider' => 'stripe',
            'external_id' => 'pi_test_rec_pending_123',
            'product' => 'cobros',
            'company_id' => 1,
            'contract_reference' => '1',
            'currency' => 'CRC',
            'amount' => '10000.0000',
            'period_year' => 2026,
            'period_month' => 10,
            'status' => 'pending',
            'raw_payload' => null,
            'confirmed_at' => null,
        ]);

        $this->handler->recoverFromBillingRecord($pending);
        $this->assertSame(0, \App\Models\CobrosPaymentConfirmation::count());

        $otherProduct = \App\Models\BillingPaymentRecord::create([
            'provider' => 'stripe',
            'external_id' => 'pi_test_rec_other_123',
            'product' => 'academy',
            'company_id' => 1,
            'contract_reference' => '1',
            'currency' => 'CRC',
            'amount' => '10000.0000',
            'period_year' => 2026,
            'period_month' => 10,
            'status' => 'confirmed',
            'raw_payload' => null,
            'confirmed_at' => now(),
        ]);

        $this->handler->recoverFromBillingRecord($otherProduct);
        $this->assertSame(0, \App\Models\CobrosPaymentConfirmation::count());
    }
}
