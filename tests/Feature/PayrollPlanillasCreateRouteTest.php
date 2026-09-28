<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPlanillasCreateRouteTest extends TestCase
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
        $verPermission = Permission::create(['name' => 'planilla.planillas.ver', 'label' => 'Ver planillas', 'module' => 'Planilla', 'is_active' => true]);
        $crearPermission = Permission::create(['name' => 'planilla.planillas.crear', 'label' => 'Crear planillas', 'module' => 'Planilla', 'is_active' => true]);
        $role->permissions()->attach([$verPermission->id, $crearPermission->id]);
        $user->companies()->updateExistingPivot($companyA->id, ['role_id' => $role->id]);

        session(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);
        $this->actingAs($user);

        // Test create form
        $response = $this->get('/planilla/planillas/crear');
        $response->assertOk();
        $response->assertSee('Nueva planilla');
        $response->assertSee('payroll_number');
        $response->assertSee('period_start');
        $response->assertSee('period_end');
        $response->assertSee('frequency');
        $response->assertSee('semanal');
        $response->assertSee('quincenal');
        $response->assertSee('mensual');
        $response->assertDontSee('company_id');
        $response->assertDontSee('status');

        // Test link in index
        $response = $this->get('/planilla/planillas');
        $response->assertOk();
        $response->assertSee('Nueva planilla');
    }

    public function test_without_permission_receives_403_and_no_link(): void
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

        // Create form returns 403
        $response = $this->get('/planilla/planillas/crear');
        $response->assertForbidden();

        // Index doesn't show link
        $response = $this->get('/planilla/planillas');
        $response->assertOk();
        $response->assertDontSee('Nueva planilla');
    }
}