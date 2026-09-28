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

class PayrollPlanillasStoreRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_creates_draft_in_active_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.planillas.crear', 'label' => 'Crear planillas', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/planillas', [
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $response->assertRedirect(route('planilla.planillas.index'));
        $this->assertDatabaseHas('payrolls', [
            'company_id' => $companyA->id,
            'payroll_number' => 'NOM-001',
            'status' => 'borrador',
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
        $verPermission = Permission::create(['name' => 'planilla.planillas.ver', 'label' => 'Ver planillas', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($verPermission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/planillas', [
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('payrolls', ['payroll_number' => 'NOM-001']);
    }

    public function test_injected_company_id_and_status_ignored(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $companyB = Company::create(['trade_name' => 'Empresa B', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.planillas.crear', 'label' => 'Crear planillas', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/planillas', [
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-01',
            'period_end' => '2024-01-31',
            'frequency' => 'mensual',
            'company_id' => $companyB->id,
            'status' => 'cerrada',
        ]);

        $response->assertRedirect(route('planilla.planillas.index'));
        $this->assertDatabaseHas('payrolls', [
            'company_id' => $companyA->id,
            'payroll_number' => 'NOM-001',
            'status' => 'borrador',
        ]);
        $this->assertDatabaseMissing('payrolls', ['company_id' => $companyB->id]);
    }

    public function test_invalid_period_does_not_create_row(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $companyA = Company::create(['trade_name' => 'Empresa A', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $companyA->id, 'name' => 'Sucursal A', 'code' => 'SA', 'is_active' => true]);

        $user->companies()->attach($companyA->id);
        $user->branches()->attach($branchA->id);

        $role = Role::create(['company_id' => $companyA->id, 'name' => 'Test Role', 'is_active' => true]);
        $permission = Permission::create(['name' => 'planilla.planillas.crear', 'label' => 'Crear planillas', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach($permission->id);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        $response = $this->post('/planilla/planillas', [
            'payroll_number' => 'NOM-001',
            'period_start' => '2024-01-31',
            'period_end' => '2024-01-01',
            'frequency' => 'mensual',
        ]);

        $response->assertSessionHasErrors('period_end');
        $this->assertDatabaseMissing('payrolls', ['payroll_number' => 'NOM-001']);
    }
}