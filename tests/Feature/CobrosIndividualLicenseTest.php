<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CobrosCollectorLicense;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CollectorManagementService;
use App\Services\CompanyLicenseService;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licenciamiento comercial real de CobrOS (Bloque 1).
 *
 * Modalidad individual: licencia propia por cobrador (estado, inicio,
 * vencimiento, suspensión, renovación) y sin licencia vigente no se opera.
 * Modalidad empresa/cupo: contrato de empresa + cupo, sin licencias
 * individuales; el dueño gratis no consume cupo y el cobrado sí.
 */
class CobrosIndividualLicenseTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | Modalidad individual: activa / vencida / suspendida / renovada
     | ------------------------------------------------------------------- */

    public function test_collector_with_active_individual_license_can_log_in(): void
    {
        [$company, $admin] = $this->context();
        $this->setContract($company, $admin, 'individual', null, false);
        [$collector, $plain] = $this->createCollector($company, $admin);

        $license = CobrosCollectorLicense::query()
            ->where('company_id', $company->id)->where('user_id', $collector->id)->first();
        $this->assertNotNull($license, 'El alta en modalidad individual otorga licencia propia.');
        $this->assertSame('active', $license->status);
        $this->assertNotNull($license->expires_at);

        $this->post(route('cobros.login.store'), ['email' => $collector->email, 'password' => $plain])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($collector);
    }

    public function test_expired_license_blocks_login_and_portal_until_renewed(): void
    {
        [$company, $admin] = $this->context();
        $this->setContract($company, $admin, 'individual', null, false);
        [$collector, $plain] = $this->createCollector($company, $admin);

        CobrosCollectorLicense::query()
            ->where('company_id', $company->id)->where('user_id', $collector->id)
            ->update(['expires_at' => now()->subDay()]);

        // Login bloqueado con motivo visible.
        $this->post(route('cobros.login.store'), ['email' => $collector->email, 'password' => $plain])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // Acceso directo al portal también bloqueado (403 con motivo).
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))->assertForbidden();

        // Renovación desde el Panel Maestro => vuelve a operar.
        $this->platformCall('post', route('platform.collectors.license.renew', [$company, $collector]), $admin);

        $this->post(route('cobros.login.store'), ['email' => $collector->email, 'password' => $plain])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($collector);
    }

    public function test_suspended_license_blocks_access_and_resume_restores_it(): void
    {
        [$company, $admin] = $this->context();
        $this->setContract($company, $admin, 'individual', null, false);
        [$collector, $plain] = $this->createCollector($company, $admin);

        $this->platformCall('patch', route('platform.collectors.license.suspend', [$company, $collector]), $admin);

        $this->post(route('cobros.login.store'), ['email' => $collector->email, 'password' => $plain])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->platformCall('patch', route('platform.collectors.license.resume', [$company, $collector]), $admin);

        $this->post(route('cobros.login.store'), ['email' => $collector->email, 'password' => $plain])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($collector);
    }

    /* ---------------------------------------------------------------------
     | Modalidad empresa/cupo: sin licencias individuales, cupo y dueño
     | ------------------------------------------------------------------- */

    public function test_company_cupo_mode_does_not_create_individual_licenses(): void
    {
        [$company, $admin] = $this->context();
        $this->setContract($company, $admin, 'company_cupo', 3, false);
        [$collector] = $this->createCollector($company, $admin);

        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
        $service = app(CollectorManagementService::class);
        $this->assertTrue($service->canCreateCollector($company));
        $this->assertSame(2, $service->getRemainingSlots($company)); // 3 − 1 cobrador
        $this->assertNotNull($collector);
    }

    public function test_free_admin_does_not_consume_cupo_but_charged_admin_does(): void
    {
        // Dueño gratis: el cupo queda entero para cobradores.
        [$company, $admin, $owner] = $this->context();
        $this->setContract($company, $admin, 'company_cupo', 1, false);
        $service = app(CollectorManagementService::class);
        $this->assertTrue($service->canCreateCollector($company), 'Dueño gratis no consume cupo.');
        $this->assertSame(1, $service->getRemainingSlots($company));

        // Dueño cobrado: ocupa un asiento del contrato.
        $this->setContract($company, $admin, 'company_cupo', 1, true);
        $this->assertSame(1, $service->seatUsage($company));
        $this->assertFalse($service->canCreateCollector($company), 'Dueño cobrado consume cupo.');
        $this->assertSame(0, $service->getRemainingSlots($company));
        $this->assertNotNull($owner);
    }

    public function test_charged_admin_in_individual_mode_requires_own_license(): void
    {
        [$company, $admin, $owner] = $this->context();
        $this->setContract($company, $admin, 'individual', null, true);

        $password = 'OwnerPass123';
        $owner->update(['password' => $password]);

        // Sin licencia propia el dueño cobrado no inicia sesión.
        $this->post(route('cobros.login.store'), ['email' => $owner->email, 'password' => $password])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // Con licencia otorgada, sí opera.
        $this->platformCall('post', route('platform.collectors.license.renew', [$company, $owner]), $admin);

        $this->post(route('cobros.login.store'), ['email' => $owner->email, 'password' => $password])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_free_admin_in_individual_mode_operates_without_own_license(): void
    {
        [$company, $admin, $owner] = $this->context();
        $this->setContract($company, $admin, 'individual', null, false);

        $password = 'OwnerPass123';
        $owner->update(['password' => $password]);

        $this->post(route('cobros.login.store'), ['email' => $owner->email, 'password' => $password])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($owner);
        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    /* ---------------------------------------------------------------------
     | Cambio de modalidad y acceso
     | ------------------------------------------------------------------- */

    public function test_switching_mode_revalidates_access_automatically(): void
    {
        [$company, $admin] = $this->context();

        // Empresas/cupo: el cobrador opera sin licencia individual.
        $this->setContract($company, $admin, 'company_cupo', 5, false);
        [$collector, $plain] = $this->createCollector($company, $admin);
        $this->post(route('cobros.login.store'), ['email' => $collector->email, 'password' => $plain])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($collector);
        // Cambio forzado para que las consultas al portal no redirijan.
        $this->post(route('cobros.password.force.store'), [
            'password' => 'NuevaClave7',
            'password_confirmation' => 'NuevaClave7',
        ])->assertRedirect();
        $this->post(route('cobros.logout'));

        // Cambio a individual: exige licencia propia => queda bloqueado.
        $this->setContract($company, $admin, 'individual', null, false);
        $this->assertNull(
            CobrosCollectorLicense::query()->where('company_id', $company->id)->where('user_id', $collector->id)->first()
        );
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))->assertForbidden();

        // Otorgada la licencia, vuelve a operar.
        $this->platformCall('post', route('platform.collectors.license.renew', [$company, $collector]), $admin);
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));

        // Vuelta a empresa/cupo: la licencia deja de condicionar el acceso.
        $this->setContract($company, $admin, 'company_cupo', 5, false);
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
    }

    public function test_panel_maestro_shows_mode_contract_and_license_state(): void
    {
        [$company, $admin] = $this->context();
        $this->setContract($company, $admin, 'individual', null, false);
        [$collector] = $this->createCollector($company, $admin);

        $this->actingAs($admin)->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Contrato CobrOS', false)
            ->assertSee('Licencias individuales', false)
            ->assertSee('Vigencia:', false)
            ->assertSee('Renovar +1 año', false);

        $licenseGate = app(CollectorLicenseService::class);
        $license = $licenseGate->licenseFor($collector, $company);
        $this->assertSame('active', $licenseGate->stateOf($license, $company));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /** @return array{0: Company, 1: User, 2: User} empresa, platform admin, dueño */
    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'Licencias '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.random_int(10, 99), 'is_active' => true]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);

        $owner = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño '.uniqid(), 'is_active' => true]);
        foreach (['cobros.portal_dueno', 'cobros.acceder'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);

        return [$company, $admin, $owner];
    }

    private function setContract(Company $company, User $admin, string $mode, ?int $limit, bool $adminCharged): void
    {
        $payload = [
            'license_mode' => $mode,
            'admin_license_charged' => $adminCharged,
        ];
        if ($mode === 'company_cupo') {
            $payload['collector_limit'] = $limit;
        }

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), $payload)
            ->assertSessionHasNoErrors();

        $company->refresh();
        // El actingAs del Panel Maestro no debe filtrarse al login CobrOS.
        $this->app['auth']->forgetGuards();
    }

    /** Llamada autenticada al Panel Maestro que no deja usuario persistido. */
    private function platformCall(string $method, string $uri, User $admin, array $data = []): void
    {
        $this->actingAs($admin)->call($method, $uri, $data)->assertRedirect();
        $this->app['auth']->forgetGuards();
    }

    /** @return array{0: User, 1: string} cobrador y contraseña temporal */
    private function createCollector(Company $company, User $admin): array
    {
        $result = app(CollectorManagementService::class)->createCollector($company, [
            'name' => 'Cobrador '.uniqid(),
            'email' => 'cob'.uniqid().'@test.test',
            'password' => 'TempPass123',
        ], $admin, true);

        return [$result['user'], $result['plain_password']];
    }
}
