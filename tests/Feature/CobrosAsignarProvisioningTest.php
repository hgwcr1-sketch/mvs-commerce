<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CobrosAsignarProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function existingInstallFixture(): array
    {
        $c = Company::create(['trade_name' => 'Base existente '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $ownerRole = Role::create(['company_id' => $c->id, 'name' => 'Administrador', 'is_active' => true]);
        $superRole = Role::create(['company_id' => $c->id, 'name' => 'Supervisor', 'is_active' => true]);
        $collectorRole = Role::create(['company_id' => $c->id, 'name' => 'Cobrador CobrOS', 'is_active' => true]);

        foreach ([
            'cobros.acceder',
            'cobros.portal_dueno',
            'cobros.supervisar',
            'cobros.cobrar',
        ] as $name) {
            $p = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            if (in_array($name, ['cobros.acceder', 'cobros.portal_dueno'], true)) {
                $ownerRole->permissions()->attach($p);
            }
            if (in_array($name, ['cobros.acceder', 'cobros.supervisar'], true)) {
                $superRole->permissions()->attach($p);
            }
            if (in_array($name, ['cobros.acceder', 'cobros.cobrar'], true)) {
                $collectorRole->permissions()->attach($p);
            }
        }

        $owner = User::factory()->create(['is_active' => true]);
        $owner->companies()->attach($c->id, ['role_id' => $ownerRole->id]);
        $collector = User::factory()->create(['is_active' => true]);
        $collector->companies()->attach($c->id, ['role_id' => $collectorRole->id]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($c, $admin, ['cobros']);

        return [$c, $owner, $collector, $ownerRole, $superRole, $collectorRole];
    }

    private function simulatePre1ECDatabase(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'cobros.asignar')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    private function runProvisioningMigration(): void
    {
        $migration = include database_path('migrations/2026_09_22_000003_provision_cobros_asignar_permission.php');
        $migration->up();
    }

    private function roleHas(Role $role, string $permission): bool
    {
        return $role->permissions()->where('permissions.name', $permission)->exists();
    }

    public function test_existing_install_gets_asignar_only_for_portal_and_supervisar_roles(): void
    {
        [$c, $owner, $collector, $ownerRole, $superRole, $collectorRole] = $this->existingInstallFixture();
        $this->simulatePre1ECDatabase();

        $this->assertFalse(Permission::where('name', 'cobros.asignar')->exists());
        $this->assertFalse($owner->hasPermission('cobros.asignar', $c));

        $this->runProvisioningMigration();

        $this->assertTrue(Permission::where('name', 'cobros.asignar')->where('is_active', true)->exists());
        $this->assertTrue($this->roleHas($ownerRole, 'cobros.asignar'));
        $this->assertTrue($this->roleHas($superRole, 'cobros.asignar'));
        $this->assertFalse($this->roleHas($collectorRole, 'cobros.asignar'));

        $this->assertTrue($owner->hasPermission('cobros.asignar', $c));
        $this->assertFalse($collector->hasPermission('cobros.asignar', $c));

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.assignments'))
            ->assertOk();

        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.assignments'))
            ->assertForbidden()
            ->assertSee('No tiene permiso para acceder a este módulo');
    }

    public function test_provisioning_migration_is_idempotent(): void
    {
        [$c] = $this->existingInstallFixture();
        $this->simulatePre1ECDatabase();
        $this->runProvisioningMigration();

        $before = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'cobros.asignar')
            ->count();

        $this->runProvisioningMigration();

        $after = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'cobros.asignar')
            ->count();

        $this->assertSame(1, DB::table('permissions')->where('name', 'cobros.asignar')->count());
        $this->assertSame($before, $after);
        $this->assertGreaterThan(0, $after);
    }

    public function test_collector_role_without_owner_or_supervisor_never_gets_asignar(): void
    {
        [$c, $owner, $collector] = $this->existingInstallFixture();
        $this->simulatePre1ECDatabase();
        $this->runProvisioningMigration();

        $this->assertFalse($collector->hasPermission('cobros.asignar', $c));
        $this->assertTrue($collector->hasPermission('cobros.cobrar', $c));
        $this->assertTrue($owner->hasPermission('cobros.asignar', $c));
    }
}
