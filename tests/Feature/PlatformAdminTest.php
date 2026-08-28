<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_platform_administrators_can_enter_the_master_panel(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $regular = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);

        $this->get(route('platform.index'))->assertRedirect(route('login'));
        $this->actingAs($regular)->get(route('platform.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('platform.index'))->assertOk()->assertSee('Panel Maestro MVS');

        $admin->update(['is_active' => false]);
        $this->actingAs($admin->fresh())->get(route('platform.index'))->assertForbidden();
    }

    public function test_existing_user_can_receive_and_lose_master_access_without_manual_database_changes(): void
    {
        $user = User::factory()->create(['email' => 'owner@mvs.test', 'is_active' => true, 'is_platform_admin' => false]);

        $this->artisan('platform:admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_admin);
        $this->artisan('platform:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_independent_platform_administrator_can_be_created_interactively(): void
    {
        $this->artisan('platform:admin', ['email' => 'platform@example.test', '--create' => true])
            ->expectsQuestion('Nombre de la persona administradora', 'Administración Plataforma')
            ->expectsQuestion('Contraseña', 'SeguraAdmin9')
            ->expectsQuestion('Confirme la contraseña', 'SeguraAdmin9')
            ->expectsOutput('Cuenta de plataforma creada.')
            ->assertSuccessful();

        $user = User::query()->where('email', 'platform@example.test')->firstOrFail();

        $this->assertSame('Administración Plataforma', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue(Hash::check('SeguraAdmin9', $user->password));
        $this->assertFalse($user->companies()->exists());
        $this->assertFalse($user->branches()->exists());
    }

    public function test_platform_account_creation_requires_a_confirmed_secure_password(): void
    {
        $this->artisan('platform:admin', ['email' => 'platform@example.test', '--create' => true])
            ->expectsQuestion('Nombre de la persona administradora', 'Administración Plataforma')
            ->expectsQuestion('Contraseña', 'SeguraAdmin9')
            ->expectsQuestion('Confirme la contraseña', 'OtraAdmin9')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'platform@example.test']);
    }

    public function test_created_platform_account_remains_separate_from_tenant_accounts(): void
    {
        [$company, $branch, $tenantAdmin] = $this->tenant('Empresa Tenant');

        $this->artisan('platform:admin', ['email' => 'platform@example.test', '--create' => true])
            ->expectsQuestion('Nombre de la persona administradora', 'Administración Plataforma')
            ->expectsQuestion('Contraseña', 'SeguraAdmin9')
            ->expectsQuestion('Confirme la contraseña', 'SeguraAdmin9')
            ->assertSuccessful();

        $platformAdmin = User::query()->where('email', 'platform@example.test')->firstOrFail();

        $this->assertFalse($platformAdmin->companies()->whereKey($company->id)->exists());
        $this->assertFalse($platformAdmin->branches()->whereKey($branch->id)->exists());
        $this->assertFalse($tenantAdmin->fresh()->is_platform_admin);
    }

    public function test_tenant_administrator_cannot_be_promoted_to_platform_administrator(): void
    {
        [$company, , $tenantAdmin] = $this->tenant('Empresa Tenant');

        $this->artisan('platform:admin', ['email' => $tenantAdmin->email])->assertFailed();

        $this->assertFalse($tenantAdmin->fresh()->is_platform_admin);
        $this->assertTrue($tenantAdmin->companies()->whereKey($company->id)->exists());
    }
    public function test_platform_access_can_be_revoked_even_from_a_tenant_account(): void
    {
        [$company, , $tenantAdmin] = $this->tenant('Empresa Tenant');
        $tenantAdmin->update(['is_platform_admin' => true]);

        $this->artisan('platform:admin', ['email' => $tenantAdmin->email, '--revoke' => true])
            ->expectsOutput('Acceso maestro retirado.')
            ->assertSuccessful();

        $this->assertFalse($tenantAdmin->fresh()->is_platform_admin);
        $this->assertTrue($tenantAdmin->companies()->whereKey($company->id)->exists());
    }

    public function test_dashboard_lists_tenants_without_loading_operational_records(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        [$first, $firstBranch, $firstUser] = $this->tenant('Empresa Uno');
        [$second] = $this->tenant('Empresa Dos');

        $response = $this->actingAs($admin)->get(route('platform.index'));

        $response->assertOk()->assertSee('Empresa Uno')->assertSee('Empresa Dos')->assertSee('Sucursales')->assertSee('Usuarios');
        $this->assertSame(1, $first->branches()->count());
        $this->assertTrue($firstUser->companies()->whereKey($first->id)->exists());
        $this->assertFalse($firstUser->companies()->whereKey($second->id)->exists());
    }

    public function test_company_detail_is_scoped_and_rejects_cross_company_branch_or_user_updates(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        [$first, $firstBranch, $firstUser] = $this->tenant('Empresa Uno');
        [$second, $secondBranch, $secondUser] = $this->tenant('Empresa Dos');

        $this->actingAs($admin)->get(route('platform.companies.show', $first))
            ->assertOk()->assertSee($firstBranch->name)->assertSee($firstUser->email)
            ->assertDontSee($secondBranch->name)->assertDontSee($secondUser->email);
        $this->patch(route('platform.branches.update', [$first, $secondBranch]), ['is_active' => 0])->assertNotFound();
        $this->patch(route('platform.users.update', [$first, $secondUser]), ['is_active' => 0])->assertNotFound();
        $this->assertTrue($secondBranch->fresh()->is_active);
        $this->assertTrue($secondUser->fresh()->is_active);
    }

    public function test_platform_admin_can_update_basic_company_branch_and_user_status(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        [$company, $branch, $user] = $this->tenant('Empresa Uno');

        $this->actingAs($admin)->patch(route('platform.companies.update', $company), [
            'trade_name' => 'Empresa Renovada', 'legal_name' => 'Empresa Renovada S.A.', 'email' => 'tenant@example.test',
            'phone' => '2222-2222', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => 0,
        ])->assertRedirect();
        $this->patch(route('platform.branches.update', [$company, $branch]), ['is_active' => 0])->assertRedirect();
        $this->patch(route('platform.users.update', [$company, $user]), ['is_active' => 0])->assertRedirect();

        $this->assertSame('Empresa Renovada', $company->fresh()->trade_name);
        $this->assertFalse($company->fresh()->is_active);
        $this->assertFalse($branch->fresh()->is_active);
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_master_panel_is_mobile_first_and_explains_module_permission_separation(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        [$company] = $this->tenant('Empresa Responsive');

        $this->actingAs($admin)->get(route('platform.index'))->assertOk()
            ->assertSee('grid grid-cols-2 gap-3 lg:grid-cols-4', false)
            ->assertSee('grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3', false)
            ->assertSee('min-h-11', false);
        $this->get(route('platform.companies.show', $company))->assertOk()
            ->assertSee('overflow-x-auto', false)->assertSee('Habilitar un módulo no concede permisos');
    }

    private function tenant(string $name): array
    {
        $company = Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => $name.' Sucursal', 'code' => 'B'.$company->id, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $user = User::factory()->create(['email' => 'user'.$company->id.'@example.test', 'is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
