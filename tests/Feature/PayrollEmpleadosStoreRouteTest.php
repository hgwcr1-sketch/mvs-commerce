<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollEmpleadosStoreRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_creates_employee_in_active_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.empleados.crear', 'label' => 'Crear empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/empleados', [
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $response->assertRedirect(route('planilla.empleados.index'));
        $this->assertDatabaseHas('employees', [
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
        ]);
    }

    public function test_without_permission_receives_403(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/empleados', [
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-001']);
    }

    public function test_injected_company_id_not_persisted(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.empleados.crear', 'label' => 'Crear empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/empleados', [
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
            'company_id' => $companyB->id,
        ]);

        $response->assertRedirect(route('planilla.empleados.index'));
        $this->assertDatabaseHas('employees', [
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
        ]);
        $this->assertDatabaseMissing('employees', ['company_id' => $companyB->id]);
    }

    public function test_invalid_data_does_not_create_row(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.empleados.crear', 'label' => 'Crear empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/empleados', [
            'employee_code' => '',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $response->assertSessionHasErrors('employee_code');
        $this->assertDatabaseMissing('employees', ['identification' => '111111111']);
    }
}