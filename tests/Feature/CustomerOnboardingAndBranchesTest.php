<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Canton;
use App\Models\Company;
use App\Models\Country;
use App\Models\District;
use App\Models\Permission;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_onboarding_loads_costa_rica_and_its_seven_provinces_before_company_exists(): void
    {
        $country = Country::create([
            'name' => 'Costa Rica', 'iso2' => 'CR', 'iso3' => 'CRI', 'phone_code' => '+506',
            'currency' => 'CRC', 'currency_symbol' => '₡', 'is_default' => true, 'is_active' => true,
        ]);

        foreach (['San José', 'Alajuela', 'Cartago', 'Heredia', 'Guanacaste', 'Puntarenas', 'Limón'] as $index => $name) {
            Province::create(['country_id' => $country->id, 'code' => (string) ($index + 1), 'name' => $name, 'is_active' => true]);
        }

        $user = User::factory()->create(['is_active' => true]);
        $response = $this->actingAs($user)->get(route('empresa.create'));

        $response->assertOk()->assertSee('Costa Rica')->assertSee('San José')->assertSee('Limón');
        $this->assertSame(7, $response->viewData('provinces')->count());
        $this->assertSame($country->id, $response->viewData('provinces')->first()->country_id);
    }

    public function test_onboarding_stores_the_company_logo_on_the_public_disk(): void
    {
        Storage::fake('public');
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->post(route('empresa.store'), [
            'trade_name' => 'Empresa con Logo',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'branch_name' => 'Principal',
            'branch_code' => 'PRINCIPAL',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ])->assertRedirect(route('dashboard'));

        $company = Company::where('trade_name', 'Empresa con Logo')->firstOrFail();
        $this->assertNotEmpty($company->logo);
        $this->assertStringStartsWith('companies/', $company->logo);
        Storage::disk('public')->assertExists($company->logo);
    }

    public function test_authenticated_onboarding_can_use_the_complete_geographic_cascade(): void
    {
        $country = Country::create([
            'name' => 'Costa Rica', 'iso2' => 'CR', 'iso3' => 'CRI', 'phone_code' => '+506',
            'currency' => 'CRC', 'currency_symbol' => '₡', 'is_default' => true, 'is_active' => true,
        ]);
        $province = Province::create(['country_id' => $country->id, 'code' => '1', 'name' => 'San José', 'is_active' => true]);
        $canton = Canton::create(['province_id' => $province->id, 'code' => '101', 'name' => 'San José', 'is_active' => true]);
        $district = District::create(['province_id' => $province->id, 'canton_id' => $canton->id, 'code' => '10101', 'name' => 'Carmen', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->getJson(route('ubicaciones.provincias', $country))
            ->assertOk()->assertExactJson([['id' => $province->id, 'name' => 'San José']]);
        $this->getJson(route('ubicaciones.cantones', $province))
            ->assertOk()->assertExactJson([['id' => $canton->id, 'name' => 'San José']]);
        $this->getJson(route('ubicaciones.distritos', $canton))
            ->assertOk()->assertExactJson([['id' => $district->id, 'name' => 'Carmen']]);
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

    public function test_platform_limit_increase_allows_tenant_to_create_the_next_branch(): void
    {
        [$company, $branch, $tenantAdmin] = $this->tenant(['configuracion.ver', 'configuracion.editar']);
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        app(CompanyLicenseService::class)->ensure($company)->update(['status' => 'active', 'plan' => 'Pro', 'branch_limit' => 2]);
        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->actingAs($tenantAdmin)->withSession($session)->post(route('branches.store'), ['name' => 'Segunda', 'code' => 'S2'])->assertRedirect();
        $this->post(route('branches.store'), ['name' => 'Tercera', 'code' => 'S3'])->assertSessionHasErrors('branches');
        $this->assertSame(2, $company->branches()->count());

        $this->actingAs($platformAdmin)->patch(route('platform.licenses.update', $company), [
            'status' => 'active', 'plan' => 'Pro', 'branch_limit' => 3,
        ])->assertRedirect();

        $this->actingAs($tenantAdmin)->withSession($session)->post(route('branches.store'), ['name' => 'Tercera', 'code' => 'S3'])->assertRedirect();
        $this->assertSame(3, $company->branches()->count());
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
