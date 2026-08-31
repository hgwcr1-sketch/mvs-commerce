<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M02PlatformTenantListTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_search_and_filter_complete_tenant_summaries(): void
    {
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        [$company, $owner] = $this->tenant('Empresa Norte', 'owner@norte.test', 2);
        [$other] = $this->tenant('Empresa Sur', 'owner@sur.test', 1);
        $licenses = app(CompanyLicenseService::class);
        $licenses->ensure($company)->update(['status' => 'active', 'plan' => 'Pro', 'branch_limit' => 2]);
        $licenses->ensure($other)->update(['status' => 'suspended', 'plan' => 'Base', 'branch_limit' => 1]);
        $company->modules()->create(['module_key' => 'sales', 'is_enabled' => true]);
        $other->modules()->create(['module_key' => 'inventory', 'is_enabled' => true]);

        $this->actingAs($platformAdmin)->get(route('platform.index', [
            'search' => $owner->email,
            'status' => 'active',
            'module' => 'sales',
        ]))->assertOk()
            ->assertSee('Empresa Norte')
            ->assertSee('Propietario:')
            ->assertSee('2/2')
            ->assertSee('Ventas y POS')
            ->assertSee('Pro')
            ->assertDontSee('Empresa Sur');
    }

    public function test_tenant_cannot_access_the_global_tenant_list(): void
    {
        [, $tenant] = $this->tenant('Privada', 'tenant@privada.test', 1);

        $this->actingAs($tenant)->get(route('platform.index'))->assertForbidden();
    }

    private function tenant(string $name, string $email, int $branches): array
    {
        $company = Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $owner = User::factory()->create(['email' => $email, 'is_active' => true]);
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);

        foreach (range(1, $branches) as $number) {
            $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.$number, 'code' => $name.$number, 'is_active' => true]);
            $owner->branches()->attach($branch->id);
        }

        return [$company, $owner];
    }
}
