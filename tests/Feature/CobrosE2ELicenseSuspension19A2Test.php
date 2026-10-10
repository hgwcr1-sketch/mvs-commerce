<?php

namespace Tests\Feature;

use App\Http\Middleware\Cobros\EnsureActiveCompany;
use App\Models\Branch;
use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteLicense;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CollectorManagementService;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 19A-2 — certificación E2E de suspensiones según el responsable real
 * del pago (SINPE simulado, datos locales, sin llamadas externas).
 *
 * A. Prestamista paga licencias consolidadas (contrato empresarial/cupo):
 *    vencida la licencia que cubre la ruta de los cobradores, se bloquea
 *    únicamente al cobrador de esa ruta (URL directa incluida) y el
 *    prestamista conserva «Mi membresía» para regularizar.
 * B. Cobrador paga individualmente: su licencia vencida bloquea únicamente
 *    sus operaciones; no suspende al prestamista ni a otros cobradores al día.
 * C. Prestamista con administración vencida (admin cobrada): se restringe su
 *    acceso administrativo según contrato; no se suspenden licencias
 *    independientes pagadas y vigentes.
 */
class CobrosE2ELicenseSuspension19A2Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User} empresa, dueño/pagador, admin Maestro */
    private function context(bool $ownerWithPortal = true): array
    {
        $company = Company::create([
            'trade_name' => '19A2 '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'A'.random_int(10, 99), 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $owner = User::factory()->create(['is_active' => true, 'email' => 'dueno.'.uniqid().'@e2e.test']);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueno '.uniqid(), 'is_active' => true]);
        $names = $ownerWithPortal ? ['cobros.portal_dueno', 'cobros.acceder'] : ['cobros.acceder'];
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);

        return [$company, $owner, $admin];
    }

    private function patchContractMode(Company $company, User $admin, string $mode, bool $adminCharged): void
    {
        $payload = ['license_mode' => $mode, 'admin_license_charged' => $adminCharged];
        if ($mode === 'company_cupo') {
            $payload['collector_limit'] = 2;
        }

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), $payload)
            ->assertSessionHasNoErrors();
        $company->refresh();
    }

    private function createCollector(Company $company, User $admin, string $prefix): User
    {
        return app(CollectorManagementService::class)->createCollector(
            $company,
            ['email' => $prefix.'.'.uniqid().'@e2e.test', 'name' => 'Cobrador '.$prefix],
            $admin,
            true,
        )['user'];
    }

    private function assertGenericSuspension($response): void
    {
        $response->assertForbidden();
        $response->assertSee(EnsureActiveCompany::SUSPENDED_MESSAGE);
        $response->assertDontSee('CRC');
        $response->assertDontSee('10000');
    }

    public function test_a_consolidated_payment_lapse_blocks_only_covered_collectors(): void
    {
        [$company, $owner, $admin] = $this->context();

        // Prestamista paga licencias consolidadas: contrato empresarial con
        // cupos, activado por SINPE aprobado por Maestro.
        $contracts = app(CobrosBillingContractService::class);
        $contract = $contracts->createEnterprise(
            $company, 2, 'CR', $owner, now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
        $confirmation = app(CobrosPaymentConfirmationService::class)->requestSinpe($contract, $owner);
        app(CobrosPaymentConfirmationService::class)->approveSinpe($confirmation, $admin);
        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame('company_cupo', app(CollectorLicenseService::class)->contract($company)['license_mode'] ?? null);

        // Cobradores cubiertos por los cupos (sin licencias individuales).
        $collectorA = $this->createCollector($company, $admin, 'cubierto');
        $collectorB = $this->createCollector($company, $admin, 'aldia');
        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());

        // Rutas con licencia consolidada por ruta: la de A queda vencida
        // (el pago que la cubría venció); la de B sigue vigente.
        $routeA = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta A', 'active' => true]);
        $routeA->users()->attach($collectorA->id, ['active' => true]);
        CobrosRouteLicense::create([
            'company_id' => $company->id,
            'cobros_route_id' => $routeA->id,
            'status' => CobrosCollectorLicense::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subDay(),
        ]);
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta B', 'active' => true]);
        $routeB->users()->attach($collectorB->id, ['active' => true]);
        CobrosRouteLicense::create([
            'company_id' => $company->id,
            'cobros_route_id' => $routeB->id,
            'status' => CobrosCollectorLicense::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        // Bloqueo por URL directa: solo el cobrador de la ruta vencida.
        $this->assertGenericSuspension(
            $this->actingAs($collectorA)
                ->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $routeA->id])
                ->get(route('cobros.index'))
        );

        $this->actingAs($collectorB)
            ->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $routeB->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.collector.index'));

        // El prestamista no depende de la licencia de una ruta.
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.owner.dashboard'));

        // Y conserva «Mi membresía» (puerta de pago) para regularizar.
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk();
    }

    public function test_b_expired_individual_collector_license_blocks_only_that_collector(): void
    {
        [$company, $owner, $admin] = $this->context();
        $this->patchContractMode($company, $admin, 'individual', false);

        $collectorA = $this->createCollector($company, $admin, 'ind-a');
        $collectorB = $this->createCollector($company, $admin, 'ind-b');
        $this->assertSame(2, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());

        // Su licencia individual vencida (pagó individualmente y venció).
        CobrosCollectorLicense::query()->where('user_id', $collectorA->id)->sole()->update([
            'expires_at' => now()->subDay(),
        ]);

        // Bloqueo por URL directa: únicamente sus operaciones.
        $this->assertGenericSuspension(
            $this->actingAs($collectorA)
                ->withSession(['active_company_id' => $company->id])
                ->get(route('cobros.index'))
        );

        // No suspende a otros cobradores al día.
        $this->actingAs($collectorB)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.collector.index'));
        $this->assertSame(
            CobrosCollectorLicense::STATE_ACTIVE,
            app(CollectorLicenseService::class)->stateOf(
                CobrosCollectorLicense::query()->where('user_id', $collectorB->id)->sole(),
                $company
            )
        );

        // Ni al prestamista (dueño gratuito, sin licencia requerida).
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.owner.dashboard'));
    }

    public function test_c_overdue_admin_access_is_restricted_without_touching_independent_licenses(): void
    {
        [$company, $owner, $admin] = $this->context();
        $contracts = app(CobrosBillingContractService::class);
        $contract = $contracts->createIndividual(
            $company, 'prestamista', 'CR', $owner, now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        // Administración cobrada según contrato: el dueño requiere licencia propia.
        $this->patchContractMode($company, $admin, 'individual', true);
        $licenses = app(CollectorLicenseService::class);
        $licenses->renew($owner, $company, now()->addDays(30));

        // Licencia independiente pagada y vigente de un cobrador.
        $collector = $this->createCollector($company, $admin, 'independiente');

        // Administración vencida: el dueño pierde el acceso administrativo
        // (URL directa) pero conserva la puerta de membresía para regularizar.
        CobrosCollectorLicense::query()->where('user_id', $owner->id)->sole()->update([
            'expires_at' => now()->subDay(),
        ]);

        $this->assertGenericSuspension(
            $this->actingAs($owner)
                ->withSession(['active_company_id' => $company->id])
                ->get(route('cobros.index'))
        );
        $this->assertGenericSuspension(
            $this->actingAs($owner)
                ->withSession(['active_company_id' => $company->id])
                ->get(route('cobros.owner.dashboard'))
        );
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk();

        // La licencia independiente pagada y vigente NO se suspende.
        $this->actingAs($collector)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))
            ->assertRedirect(route('cobros.collector.index'));
        $this->assertSame(
            CobrosCollectorLicense::STATE_ACTIVE,
            $licenses->stateOf(CobrosCollectorLicense::query()->where('user_id', $collector->id)->sole(), $company)
        );
    }
}
