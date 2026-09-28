<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\PayrollEmployeeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollEmployeeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_only_employees_of_active_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $companyB->id, 'name' => 'Sucursal B', 'code' => 'SB', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $empA1 = Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);
        $empA2 = Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-002',
            'identification' => '222222222',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
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
        ]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollEmployeeService;
        $employees = $service->list();

        $this->assertCount(2, $employees);
        $this->assertTrue($employees->contains('id', $empA1->id));
        $this->assertTrue($employees->contains('id', $empA2->id));
        $this->assertFalse($employees->contains('id', $empB->id));
    }

    public function test_find_returns_employee_of_active_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $companyB->id, 'name' => 'Sucursal B', 'code' => 'SB', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $empA = Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);
        $empB = Employee::create([
            'company_id' => $companyB->id,
            'employee_code' => 'EMP-002',
            'identification' => '222222222',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
        ]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollEmployeeService;
        $found = $service->find($empA->id);

        $this->assertSame($empA->id, $found->id);
    }

    public function test_find_throws_for_employee_of_other_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $companyB->id, 'name' => 'Sucursal B', 'code' => 'SB', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $empB = Employee::create([
            'company_id' => $companyB->id,
            'employee_code' => 'EMP-002',
            'identification' => '222222222',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
        ]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollEmployeeService;

        $this->expectException(ModelNotFoundException::class);

        $service->find($empB->id);
    }

    public function test_find_throws_for_nonexistent_id(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollEmployeeService;

        $this->expectException(ModelNotFoundException::class);

        $service->find(999999);
    }

    public function test_list_throws_when_invalid_company_branch_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        session(['active_company_id' => 999, 'active_branch_id' => 999]);

        $service = new PayrollEmployeeService;

        $this->expectException(AuthorizationException::class);

        $service->list();
    }

    public function test_find_throws_when_invalid_company_branch_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        session(['active_company_id' => 999, 'active_branch_id' => 999]);

        $service = new PayrollEmployeeService;

        $this->expectException(AuthorizationException::class);

        $service->find(1);
    }
}
