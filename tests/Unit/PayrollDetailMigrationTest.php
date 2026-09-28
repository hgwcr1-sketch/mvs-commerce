<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayrollDetailMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_insert_with_same_company_references(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $detail = PayrollDetail::create([
            'company_id' => $company->id,
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
            'base_salary' => 3000.00,
            'gross_salary' => 3200.00,
            'net_salary' => 2850.00,
            'worked_days' => 22.00,
            'vacation_days' => 0.00,
        ]);

        $this->assertDatabaseHas('payroll_details', [
            'id' => $detail->id,
            'company_id' => $company->id,
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
        ]);
        $this->assertNotNull($detail->created_at);
        $this->assertNotNull($detail->updated_at);
    }

    public function test_rejects_cross_company_payroll_reference(): void
    {
        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $companyA->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $employee = Employee::create([
            'company_id' => $companyB->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $this->expectException(QueryException::class);
        PayrollDetail::create([
            'company_id' => $companyB->id,
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_rejects_cross_company_employee_reference(): void
    {
        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $companyB->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $employee = Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $this->expectException(QueryException::class);
        PayrollDetail::create([
            'company_id' => $companyB->id,
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_unique_payroll_employee_per_company(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        PayrollDetail::create([
            'company_id' => $company->id,
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
        ]);

        $this->expectException(QueryException::class);
        PayrollDetail::create([
            'company_id' => $company->id,
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_same_employee_different_payroll_allowed(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll1 = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $payroll2 = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-002',
            'period_start' => '2024-02-01',
            'period_end' => '2024-02-28',
            'frequency' => 'mensual',
        ]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        $detail1 = PayrollDetail::create([
            'company_id' => $company->id,
            'payroll_id' => $payroll1->id,
            'employee_id' => $employee->id,
        ]);

        $detail2 = PayrollDetail::create([
            'company_id' => $company->id,
            'payroll_id' => $payroll2->id,
            'employee_id' => $employee->id,
        ]);

        $this->assertDatabaseHas('payroll_details', ['id' => $detail2->id]);
    }

    public function test_migration_down_drops_table(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP-001',
            'identification' => '111111111',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'position' => 'Developer',
            'hire_date' => '2024-01-01',
            'base_salary' => 3000.00,
        ]);

        PayrollDetail::create([
            'company_id' => $company->id,
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
        ]);

        $this->artisan('migrate:rollback --path=database/migrations/2026_09_28_223415_create_payroll_details_table.php');

        $this->assertFalse(Schema::hasTable('payroll_details'));
    }
}
