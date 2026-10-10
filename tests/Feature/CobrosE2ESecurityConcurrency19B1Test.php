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
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmedHandler;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * BLOQUE 19B-1 — seguridad y concurrencia: pagos repetidos, decisiones en
 * competencia y aislamiento, con servicios productivos reales y pagos
 * simulados (sin llamadas externas ni operaciones reales).
 *
 * Límite documentado: SQLite de desarrollo no ofrece garantías de concurrencia
 * multi-conexión equivalentes a producción, por lo que NO se simula hilos
 * concurrentes reales. Se certifican las transiciones y la idempotencia
 * DETERMINISTAS: los caminos secuenciales de aprobación/rechazo y los
 * reintentos de webhook aplican bloqueo pesimista (lockForUpdate) e
 * idempotencia por transaction_id, de modo que cualquier orden observable
 * termina en un único estado coherente.
 */
class CobrosE2ESecurityConcurrency19B1Test extends TestCase
{
    use RefreshDatabase;

    private CobrosPaymentConfirmationService $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = app(CobrosPaymentConfirmationService::class);
    }

    /** @return array{0: Company, 1: User, 2: User} empresa, pagador, admin Maestro */
    private function context(): array
    {
        $payer = User::factory()->create(['is_active' => true, 'email' => 'pagador.'.uniqid().'@e2e.test']);
        $company = Company::create([
            'trade_name' => '19B1 '.uniqid(),
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

        return [$company, $payer, $admin, $contract];
    }

    private function assert403(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba HTTP 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function assertValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba ValidationException.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    private function stripeEvent(Company $company, CobrosBillingContract $contract, string $externalId): PaymentEvent
    {
        return new PaymentEvent(
            type: PaymentEventTypes::CONFIRMED,
            provider: 'stripe',
            externalId: $externalId,
            product: 'cobros',
            companyId: (string) $company->id,
            contractReference: (string) $contract->id,
            currency: 'CRC',
            amount: '10000.0000',
            periodYear: now()->year,
            periodMonth: now()->month,
        );
    }

    public function test_stripe_webhook_replay_creates_no_new_licenses_and_does_not_extend_validity(): void
    {
        [$company, $payer, $admin, $contract] = $this->context();

        // Borrador vinculado real (mismo patrón del handler productivo 17B-3A).
        $cobradorEmail = 'cobrador.'.uniqid().'@e2e.test';
        $issued = app(\App\Services\Cobros\LaborInvitationService::class)
            ->issue($company, $payer, $cobradorEmail, 1440);
        $draft = CobrosRegistrationDraft::create([
            'email' => $cobradorEmail,
            'payload' => [
                'country_iso' => 'CR',
                'modality' => 'cobrador_vinculado',
                'personal' => ['name' => 'Cobrador', 'email' => $cobradorEmail, 'phone' => '8888-8888'],
                'portfolio_plan' => 'manual',
                'routes' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Ruta', 'code' => 'RN']],
                'labor_invitation' => [
                    'id' => $issued['invitation']->id,
                    'token_hash' => $issued['invitation']->token_hash,
                    'invited_by' => $payer->email,
                    'lender_name' => $payer->name,
                    'company' => $company->trade_name,
                ],
            ],
            'last_saved_at' => now(),
        ]);
        $draft->update(['billing_contract_id' => $contract->id]);

        $handler = app(CobrosPaymentConfirmedHandler::class);
        $event = $this->stripeEvent($company, $contract, 'pi_replay_'.uniqid());

        $handler->handle($event);
        $licenseAfterFirst = CobrosCollectorLicense::query()->where('company_id', $company->id)->get();
        $expiryMapAfterFirst = $licenseAfterFirst->mapWithKeys(fn ($l) => [$l->user_id => $l->expires_at?->getTimestamp()])->all();
        $confirmationAfterFirst = CobrosPaymentConfirmation::query()->sole();
        $this->assertFalse($licenseAfterFirst->isEmpty());

        // Replay del webhook (mismo payment_intent.id): sin nuevas licencias
        // ni extensión de vigencia.
        $handler->handle($event);
        $handler->handle($event);

        $licenses = CobrosCollectorLicense::query()->where('company_id', $company->id)->get();
        $this->assertCount($licenseAfterFirst->count(), $licenses);
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(
            $expiryMapAfterFirst,
            $licenses->mapWithKeys(fn ($l) => [$l->user_id => $l->expires_at?->getTimestamp()])->all()
        );
        $this->assertSame($confirmationAfterFirst->confirmed_at?->getTimestamp(), CobrosPaymentConfirmation::query()->sole()->confirmed_at?->getTimestamp());
        $this->assertNotNull($draft->fresh()->billing_contract_id);
    }

    public function test_double_sinpe_approval_does_not_duplicate_activation(): void
    {
        [, $payer, $admin, $contract] = $this->context();

        $pending = $this->payments->requestSinpe($contract, $payer);
        $approved = $this->payments->approveSinpe($pending, $admin);
        $second = $this->payments->approveSinpe($approved->fresh(), $admin);

        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(1, CobrosCollectorLicense::query()->count());
        $this->assertSame($approved->confirmed_at?->getTimestamp(), $second->confirmed_at?->getTimestamp());
        $license = CobrosCollectorLicense::query()->sole();
        $this->assertSame(now()->startOfMonth()->endOfMonth()->toDateTimeString(), $license->expires_at?->toDateTimeString());
        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_approval_and_rejection_sequences_reach_a_single_coherent_state(): void
    {
        // Orden 1: rechazo decide primero — la aprobación posterior es
        // rechazada por el servicio y el estado original se conserva.
        [, $payerA, $adminA, $contractA] = $this->context();
        $pendingA = $this->payments->requestSinpe($contractA, $payerA);
        $rejected = $this->payments->rejectSinpe($pendingA, $adminA);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_REJECTED, $rejected->status);

        $this->assertValidation(fn () => $this->payments->approveSinpe($rejected->fresh(), $adminA));
        $this->assertSame(CobrosPaymentConfirmation::STATUS_REJECTED, $rejected->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->count());
        $this->assertSame('draft', $contractA->fresh()->status);

        // Orden 2: la aprobación decide primero — el rechazo posterior no
        // puede contradecir ni revertir la activación.
        [, $payerB, $adminB, $contractB] = $this->context();
        $pendingB = $this->payments->requestSinpe($contractB, $payerB);
        $confirmed = $this->payments->approveSinpe($pendingB, $adminB);
        $licenseB = CobrosCollectorLicense::query()->where('company_id', $contractB->company_id)->sole();
        $expiry = $licenseB->expires_at?->getTimestamp();

        $this->assertValidation(fn () => $this->payments->rejectSinpe($confirmed->fresh(), $adminB));
        $fresh = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contractB->id)->sole();
        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $fresh->status);
        $this->assertSame($confirmed->confirmed_at?->getTimestamp(), $fresh->confirmed_at?->getTimestamp());
        $this->assertSame('active', $contractB->fresh()->status);
        $this->assertSame($expiry, CobrosCollectorLicense::query()->where('company_id', $contractB->company_id)->sole()->expires_at?->getTimestamp());
    }

    public function test_foreign_company_and_unauthorized_users_cannot_confirm_payments(): void
    {
        [$companyA, $payerA, $adminA, $contractA] = $this->context();
        $pendingA = $this->payments->requestSinpe($contractA, $payerA);

        // Dueño de OTRA empresa, sin autoridad Maestro.
        $intruder = User::factory()->create(['is_active' => true, 'email' => 'ajeno.'.uniqid().'@e2e.test']);
        $companyB = Company::create([
            'trade_name' => '19B1-B '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $intruder->id,
        ]);

        // Contrato de otra empresa: no se aprueba, rechaza ni renueva.
        $this->assert403(fn () => $this->payments->approveSinpe($pendingA, $intruder));
        $this->assert403(fn () => $this->payments->rejectSinpe($pendingA, $intruder));
        $this->assert403(fn () => $this->payments->prepareRenewal($contractA, $intruder));
        $this->assert403(fn () => $this->payments->requestSinpe($contractA, $intruder));

        // Ni con permisos Maestro falsos en su propia empresa.
        $this->assertFalse($intruder->isPlatformAdmin());

        // Estados intactos tras los intentos.
        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pendingA->fresh()->status);
        $this->assertSame('draft', $contractA->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->count());

        // Confirmación interna reservada al mecanismo autorizado (plataforma).
        $this->assert403(fn () => $this->payments->confirmInternal(
            $contractA, $intruder, 'int_'.uniqid(), '10000.0000', now()->year, now()->month
        ));

        // El pagador legítimo tampoco se autoconfirma fuera del flujo autorizado.
        $this->assert403(fn () => $this->payments->approveSinpe($pendingA, $payerA));
        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pendingA->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->count());
        $this->assertSame('draft', $contractA->fresh()->status);
    }

    public function test_rejection_preserves_audit_trail_and_original_state(): void
    {
        [, $payer, $admin, $contract] = $this->context();
        $pending = $this->payments->requestSinpe($contract, $payer);
        $notesBefore = $pending->notes;

        $rejected = $this->payments->rejectSinpe($pending, $admin, 'Comprobante no verificable.');

        $this->assertSame(CobrosPaymentConfirmation::STATUS_REJECTED, $rejected->status);
        $this->assertSame($admin->id, $rejected->confirmed_by_user_id);
        $this->assertNotNull($rejected->confirmed_at);
        $this->assertNotNull($rejected->notes);
        $this->assertStringContainsString('Comprobante no verificable.', $rejected->notes);
        if ($notesBefore !== null) {
            $this->assertStringContainsString($notesBefore, $rejected->notes);
        }

        // Estado original del negocio: sin licencias, contrato sin activar y
        // sin configuración de licenciamiento aplicada por un rechazo.
        $this->assertSame(0, CobrosCollectorLicense::query()->count());
        $this->assertSame('draft', $contract->fresh()->status);
        $this->assertNull(app(\App\Services\Cobros\CollectorLicenseService::class)->contract($contract->company)['license_mode'] ?? null);
    }
}
