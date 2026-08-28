<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerOnboardingAndBranchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_without_company_is_forced_to_create_company_and_first_branch(): void
    {
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('empresa.create'));
        $this->get(route('empresa.create'))->assertOk()->assertSee('Primera sucursal');

        $response = $this->post(route('empresa.store'), [
            'trade_name' => 'Cliente Nuevo',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'branch_name' => 'San José',
            'branch_code' => 'SJO',
        ]);

        $company = Company::where('trade_name', 'Cliente Nuevo')->firstOrFail();
        $branch = $company->branches()->firstOrFail();
        $response->assertRedirect(route('dashboard'));
        $this->assertSame($company->id, session('active_company_id'));
        $this->assertSame($branch->id, session('active_branch_id'));
        $this->assertTrue($user->fresh()->companies()->whereKey($company->id)->exists());
        $this->assertTrue($user->fresh()->branches()->whereKey($branch->id)->exists());
    }

    public function test_login_routes_platform_admin_to_master_panel_and_customer_to_onboarding(): void
    {
        $platform = User::factory()->create(['email' => 'platform@example.test', 'password' => Hash::make('Secure123'), 'is_active' => true, 'is_platform_admin' => true]);
        $customer = User::factory()->create(['email' => 'customer@example.test', 'password' => Hash::make('Secure123'), 'is_active' => true]);

        $this->post(route('login.store'), ['email' => $platform->email, 'password' => 'Secure123'])
            ->assertRedirect(route('platform.index'));
        $this->get(route('dashboard'))->assertRedirect(route('platform.index'));
        $this->get(route('empresa.create'))->assertRedirect(route('platform.index'));
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'Secure123'])
            ->assertRedirect(route('empresa.create'));
    }

    public function test_existing_company_is_not_sent_through_onboarding_again(): void
    {
        [$company, $branch, $user] = $this->tenant(['dashboard.ver']);

        $response = $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])->get(route('dashboard'));

        $this->assertNotSame(route('empresa.create'), $response->headers->get('Location'));
    }

    public function test_authorized_admin_can_see_and_create_branches_until_license_limit(): void
    {
        [$company, $branch, $admin] = $this->tenant(['configuracion.ver', 'configuracion.editar']);
        app(CompanyLicenseService::class)->ensure($company)->update(['branch_limit' => 2]);
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->actingAs($admin)->withSession($session)->get(route('branches.index'))
            ->assertOk()->assertSee('Sucursales')->assertSee(route('branches.create'), false);
        $this->get(route('dashboard'))->assertOk()->assertSee(route('branches.index'), false);

        $this->post(route('branches.store'), ['name' => 'Liberia', 'code' => 'LIB'])
            ->assertRedirect(route('branches.index'));
        $created = Branch::where('company_id', $company->id)->where('code', 'LIB')->firstOrFail();
        $this->assertTrue($admin->branches()->whereKey($created->id)->exists());

        $this->post(route('branches.store'), ['name' => 'Heredia', 'code' => 'HER'])
            ->assertSessionHasErrors('branches');
        $this->assertSame(2, $company->branches()->count());
    }

    public function test_branch_routes_require_configuration_permissions(): void
    {
        [$company, $branch, $viewer] = $this->tenant(['configuracion.ver']);
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->actingAs($viewer)->withSession($session)->get(route('branches.index'))->assertOk();
        $this->get(route('branches.create'))->assertForbidden();
        $this->post(route('branches.store'), ['name' => 'No autorizada', 'code' => 'NO'])->assertForbidden();
    }

    private function tenant(array $permissions): array
    {
        $company = Company::create(['trade_name' => 'MYM', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Configuración', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}