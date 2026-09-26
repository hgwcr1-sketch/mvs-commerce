<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fk_company_id_restrict_on_delete(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '123456789',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $this->expectException(QueryException::class);
        $company->delete();
    }

    public function test_unique_employee_code_per_company(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '123456789',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $this->expectException(QueryException::class);
        Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '987654321',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
        ]);
    }

    public function test_unique_identification_per_company(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '123456789',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $this->expectException(QueryException::class);
        Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-002',
            'identification' => '123456789',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
        ]);
    }

    public function test_same_employee_code_allowed_in_different_company(): void
    {
        $companyA = Company::create(['trade_name' => 'Company A', 'is_active' => true]);
        $companyB = Company::create(['trade_name' => 'Company B', 'is_active' => true]);

        Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);
        $employeeB = Employee::create([
            'company_id' => $companyB->id,
            'employee_code' => 'EMP-001',
            'identification' => '222222222',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
        ]);

        $this->assertDatabaseHas('employees', ['id' => $employeeB->id, 'employee_code' => 'EMP-001']);
    }

    public function test_same_identification_allowed_in_different_company(): void
    {
        $companyA = Company::create(['trade_name' => 'Company A', 'is_active' => true]);
        $companyB = Company::create(['trade_name' => 'Company B', 'is_active' => true]);

        Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '123456789',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);
        $employeeB = Employee::create([
            'company_id' => $companyB->id,
            'employee_code' => 'EMP-002',
            'identification' => '123456789',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'position' => 'Designer',
            'hire_date' => '2024-01-01',
            'base_salary' => 2800.00,
        ]);

        $this->assertDatabaseHas('employees', ['id' => $employeeB->id, 'identification' => '123456789']);
    }

    public function test_weekly_work_days_nullable(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '123456789',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
            'weekly_work_days' => null,
        ]);

        $this->assertNull($employee->fresh()->weekly_work_days);
    }
}
