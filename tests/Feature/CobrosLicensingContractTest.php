<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorManagementService;
use App\Services\CompanyCashSettingsProvisioner;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CobrosLicensingContractTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | Migration: campos nuevos existen y son nullable
     | --------------------------------------------------------------------- */

    public function test_company_modules_has_cobros_contract_columns(): void
    {
        Schema::hasColumns('company_modules', [
            'license_mode', 'collector_limit', 'admin_license_charged',
        ]) ? $this->assertTrue(true) : $this->fail('Missing columns');
    }

    public function test_existing_module_rows_have_null_contract_fields(): void
    {
        [$company] = $this->setupCompany();
        $module = $company->modules()->create(['module_key' => 'sales', 'is_enabled' => true]);
        $module->refresh();

        $this->assertNull($module->license_mode);
        $this->assertNull($module->collector_limit);
        $this->assertNull($module->admin_license_charged);
    }

    /* ---------------------------------------------------------------------
     | Platform Admin: crear contrato individual
     | --------------------------------------------------------------------- */

    public function test_platform_admin_can_set_individual_mode(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])->assertRedirect();

        $module = $company->modules()->where('module_key', 'cobros')->first();
        $this->assertNotNull($module);
        $this->assertTrue($module->is_enabled);
        $this->assertSame('individual', $module->license_mode);
        $this->assertNull($module->collector_limit);
        $this->assertFalse($module->admin_license_charged);
    }

    public function test_individual_mode_clears_collector_limit(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 5,
                'admin_license_charged' => true,
            ])->assertRedirect();

        $module = $company->modules()->where('module_key', 'cobros')->first();
        $this->assertSame('company_cupo', $module->license_mode);
        $this->assertSame(5, $module->collector_limit);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])->assertRedirect();

        $module->refresh();
        $this->assertSame('individual', $module->license_mode);
        $this->assertNull($module->collector_limit);
        $this->assertFalse($module->admin_license_charged);
    }

    /* ---------------------------------------------------------------------
     | Platform Admin: crear contrato company_cupo
     | --------------------------------------------------------------------- */

    public function test_platform_admin_can_set_company_cupo_mode(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 10,
                'admin_license_charged' => true,
            ])->assertRedirect();

        $module = $company->modules()->where('module_key', 'cobros')->first();
        $this->assertNotNull($module);
        $this->assertTrue($module->is_enabled);
        $this->assertSame('company_cupo', $module->license_mode);
        $this->assertSame(10, $module->collector_limit);
        $this->assertTrue($module->admin_license_charged);
    }

    public function test_company_cupo_requires_collector_limit(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'admin_license_charged' => true,
            ])->assertSessionHasErrors('collector_limit');
    }

    public function test_company_cupo_collector_limit_must_be_at_least_1(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 0,
                'admin_license_charged' => true,
            ])->assertSessionHasErrors('collector_limit');
    }

    /* ---------------------------------------------------------------------
     | Validación de modalidad
     | --------------------------------------------------------------------- */

    public function test_invalid_license_mode_is_rejected(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'invalid_mode',
                'admin_license_charged' => true,
            ])->assertSessionHasErrors('license_mode');
    }

    public function test_missing_license_mode_is_rejected(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'admin_license_charged' => true,
            ])->assertSessionHasErrors('license_mode');
    }

    public function test_missing_admin_license_charged_is_rejected(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
            ])->assertSessionHasErrors('admin_license_charged');
    }

    /* ---------------------------------------------------------------------
     | Autorización: solo Platform Admin
     | --------------------------------------------------------------------- */

    public function test_tenant_user_cannot_update_cobros_contract(): void
    {
        [$company, $admin, $tenantUser] = $this->setupCompany(true);

        $this->actingAs($tenantUser)
            ->withSession(['active_company_id' => $company->id])
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])->assertForbidden();
    }

    public function test_guest_cannot_update_cobros_contract(): void
    {
        [$company] = $this->setupCompany();

        $this->patch(route('platform.cobros-contract.update', $company), [
            'license_mode' => 'individual',
            'admin_license_charged' => false,
        ])->assertRedirect();
    }

    /* ---------------------------------------------------------------------
     | Auditoría: evento registrado
     | --------------------------------------------------------------------- */

    public function test_cobros_contract_update_creates_audit_event(): void
    {
        [$company, $admin] = $this->setupCompany();
        $license = app(CompanyLicenseService::class)->ensure($company);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 3,
                'admin_license_charged' => true,
            ])->assertRedirect();

        $license->refresh();
        $event = $license->events()->where('action', 'cobros_contract')->latest()->first();
        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame($license->status, $event->from_status);
        $this->assertSame($license->status, $event->to_status);
        $this->assertArrayHasKey('cobros_contract', $event->snapshot);
        $this->assertSame('company_cupo', $event->snapshot['cobros_contract']['license_mode']);
        $this->assertSame(3, $event->snapshot['cobros_contract']['collector_limit']);
        $this->assertTrue($event->snapshot['cobros_contract']['admin_license_charged']);
    }

    /* ---------------------------------------------------------------------
     | Aislamiento multiempresa
     | --------------------------------------------------------------------- */

    public function test_cobros_contract_is_isolated_between_companies(): void
    {
        [$companyA, $adminA] = $this->setupCompany(false, 'Alpha');
        [$companyB, $adminB] = $this->setupCompany(false, 'Beta');

        $this->actingAs($adminA)
            ->patch(route('platform.cobros-contract.update', $companyA), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])->assertRedirect();

        $this->actingAs($adminB)
            ->patch(route('platform.cobros-contract.update', $companyB), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 5,
                'admin_license_charged' => true,
            ])->assertRedirect();

        $moduleA = $companyA->modules()->where('module_key', 'cobros')->first();
        $moduleB = $companyB->modules()->where('module_key', 'cobros')->first();

        $this->assertSame('individual', $moduleA->license_mode);
        $this->assertNull($moduleA->collector_limit);
        $this->assertFalse($moduleA->admin_license_charged);

        $this->assertSame('company_cupo', $moduleB->license_mode);
        $this->assertSame(5, $moduleB->collector_limit);
        $this->assertTrue($moduleB->admin_license_charged);
    }

    /* ---------------------------------------------------------------------
     | getCobrosContract
     | --------------------------------------------------------------------- */

    public function test_get_cobros_contract_returns_null_when_not_configured(): void
    {
        [$company] = $this->setupCompany();
        $service = app(CompanyLicenseService::class);

        $this->assertNull($service->getCobrosContract($company));
    }

    public function test_get_cobros_contract_returns_data_when_configured(): void
    {
        [$company, $admin] = $this->setupCompany();
        $service = app(CompanyLicenseService::class);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 7,
                'admin_license_charged' => true,
            ]);

        $contract = $service->getCobrosContract($company);
        $this->assertNotNull($contract);
        $this->assertSame('company_cupo', $contract['license_mode']);
        $this->assertSame(7, $contract['collector_limit']);
        $this->assertTrue($contract['admin_license_charged']);
    }

    /* ---------------------------------------------------------------------
     | Habilitación automática del módulo
     | --------------------------------------------------------------------- */

    public function test_setting_cobros_contract_enables_the_module(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.modules.update', $company), ['modules' => ['sales']]);

        $this->assertFalse($company->fresh()->isModuleEnabled('cobros'));

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])->assertRedirect();

        $this->assertTrue($company->fresh()->isModuleEnabled('cobros'));
    }

    /* ---------------------------------------------------------------------
     | Licencias potenciales = cobradores + administrador/dueño
     | --------------------------------------------------------------------- */

    public function test_potential_licenses_are_null_without_contract(): void
    {
        [$company] = $this->setupCompany();
        $service = app(CollectorManagementService::class);

        $this->assertNull($service->getPotentialLicenses($company));
    }

    public function test_potential_licenses_count_collectors_plus_admin(): void
    {
        [$company, $admin] = $this->setupCompany();
        $management = app(CollectorManagementService::class);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 5,
                'admin_license_charged' => true,
            ]);

        // Solo el administrador/dueño: 0 cobradores + 1 = 1 licencia potencial.
        $this->assertSame(1, $management->getPotentialLicenses($company));

        $management->createCollector($company, [
            'name' => 'Cobrador Uno', 'email' => 'pot1@test.com', 'password' => 'password123',
        ], $admin);
        $management->createCollector($company, [
            'name' => 'Cobrador Dos', 'email' => 'pot2@test.com', 'password' => 'password123',
        ], $admin);

        // 2 cobradores + 1 administrador/dueño = 3 licencias potenciales.
        $this->assertSame(3, $management->getPotentialLicenses($company));
    }

    public function test_admin_license_charged_does_not_change_potential_count(): void
    {
        [$companyA, $adminA] = $this->setupCompany('Charged');
        [$companyB, $adminB] = $this->setupCompany('Free');
        $management = app(CollectorManagementService::class);

        $this->actingAs($adminA)->patch(route('platform.cobros-contract.update', $companyA), [
            'license_mode' => 'individual', 'admin_license_charged' => true,
        ]);
        $this->actingAs($adminB)->patch(route('platform.cobros-contract.update', $companyB), [
            'license_mode' => 'individual', 'admin_license_charged' => false,
        ]);

        $this->assertSame(1, $management->getPotentialLicenses($companyA));
        $this->assertSame(1, $management->getPotentialLicenses($companyB));
    }

    /* ---------------------------------------------------------------------
     | Acceso visible: contrato CobrOS siempre presente en el Panel Maestro
     | --------------------------------------------------------------------- */

    public function test_contract_section_is_visible_even_when_module_is_disabled(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)
            ->patch(route('platform.modules.update', $company), ['modules' => ['sales']])
            ->assertRedirect();

        $this->assertFalse($company->fresh()->isModuleEnabled('cobros'));

        $this->actingAs($admin)
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('id="contrato-cobros"', false)
            ->assertSee('Contrato CobrOS', false)
            ->assertSee('El módulo CobrOS está desactivado', false);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ])->assertRedirect();

        $this->assertTrue($company->fresh()->isModuleEnabled('cobros'));
    }

    public function test_show_renders_potential_licenses_kpi(): void
    {
        [$company, $admin] = $this->setupCompany();

        $this->actingAs($admin)->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertDontSee('Licencias potenciales', false);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => 4,
                'admin_license_charged' => true,
            ]);

        $this->actingAs($admin)->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Licencias potenciales: 1', false)
            ->assertSee('cobradores + administrador/dueño', false);
    }

    /* ---------------------------------------------------------------------
     | Métodos auxiliares
     | --------------------------------------------------------------------- */

    private function setupCompany(bool $withTenantUser = false, string $name = 'Test'): array
    {
        $company = Company::create([
            'trade_name' => $name.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);

        $tenantUser = null;
        if ($withTenantUser) {
            $tenantUser = User::factory()->create(['is_active' => true]);
            $role = Role::create(['company_id' => $company->id, 'name' => 'Tenant'.uniqid(), 'is_active' => true]);
            $permission = Permission::firstOrCreate(['name' => 'dashboard.ver'], ['label' => 'Ver dashboard', 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission);
            $tenantUser->companies()->attach($company->id, ['role_id' => $role->id]);
        }

        return [$company, $admin, $tenantUser];
    }
}
