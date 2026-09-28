<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class M01SaaSLicensingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_controls_the_tenant_license_and_branch_limit(): void
    {
        [$company] = $this->tenant('Licenciada');
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $this->actingAs($platformAdmin)->patch(route('platform.licenses.update', $company), [
            'status' => 'active',
            'plan' => 'Profesional',
            'branch_limit' => 3,
            'notes' => 'Contrato M01',
        ])->assertRedirect();

        $this->assertDatabaseHas('company_licenses', [
            'company_id' => $company->id,
            'status' => 'active',
            'plan' => 'Profesional',
            'branch_limit' => 3,
            'updated_by' => $platformAdmin->id,
        ]);
        $this->assertDatabaseHas('company_license_events', [
            'company_id' => $company->id,
            'actor_id' => $platformAdmin->id,
            'action' => 'manual',
            'to_status' => 'active',
        ]);
    }

    public function test_platform_admin_defines_the_complete_module_contract(): void
    {
        [$company] = $this->tenant('MÃ³dulos');
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $this->actingAs($platformAdmin)->patch(route('platform.modules.update', $company), [
            'modules' => ['sales', 'inventory'],
        ])->assertRedirect();

        $this->assertCount(count(ModuleRegistry::MODULES), $company->modules()->get());
        $this->assertTrue($company->fresh()->isModuleEnabled('sales'));
        $this->assertTrue($company->fresh()->isModuleEnabled('inventory'));
        $this->assertFalse($company->fresh()->isModuleEnabled('loyalty'));
    }

    public function test_tenant_cannot_change_its_own_or_another_company_contract(): void
    {
        [$company, , $tenantAdmin] = $this->tenant('Propia');
        [$otherCompany] = $this->tenant('Ajena');
        $licenses = app(CompanyLicenseService::class);
        $ownLicense = $licenses->ensure($company);
        $otherLicense = $licenses->ensure($otherCompany);

        foreach ([$company, $otherCompany] as $target) {
            $this->actingAs($tenantAdmin)->patch(route('platform.licenses.update', $target), [
                'status' => 'suspended',
                'plan' => 'Alterado',
                'branch_limit' => 1,
            ])->assertForbidden();
            $this->actingAs($tenantAdmin)->patch(route('platform.modules.update', $target), [
                'modules' => ['sales'],
            ])->assertForbidden();
        }

        $this->assertSame('trial', $ownLicense->fresh()->status);
        $this->assertSame('trial', $otherLicense->fresh()->status);
        $this->assertDatabaseCount('company_modules', 0);
    }

    public function test_contract_service_rejects_tenant_mutations_outside_http(): void
    {
        [$company, , $tenantAdmin] = $this->tenant('Servicio');
        $licenses = app(CompanyLicenseService::class);

        try {
            $licenses->updateContract($company, $tenantAdmin, 'active', null, ['branch_limit' => 2]);
            $this->fail('El tenant no debe poder actualizar su licencia desde el servicio.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            $licenses->updateModules($company, $tenantAdmin, ['sales']);
            $this->fail('El tenant no debe poder actualizar sus mÃ³dulos desde el servicio.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('company_modules', 0);
    }

    public function test_platform_updates_are_isolated_between_companies(): void
    {
        [$first] = $this->tenant('Primera');
        [$second] = $this->tenant('Segunda');
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $licenses = app(CompanyLicenseService::class);
        $secondLicense = $licenses->ensure($second);
        $second->modules()->create(['module_key' => 'loyalty', 'is_enabled' => true]);

        $this->actingAs($platformAdmin)->patch(route('platform.licenses.update', $first), [
            'status' => 'active',
            'plan' => 'Primera',
            'branch_limit' => 1,
        ])->assertRedirect();
        $this->actingAs($platformAdmin)->patch(route('platform.modules.update', $first), [
            'modules' => ['sales'],
        ])->assertRedirect();

        $this->assertSame('trial', $secondLicense->fresh()->status);
        $this->assertNull($secondLicense->fresh()->branch_limit);
        $this->assertTrue($second->fresh()->isModuleEnabled('loyalty'));
        $this->assertDatabaseCount('company_modules', count(ModuleRegistry::MODULES) + 1);
    }

    public function test_branch_limit_capacity_is_evaluated_per_company(): void
    {
        [$limited] = $this->tenant('Limitada');
        [$available] = $this->tenant('Disponible');
        $licenses = app(CompanyLicenseService::class);
        $licenses->ensure($limited)->update(['branch_limit' => 1]);
        $licenses->ensure($available)->update(['branch_limit' => 2]);

        try {
            $licenses->assertCapacity($limited, 'branches');
            $this->fail('El branch_limit debÃ­a bloquear a la empresa limitada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branches', $exception->errors());
        }

        $licenses->assertCapacity($available, 'branches');
        $this->addToAssertionCount(1);
    }

    private function tenant(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name,
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'B'.uniqid(),
            'is_active' => true,
        ]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
