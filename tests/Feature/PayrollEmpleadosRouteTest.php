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

class PayrollEmpleadosRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_permission_sees_only_active_company_employees(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $companyB->id, 'name' => 'Sucursal B', 'code' => 'SB', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.empleados.ver', 'label' => 'Ver empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        $empA = Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
            'status' => 'active',
        ]);
        Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-002',
            'identification' => '222222222',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
            'status' => 'inactive',
        ]);
        $empB = Employee::create([
            'company_id' => $companyB->id,
            'employee_code' => 'EMP-003',
            'identification' => '333333333',
            'first_name' => 'Pedro',
            'last_name' => 'Gomez',
            'position' => 'Manager',
            'hire_date' => '2024-01-01',
            'base_salary' => 4000.00,
            'status' => 'active',
        ]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->get('/planilla/empleados');

        $response->assertOk();
        $response->assertSee($empA->employee_code);
        $response->assertSee($empA->first_name.' '.$empA->last_name);
        $response->assertDontSee($empB->employee_code);
        $response->assertDontSee('3000.00');
        $response->assertDontSee('bank_account');
        $response->assertDontSee('iban');
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

        $response = $this->get('/planilla/empleados');

        $response->assertForbidden();
    }

    public function test_response_never_contains_salary_or_bank_data(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.empleados.ver', 'label' => 'Ver empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
            'status' => 'active',
            'bank_name' => 'Banco Nacional',
            'bank_account' => '123456789',
            'iban' => 'CR999999999',
        ]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->get('/planilla/empleados');

        $response->assertOk();
        $response->assertDontSee('3000.00');
        $response->assertDontSee('Banco Nacional');
        $response->assertDontSee('123456789');
        $response->assertDontSee('CR999999999');
    }
}
