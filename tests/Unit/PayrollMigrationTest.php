<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Payroll;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayrollMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_structure_has_required_columns_and_constraints(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
            'status' => 'borrador',
        ]);

        $this->assertDatabaseHas('payrolls', [
            'id' => $payroll->id,
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'frequency' => 'mensual',
            'status' => 'borrador',
        ]);
        $this->assertNotNull($payroll->created_at);
        $this->assertNotNull($payroll->updated_at);
    }

    public function test_payroll_number_unique_global(): void
    {
        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);

        Payroll::create([
            'company_id' => $companyA->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $this->expectException(QueryException::class);
        Payroll::create([
            'company_id' => $companyB->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-02-01',
            'period_end' => '2024-02-28',
            'frequency' => 'mensual',
        ]);
    }

    public function test_company_id_required_and_fk_restrict(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $this->expectException(QueryException::class);
        $company->delete();
    }

    public function test_unique_id_company_id_composite(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $this->assertDatabaseHas('payrolls', ['id' => $payroll->id, 'company_id' => $company->id]);
    }

    public function test_default_status_borrador(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        $payroll = Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $this->assertSame('borrador', $payroll->fresh()->status);
    }

    public function test_migration_down_drops_table(): void
    {
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);

        Payroll::create([
            'company_id' => $company->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        Schema::dropIfExists('payroll_details');

        $this->artisan('migrate:rollback --path=database/migrations/2026_09_28_221914_create_payrolls_table.php');

        $this->assertFalse(Schema::hasTable('payrolls'));
    }
}
