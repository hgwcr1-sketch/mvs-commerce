<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_contracted_module_and_user_permission_are_independent_requirements(): void
    {
        [$company, $branch, $allowed] = $this->context(['pos.acceder']);
        [, , $withoutPermission] = $this->context([]);

        $this->assertTrue($allowed->hasPermission('pos.acceder', $company));
        $company->modules()->create(['module_key' => 'sales', 'is_enabled' => false]);
        $this->assertFalse($allowed->hasPermission('pos.acceder', $company));
        $this->assertFalse($withoutPermission->hasPermission('pos.acceder', $withoutPermission->companies()->first()));
    }

    public function test_disabling_a_module_blocks_real_routes_and_hides_navigation(): void
    {
        [$company, $branch, $user] = $this->context(['pos.acceder', 'dashboard.ver']);
        $company->modules()->create(['module_key' => 'sales', 'is_enabled' => false]);
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->actingAs($user)->withSession($session)->get(route('pos.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('href="'.route('pos.index').'"', false);
    }

    public function test_module_configuration_is_strictly_isolated_between_companies(): void
    {
        [$first, $firstBranch, $firstUser] = $this->context(['pos.acceder']);
        [$second, $secondBranch, $secondUser] = $this->context(['pos.acceder']);
        $first->modules()->create(['module_key' => 'sales', 'is_enabled' => false]);

        $this->assertFalse($firstUser->hasPermission('pos.acceder', $first));
        $this->assertTrue($secondUser->hasPermission('pos.acceder', $second));
        $this->actingAs($secondUser)->withSession(['active_company_id' => $second->id, 'active_branch_id' => $secondBranch->id])
            ->get(route('pos.index'))->assertOk();
    }

    public function test_platform_admin_can_persist_the_complete_module_contract(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        [$company] = $this->context([]);

        $this->actingAs($admin)->patch(route('platform.modules.update', $company), ['modules' => ['sales', 'inventory']])->assertRedirect();

        $this->assertCount(count(ModuleRegistry::MODULES), $company->modules()->get());
        $this->assertTrue($company->fresh()->isModuleEnabled('sales'));
        $this->assertFalse($company->fresh()->isModuleEnabled('loyalty'));
    }

    public function test_existing_companies_remain_enabled_until_a_contract_is_configured(): void
    {
        [$company, , $user] = $this->context(['fidelidad.ver']);

        $this->assertTrue($company->isModuleEnabled('loyalty'));
        $this->assertTrue($user->hasPermission('fidelidad.ver', $company));
    }

    private function context(array $permissions): array
    {
        $company = Company::create(['trade_name' => 'Module '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'B'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Role '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        $register = CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'CAJA-'.uniqid(),
            'name' => 'Caja principal',
            'is_active' => true,
        ]);
        CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CAJA-'.uniqid(),
            'opened_by' => $user->id,
            'status' => CashSession::STATUS_OPEN,
            'open_guard' => CashSession::OPEN_GUARD,
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);

        return [$company, $branch, $user];
    }
}
