<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * BLOQUE 17B-5B — aprobación/rechazo manual SINPE (servicio interno Panel Maestro).
 *
 * Cubre: aprobación válida con activación única, rechazo auditable sin
 * activación, doble aprobación idempotente y autorización explícita de
 * Panel Maestro (platform admin) — no permisos ordinarios CobrOS.
 */
class CobrosSinpeApproval17B5BTest extends TestCase
{
    use RefreshDatabase;

    private CobrosPaymentConfirmationService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = app(CobrosPaymentConfirmationService::class);
    }

    /** @return array{0: Company, 1: User, 2: CobrosBillingContract} Empresa + pagador (dueño) + contrato individual. */
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

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    private function pendingSinpe(CobrosBillingContract $contract, User $payer): CobrosPaymentConfirmation
    {
        return $this->payments->requestSinpe($contract, $payer);
    }

    public function test_platform_admin_approves_pending_sinpe_and_activates(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();
        $pending = $this->pendingSinpe($contract, $payer);

        $approved = $this->payments->approveSinpe($pending, $admin, 'Verificado manualmente (conciliación externa).');

        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $approved->status);
        $this->assertSame('sinpe', $approved->provider);
        $this->assertSame($admin->id, $approved->confirmed_by_user_id);
        $this->assertNotNull($approved->confirmed_at);
        $this->assertStringContainsString('Verificado manualmente', (string) $approved->notes);
        $this->assertSame('10000.0000', (string) $approved->amount);
        $this->assertSame('CRC', $approved->currency);

        // Activación vía servicio existente: contrato activo + licencia única.
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);
        $license = CobrosCollectorLicense::query()
            ->where('company_id', $company->id)
            ->where('user_id', $payer->id)
            ->first();
        $this->assertNotNull($license);
        $this->assertSame(CobrosCollectorLicense::STATUS_ACTIVE, $license->status);
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    public function test_rejection_is_auditable_and_never_activates(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();
        $pending = $this->pendingSinpe($contract, $payer);

        $rejected = $this->payments->rejectSinpe($pending, $admin, 'Comprobante no coincide con la referencia.');

        $this->assertSame(CobrosPaymentConfirmation::STATUS_REJECTED, $rejected->status);
        $this->assertSame($admin->id, $rejected->confirmed_by_user_id);
        $this->assertNotNull($rejected->confirmed_at);
        $this->assertStringContainsString('no coincide', (string) $rejected->notes);

        // Sin borrado, sin activación, sin licencias.
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame('draft', $contract->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    public function test_double_approval_is_idempotent_without_duplicate_licenses(): void
    {
        [$company, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();
        $pending = $this->pendingSinpe($contract, $payer);

        $first = $this->payments->approveSinpe($pending, $admin);
        $second = $this->payments->approveSinpe($first, $admin);

        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $second->status);
        $this->assertSame($first->confirmed_at?->getTimestamp(), $second->confirmed_at?->getTimestamp());
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);
        $this->assertSame(1, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    public function test_approval_and_rejection_require_maestro_authorization(): void
    {
        [, $payer, $contract] = $this->company();
        $pending = $this->pendingSinpe($contract, $payer);
        // Usuario ordinario del tenant (dueño/pagador): sin autorización Maestro.
        $ordinary = $payer;

        try {
            $this->payments->approveSinpe($pending, $ordinary);
            $this->fail('Se esperaba 403 de Panel Maestro en aprobación.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $this->payments->rejectSinpe($pending, $ordinary);
            $this->fail('Se esperaba 403 de Panel Maestro en rechazo.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // La solicitud sigue intacta y sin decidir.
        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->confirmed_by_user_id);
        $this->assertNull($pending->fresh()->confirmed_at);
    }

    public function test_non_sinpe_or_non_pending_requests_are_rejected(): void
    {
        [, $payer, $contract] = $this->company();
        $admin = $this->platformAdmin();
        $pending = $this->pendingSinpe($contract, $payer);

        $pending->update(['provider' => 'internal']);

        try {
            $this->payments->approveSinpe($pending->fresh(), $admin);
            $this->fail('Se esperaba ValidationException para proveedor distinto de sinpe.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('provider', $e->errors());
        }

        $pending->update(['provider' => 'sinpe', 'status' => CobrosPaymentConfirmation::STATUS_EXPIRED]);

        try {
            $this->payments->approveSinpe($pending->fresh(), $admin);
            $this->fail('Se esperaba ValidationException para solicitud sinpe no pendiente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }
}
