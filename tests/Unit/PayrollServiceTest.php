<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_only_payrolls_of_active_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $companyB->id, 'name' => 'Sucursal B', 'code' => 'SB', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $payrollA = Payroll::create([
            'company_id' => $companyA->id,
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
            'status' => 'borrador',
        ]);
        Payroll::create([
            'company_id' => $companyA->id,
            'payroll_number' => 'NOM-002',
            'period_start' => '2024-02-01',
            'period_end' => '2024-02-28',
            'frequency' => 'mensual',
            'status' => 'borrador',
        ]);
        $payrollB = Payroll::create([
            'company_id' => $companyB->id,
            'payroll_number' => 'NOM-003',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
            'status' => 'borrador',
        ]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollService;
        $payrolls = $service->list();

        $this->assertCount(2, $payrolls);
        $this->assertTrue($payrolls->contains('id', $payrollA->id));
        $this->assertFalse($payrolls->contains('id', $payrollB->id));
    }

    public function test_create_valid_payroll_in_active_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollService;

        $payroll = $service->create([
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $this->assertSame($companyA->id, $payroll->company_id);
        $this->assertSame('NOM-001', $payroll->payroll_number);
        $this->assertSame('borrador', $payroll->status);
        $this->assertDatabaseHas('payrolls', [
            'id' => $payroll->id,
            'company_id' => $companyA->id,
            'payroll_number' => 'NOM-001',
        ]);
    }

    public function test_create_rejects_injected_company_id_and_status(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollService;

        $payroll = $service->create([
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
            'company_id' => $companyB->id,
            'status' => 'cerrada',
        ]);

        $this->assertSame($companyA->id, $payroll->company_id);
        $this->assertNotSame($companyB->id, $payroll->company_id);
        $this->assertSame('borrador', $payroll->status);
        $this->assertNotSame('cerrada', $payroll->status);
    }

    public function test_create_throws_on_invalid_data(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollService;

        $this->expectException(ValidationException::class);

        $service->create([
            'payroll_number' => '',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);
    }

    public function test_create_throws_on_period_end_before_start(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollService;

        $this->expectException(ValidationException::class);

        $service->create([
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-31',
            'period_end' => '2024-01-01',
            'frequency' => 'mensual',
        ]);
    }

    public function test_create_throws_on_invalid_frequency(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $service = new PayrollService;

        $this->expectException(ValidationException::class);

        $service->create([
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'invalida',
        ]);
    }

    public function test_list_throws_on_invalid_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        session(['active_company_id' => 999, 'active_branch_id' => 999]);

        $service = new PayrollService;

        $this->expectException(AuthorizationException::class);

        $service->list();
    }

    public function test_create_throws_on_invalid_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        session(['active_company_id' => 999, 'active_branch_id' => 999]);

        $service = new PayrollService;

        $this->expectException(AuthorizationException::class);

        $service->create([
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);
    }
}
