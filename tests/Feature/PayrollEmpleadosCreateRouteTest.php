<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollEmpleadosCreateRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_sees_create_form_and_link(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $verPermission = Permission::create(['name' => 'planilla.empleados.ver', 'label' => 'Ver empleados', 'module' => 'Planilla', 'is_active' => true]);
        $crearPermission = Permission::create(['name' => 'planilla.empleados.crear', 'label' => 'Crear empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach([$verPermission->id, $crearPermission->id]);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        // Test create form
        $response = $this->get('/planilla/empleados/crear');
        $response->assertOk();
        $response->assertSee('Nuevo empleado');
        $response->assertSee('employee_code');
        $response->assertSee('identification');
        $response->assertSee('first_name');
        $response->assertSee('last_name');
        $response->assertSee('position');
        $response->assertSee('hire_date');
        $response->assertSee('base_salary');
        $response->assertDontSee('company_id');

        // Test link in index
        $response = $this->get('/planilla/empleados');
        $response->assertOk();
        $response->assertSee('Nuevo empleado');
    }

    public function test_without_permission_receives_403_and_no_link(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $verPermission = Permission::create(['name' => 'planilla.empleados.ver', 'label' => 'Ver empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($verPermission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        // Create form returns 403
        $response = $this->get('/planilla/empleados/crear');
        $response->assertForbidden();

        // Index doesn't show link
        $response = $this->get('/planilla/empleados');
        $response->assertOk();
        $response->assertDontSee('Nuevo empleado');
    }
}