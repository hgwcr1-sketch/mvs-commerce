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

    public function test_administrator_and_local_variants_receive_credit_note_permissions_non_destructively(): void
    {
        $company = Company::create([
            'trade_name' => 'Empresa NC Prueba',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $adminRole = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);
        $localAdminRole = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador Local',
            'is_active' => true,
        ]);
        $cashierRole = Role::create([
            'company_id' => $company->id,
            'name' => 'Cajero',
            'is_active' => true,
        ]);
        $regionalAdminRole = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador Regional',
            'is_active' => true,
        ]);

        $existingPerm = Permission::create([
            'name' => 'pos.acceder',
            'label' => 'Acceder al POS',
            'module' => 'POS',
            'is_active' => true,
        ]);
        $localAdminRole->permissions()->attach($existingPerm);
        $cashierRole->permissions()->attach($existingPerm);

        $user = \App\Models\User::factory()->create(['email' => 'admin.test@local.test', 'is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $localAdminRole->id]);

        $this->session(['active_company_id' => $company->id]);

        $this->seed(PermissionSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => 'notas_credito.crear',
            'label' => 'Crear notas de crédito',
            'module' => 'Notas de Crédito',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('permissions', [
            'name' => 'notas_credito.aplicar',
            'label' => 'Aplicar notas de crédito',
            'module' => 'Notas de Crédito',
            'is_active' => true,
        ]);

        $this->assertTrue($adminRole->fresh()->permissions()->where('name', 'notas_credito.crear')->exists());
        $this->assertTrue($adminRole->fresh()->permissions()->where('name', 'notas_credito.aplicar')->exists());
        $this->assertTrue($localAdminRole->fresh()->permissions()->where('name', 'notas_credito.crear')->exists());
        $this->assertTrue($localAdminRole->fresh()->permissions()->where('name', 'notas_credito.aplicar')->exists());
        $this->assertTrue($localAdminRole->fresh()->permissions()->where('name', 'pos.acceder')->exists());
        $this->assertFalse($cashierRole->fresh()->permissions()->where('name', 'notas_credito.crear')->exists());
        $this->assertFalse($cashierRole->fresh()->permissions()->where('name', 'notas_credito.aplicar')->exists());

        $this->assertTrue($user->can('notas_credito.crear'));
        $this->assertTrue($user->can('notas_credito.aplicar'));

        // Administrador Regional NO debe recibir permisos NC automáticamente
        $this->assertFalse($regionalAdminRole->fresh()->permissions()->where('name', 'notas_credito.crear')->exists());
        $this->assertFalse($regionalAdminRole->fresh()->permissions()->where('name', 'notas_credito.aplicar')->exists());
    }
}
