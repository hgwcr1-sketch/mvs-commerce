<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\CobrosPricingService;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 19A-1 — certificación E2E local del recorrido completo CobrOS.
 *
 * Registro público del prestamista -> contrato individual pendiente ->
 * cotización por precios internos -> solicitud SINPE -> aprobación Maestro ->
 * activación de contrato, módulo y licencia -> acceso operativo -> segunda
 * aprobación idempotente. Proveedores simulados; sin llamadas externas.
 */
class CobrosCertificationE2E19ATest extends TestCase
{
    use RefreshDatabase;

    private CobrosBillingContractService $contracts;

    private CobrosPaymentConfirmationService $payments;

    private CobrosPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = app(CobrosBillingContractService::class);
        $this->payments = app(CobrosPaymentConfirmationService::class);
        $this->pricing = app(CobrosPricingService::class);
    }

    public function test_full_journey_registration_quote_sinpe_approval_activation_and_operational_access(): void
    {
        $email = 'dueno.e2e.'.uniqid().'@cobros.test';

        // 1) Registro público del prestamista (wizard real por HTTP, en sesión).
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR', 'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Dueño E2E', 'email' => $email, 'phone' => '8888-0000',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        // Registro sin acceso todavía: borrador persistido, cero entidades operativas.
        $this->assertGuest();
        $this->assertSame(1, \App\Models\CobrosRegistrationDraft::where('email', $email)->count());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('cobros_collector_licenses', 0);

        // 2) Contexto local: empresa, sucursal, dueño/pagador y admin Maestro.
        [$company, $branch, $payer, $maestro] = $this->context($email);

        // 3) Contrato individual pendiente (draft) creado por Panel Maestro.
        $contract = $this->contracts->createIndividual(
            $company, 'prestamista', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $maestro,
        );
        $this->assertSame(CobrosBillingContract::STATUSES[0], $contract->status);
        $this->assertSame($payer->id, $contract->payer_user_id);

        // 4) Cotización desde precios internos (nunca del navegador).
        $quote = $this->pricing->quoteForContract($contract);
        $this->assertSame('10000.0000', $quote->monthlyTotal);
        $this->assertSame('CRC', $quote->currency);
        $this->assertSame('10000.0000', $this->contracts->monthlyTotal($contract));

        // 5) Solicitud de pago SINPE por la ruta real, con payload hostil.
        $payerSession = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
        $this->actingAs($payer)->withSession($payerSession)
            ->post(route('cobros.payment.sinpe.report', $contract), [
                'amount' => '1.00', 'currency' => 'USD', 'status' => 'confirmed',
                'confirmed_at' => now()->toIso8601String(), 'transaction_id' => 'FAKE',
            ])->assertRedirect(route('cobros.payment.methods', $contract));

        $pending = CobrosPaymentConfirmation::query()->sole();
        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pending->status);
        $this->assertSame('sinpe', $pending->provider);
        $this->assertSame('CRC', $pending->currency);
        $this->assertSame('10000.0000', (string) $pending->amount);
        $this->assertStringStartsWith('SINPE-', $pending->transaction_id);
        $this->assertSame('draft', $contract->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->count());

        // 6) Aprobación por administrador Maestro autorizado.
        $approved = $this->payments->approveSinpe(
            $pending, $maestro, 'Verificado manualmente en conciliación local.',
        );

        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $approved->status);
        $this->assertSame($maestro->id, $approved->confirmed_by_user_id);

        // Activación: contrato, módulo CobrOS y licencia individual del pagador.
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);
        $this->assertTrue($company->fresh()->isModuleEnabled('cobros'));
        $license = CobrosCollectorLicense::query()->sole();
        $this->assertSame($company->id, $license->company_id);
        $this->assertSame($payer->id, $license->user_id);
        $this->assertSame(CobrosCollectorLicense::STATUS_ACTIVE, $license->status);
        $this->assertTrue($company->license()->first()->isOperable());

        // 7) Acceso operativo después de la activación.
        $this->actingAs($payer)->withSession($payerSession)
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.owner.dashboard'));
        $this->actingAs($payer)->withSession($payerSession)
            ->get(route('cobros.owner.dashboard'))
            ->assertOk();
        $this->actingAs($payer)->withSession($payerSession)
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk();

        // 8) Segunda aprobación: idempotente, sin duplicar licencia ni período.
        $again = $this->payments->approveSinpe($approved->fresh(), $maestro);

        $this->assertSame($approved->confirmed_at?->getTimestamp(), $again->confirmed_at?->getTimestamp());
        $this->assertSame(1, CobrosPaymentConfirmation::query()->count());
        $this->assertSame(1, CobrosCollectorLicense::query()->count());
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);
    }

    public function test_company_isolation_and_unauthorized_users_are_rejected(): void
    {
        $emailA = 'dueno.a.'.uniqid().'@cobros.test';
        [$companyA, $branchA, $payerA, $maestro] = $this->context($emailA);
        [$companyB, , $payerB] = $this->context('dueno.b.'.uniqid().'@cobros.test');

        $contractA = $this->contracts->createIndividual(
            $companyA, 'prestamista', 'CR', $payerA,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $maestro,
        );
        $contractB = $this->contracts->createIndividual(
            $companyB, 'prestamista', 'CR', $payerB,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $maestro,
        );

        $sessionA = ['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id];

        // Aislamiento: el pagador de A no ve ni paga el contrato de B.
        $this->actingAs($payerA)->withSession($sessionA)
            ->get(route('cobros.payment.methods', $contractB))
            ->assertNotFound();
        $this->actingAs($payerA)->withSession($sessionA)
            ->post(route('cobros.payment.sinpe.report', $contractB))
            ->assertNotFound();
        $this->assertSame(0, CobrosPaymentConfirmation::query()->count());

        // Usuario sin permiso cobros.acceder: 403 en la puerta de pago.
        $stranger = $this->member($companyA, []);
        $this->actingAs($stranger)->withSession($sessionA)
            ->post(route('cobros.payment.sinpe.report', $contractA))
            ->assertForbidden();

        // No pagador del contrato: 403 aunque tenga el permiso.
        $otherMember = $this->member($companyA, ['cobros.acceder']);
        $this->actingAs($otherMember)->withSession($sessionA)
            ->post(route('cobros.payment.sinpe.report', $contractA))
            ->assertForbidden();
        $this->assertSame(0, CobrosPaymentConfirmation::query()->count());

        // Solicitud legítima del pagador de A.
        $this->actingAs($payerA)->withSession($sessionA)
            ->post(route('cobros.payment.sinpe.report', $contractA))
            ->assertRedirect(route('cobros.payment.methods', $contractA));
        $pending = CobrosPaymentConfirmation::query()->sole();

        // Aprobación requiere autorización Maestro explícita.
        try {
            $this->payments->approveSinpe($pending, $payerA);
            $this->fail('La aprobación de un usuario ordinario debió bloquearse.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $this->payments->rejectSinpe($pending, $otherMember);
            $this->fail('El rechazo de un usuario ordinario debió bloquearse.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame('draft', $contractA->fresh()->status);
        $this->assertSame('draft', $contractB->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->count());
    }

    /**
     * @return array{0: Company, 1: Branch, 2: User, 3: User} Empresa + sucursal + dueño/pagador + Maestro.
     */
    private function context(string $ownerEmail): array
    {
        $owner = User::factory()->create(['is_active' => true, 'email' => $ownerEmail]);
        $company = Company::create([
            'trade_name' => 'Prestamista '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $owner->id,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id, 'name' => 'Principal',
            'code' => 'P'.uniqid(), 'is_active' => true,
        ]);

        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.portal_dueno'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);
        $owner->branches()->attach($branch->id);

        app(CompanyCashSettingsProvisioner::class)->provision($company);

        $maestro = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        return [$company, $branch, $owner, $maestro];
    }

    /** @return array{0: Company, 1: User} Usuario nuevo en la empresa con los permisos indicados. */
    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }
}
