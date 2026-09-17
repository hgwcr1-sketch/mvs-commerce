<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminFlagAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_administrador_role_remains_functional_after_migration(): void
    {
        $this->seed(PermissionSeeder::class);
        [$company, $branch, $user] = $this->context('Administrador', true);

        $this->assertTrue($user->hasPermission('notificaciones.ver', $company));
        $this->assertTrue($user->hasPermission('dashboard.admin', $company));
        $this->assertTrue($user->hasPermission('compras.recepcion.verificar', $company));
    }

    public function test_role_named_administrador_without_flag_does_not_inherit_privileges(): void
    {
        $this->seed(PermissionSeeder::class);
        [$company, $branch, $user] = $this->context('Administrador', false);

        $this->assertFalse($user->hasPermission('notificaciones.configurar', $company));
        $this->assertFalse($user->hasPermission('dashboard.admin', $company));
    }

    public function test_non_super_admin_role_only_has_explicit_permissions(): void
    {
        [$company, $branch, $user] = $this->context('Cajero', false, ['caja.abrir']);

        $this->assertTrue($user->hasPermission('caja.abrir', $company));
        $this->assertFalse($user->hasPermission('caja.administrar', $company));
        $this->assertFalse($user->hasPermission('usuarios.ver', $company));
    }

    public function test_super_admin_flag_is_scoped_to_the_correct_company(): void
    {
        Permission::create(['name' => 'notificaciones.ver', 'label' => 'Ver', 'module' => 'Notificaciones', 'is_active' => true]);
        [$companyA, $branchA, $userA] = $this->context('Admin A', true);
        [$companyB] = $this->createCompany('Empresa B');
        $branchB = Branch::create(['company_id' => $companyB->id, 'name' => 'Principal', 'code' => 'PB'.uniqid(), 'is_active' => true]);
        $userA->branches()->attach($branchB);

        $this->assertTrue($userA->hasPermission('notificaciones.ver', $companyA));
        $this->assertFalse($userA->hasPermission('notificaciones.ver', $companyB));
    }

    public function test_super_admin_flag_does_not_cross_company_id(): void
    {
        $this->seed(PermissionSeeder::class);
        [$companyA, $branchA, $userA] = $this->context('Admin A', true);
        [$companyB, $branchB, $userB] = $this->context('Admin B', true);

        $this->assertTrue($userA->hasPermission('notificaciones.configurar', $companyA));
        $this->assertFalse($userA->hasPermission('notificaciones.configurar', $companyB));
        $this->assertTrue($userB->hasPermission('notificaciones.configurar', $companyB));
        $this->assertFalse($userB->hasPermission('notificaciones.configurar', $companyA));
    }

    public function test_dashboard_admin_permission_is_not_universal_without_super_admin_flag(): void
    {
        [$company, $branch, $user] = $this->context('Verificador', false, ['dashboard.admin']);

        $this->assertTrue($user->hasPermission('dashboard.admin', $company));
        $this->assertFalse($user->hasPermission('notificaciones.compras', $company));
        $this->assertFalse($user->hasPermission('usuarios.ver', $company));
    }

    public function test_super_admin_only_grants_existing_active_permissions(): void
    {
        Permission::create(['name' => 'notificaciones.ver', 'label' => 'Ver', 'module' => 'Notificaciones', 'is_active' => true]);
        [$company, $branch, $user] = $this->context('Super', true);

        $this->assertTrue($user->hasPermission('notificaciones.ver', $company));
        $this->assertFalse($user->hasPermission('permission.that.does.not.exist', $company));
    }

    public function test_platform_admin_user_is_not_a_tenant_super_admin(): void
    {
        $this->seed(PermissionSeeder::class);
        [$company, $branch] = $this->createCompany('Tenant');
        $platformUser = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $this->assertFalse($platformUser->hasPermission('notificaciones.ver', $company));
        $this->assertFalse($platformUser->hasPermission('dashboard.admin', $company));
    }

    public function test_company_provisioner_marks_initial_admin_role_as_super_admin(): void
    {
        Permission::create(['name' => 'notificaciones.ver', 'label' => 'Ver', 'module' => 'Notificaciones', 'is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);

        $company = app(CompanyProvisioner::class)->provision($owner, [
            'trade_name' => 'Nueva '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $role = Role::query()->where('company_id', $company->id)->where('name', 'Administrador')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->is_super_admin);
        $this->assertTrue($owner->hasPermission('notificaciones.ver', $company));
    }

    private function createCompany(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name.' '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P'.uniqid(),
            'is_active' => true,
        ]);

        return [$company, $branch];
    }

    private function context(string $roleName, bool $isSuperAdmin, ?array $permissions = null): array
    {
        [$company, $branch] = $this->createCompany('Empresa');
        $role = Role::create([
            'company_id' => $company->id,
            'name' => $roleName,
            'is_active' => true,
            'is_super_admin' => $isSuperAdmin,
        ]);

        if ($permissions !== null) {
            foreach ($permissions as $name) {
                $permission = Permission::firstOrCreate(
                    ['name' => $name],
                    ['label' => $name, 'module' => 'Test', 'is_active' => true]
                );
                $role->permissions()->attach($permission->id);
            }
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
