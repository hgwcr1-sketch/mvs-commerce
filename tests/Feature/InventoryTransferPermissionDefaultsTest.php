<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asignación por defecto de inventario.transferencias.ver y
 * inventario.transferencias.recibir:
 *
 * - super_admin y el Administrador de empresa: ambos.
 * - Roles que ya gestionan inventario o traslados: ambos, sin heredar
 *   inventario.transferir (crear/despachar).
 * - Roles sin funciones de gestión de inventario (Cajero, Vendedor, solo
 *   lectura): ninguno automáticamente.
 */
class InventoryTransferPermissionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_role_receives_both_transfer_permissions_by_default(): void
    {
        $company = $this->company();
        $role = $this->role($company, 'Administrador', [], ['is_super_admin' => true]);

        $this->seed(PermissionSeeder::class);

        $this->assertTrue($this->hasPermission($role, 'inventario.transferencias.ver'));
        $this->assertTrue($this->hasPermission($role, 'inventario.transferencias.recibir'));
    }

    public function test_provisioned_company_administrator_receives_both_transfer_permissions(): void
    {
        $this->seed(PermissionSeeder::class);

        $email = 'admin.transferencias.' . uniqid() . '@test.local';
        $company = app(CompanyProvisioner::class)->install(
            [
                'name' => 'Administrador Transferencias',
                'email' => $email,
                'password' => 'segura-para-prueba',
            ],
            ['trade_name' => 'Empresa transferencias ' . uniqid()],
        );
        $administrator = User::query()->where('email', $email)->firstOrFail();

        $this->assertTrue($administrator->hasPermission('inventario.transferencias.ver', $company));
        $this->assertTrue($administrator->hasPermission('inventario.transferencias.recibir', $company));
    }

    public function test_role_with_inventory_management_receives_both_permissions_but_not_transferir(): void
    {
        $company = $this->company();
        $warehouse = $this->role($company, 'Encargado de bodega', ['inventario.ajustar']);
        $counter = $this->role($company, 'Contador de inventario', ['inventario.conteo.contar']);

        $this->seed(PermissionSeeder::class);

        $this->assertTrue($this->hasPermission($warehouse, 'inventario.transferencias.ver'));
        $this->assertTrue($this->hasPermission($warehouse, 'inventario.transferencias.recibir'));
        $this->assertTrue($this->hasPermission($counter, 'inventario.transferencias.ver'));
        $this->assertTrue($this->hasPermission($counter, 'inventario.transferencias.recibir'));

        // La asignación por defecto no hereda la capacidad de crear/despachar.
        $this->assertFalse($this->hasPermission($warehouse, 'inventario.transferir'));
        $this->assertFalse($this->hasPermission($counter, 'inventario.transferir'));
    }

    public function test_role_with_transfer_functions_receives_both_permissions(): void
    {
        $company = $this->company();
        $dispatcher = $this->role($company, 'Despachador', ['inventario.transferir']);

        $this->seed(PermissionSeeder::class);

        $this->assertTrue($this->hasPermission($dispatcher, 'inventario.transferencias.ver'));
        $this->assertTrue($this->hasPermission($dispatcher, 'inventario.transferencias.recibir'));
    }

    public function test_read_only_inventory_role_does_not_receive_transfer_permissions(): void
    {
        $company = $this->company();
        $viewer = $this->role($company, 'Consulta de inventario', [
            'inventario.ver',
            'inventario.kardex',
            'inventario.conteo.ver',
        ]);

        $this->seed(PermissionSeeder::class);

        $this->assertFalse($this->hasPermission($viewer, 'inventario.transferencias.ver'));
        $this->assertFalse($this->hasPermission($viewer, 'inventario.transferencias.recibir'));
    }

    public function test_cashier_and_seller_roles_do_not_receive_transfer_permissions(): void
    {
        $company = $this->company();
        $cashier = $this->role($company, 'Cajero', ['pos.acceder', 'caja.abrir']);
        $seller = $this->role($company, 'Vendedor', ['ventas.crear', 'clientes.ver']);

        $this->seed(PermissionSeeder::class);

        $this->assertFalse($this->hasPermission($cashier, 'inventario.transferencias.ver'));
        $this->assertFalse($this->hasPermission($cashier, 'inventario.transferencias.recibir'));
        $this->assertFalse($this->hasPermission($seller, 'inventario.transferencias.ver'));
        $this->assertFalse($this->hasPermission($seller, 'inventario.transferencias.recibir'));
    }

    public function test_seeder_is_idempotent_for_default_transfer_permissions(): void
    {
        $company = $this->company();
        $warehouse = $this->role($company, 'Encargado bodega 2', ['inventario.ajustar']);

        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertSame(
            1,
            $warehouse->fresh()->permissions()
                ->where('name', 'inventario.transferencias.recibir')
                ->count()
        );
    }

    private function company(): Company
    {
        return Company::create([
            'trade_name' => 'Empresa permisos ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
    }

    private function role(Company $company, string $name, array $permissions, array $attributes = []): Role
    {
        $role = Role::create([
            'company_id' => $company->id,
            'name' => $name,
            'is_active' => true,
            ...$attributes,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => $permissionName, 'module' => 'Inventario', 'is_active' => true]
            );
            $role->permissions()->syncWithoutDetaching($permission->id);
        }

        return $role;
    }

    private function hasPermission(Role $role, string $permissionName): bool
    {
        return $role->fresh()->permissions()->where('name', $permissionName)->exists();
    }
}
