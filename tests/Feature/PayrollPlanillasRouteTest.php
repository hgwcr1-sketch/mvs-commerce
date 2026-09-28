<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPlanillasRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_sees_only_active_company_payrolls(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $companyB->id, 'name' => 'Sucursal B', 'code' => 'SB', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.planillas.ver', 'label' => 'Ver planillas', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

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
            'status' => 'calculada',
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

        $response = $this->get('/planilla/planillas');

        $response->assertOk();
        $response->assertSee($payrollA->payroll_number);
        $response->assertSee('01/01/2024 - 31/01/2024');
        $response->assertSee('mensual');
        $response->assertSee('Borrador');
        $response->assertDontSee($payrollB->payroll_number);
    }

    public function test_without_permission_receives_403_and_no_sidebar_link(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $verEmpleados = Permission::create(['name' => 'planilla.empleados.ver', 'label' => 'Ver empleados', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($verEmpleados->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        // Route returns 403
        $response = $this->get('/planilla/planillas');
        $response->assertForbidden();

        // Check sidebar doesn't show link (via index page)
        $response = $this->get('/planilla/empleados');
        $response->assertOk();
        $response->assertDontSee('Planillas');
    }
}