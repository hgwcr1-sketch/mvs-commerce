<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_receives_current_order_permissions_and_legacy_permission_is_removed(): void
    {
        $company = Company::create([
            'trade_name' => 'Empresa de prueba',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $administrator = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);
        $legacyPermission = Permission::create([
            'name' => 'pedidos.confirmar',
            'label' => 'Confirmar pedidos',
            'module' => 'Pedidos',
            'is_active' => true,
        ]);
        $administrator->permissions()->attach($legacyPermission);

        $this->seed(PermissionSeeder::class);

        $this->assertEqualsCanonicalizing(
            [
                'pedidos.ver',
                'pedidos.crear',
                'pedidos.aprobar',
                'pedidos.rechazar',
                'pedidos.cancelar',
                'pedidos.preparar_compra',
            ],
            $administrator->permissions()
                ->where('module', 'Pedidos')
                ->pluck('name')
                ->all()
        );
        $this->assertDatabaseMissing('permissions', ['name' => 'pedidos.confirmar']);
    }
}
