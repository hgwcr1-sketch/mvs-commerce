<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministrativeDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_consult_without_cash_but_pos_and_checkout_still_require_it(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('dashboard'))->assertOk()->assertSee('Resumen administrativo');
        $this->get(route('pos.index'))->assertRedirect(route('cash.open.create'));
        $this->postJson(route('pos.checkout'), ['checkout_token' => (string) Str::uuid(), 'items' => [['product_id' => 999, 'quantity' => 1]], 'payments' => [['payment_method_id' => 999, 'amount' => 1]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Debe abrir una sesión de caja para realizar ventas.');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_periods_use_company_timezone_and_calendar_boundaries(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->travelTo(Carbon::parse('2026-09-08 18:00:00', 'UTC'));
        // 06:00 UTC is midnight in Costa Rica.
        $this->sale($company, $branch, $user, '2026-09-08 06:00:00', '100.10');
        $this->sale($company, $branch, $user, '2026-09-08 05:59:59', '200.20');
        $this->sale($company, $branch, $user, '2026-09-01 06:00:00', '300.30');
        $this->sale($company, $branch, $user, '2026-09-01 05:59:59', '400.40');
        $this->sale($company, $branch, $user, '2026-09-09 06:00:00', '500.50');
        $this->sale($company, $branch, $user, '2026-10-01 06:00:00', '900.90');
        $this->sale($company, $branch, $user, '2026-09-08 12:00:00', '999.00', Sale::STATUS_VOIDED);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        foreach (['today' => ['100.1000', 1], 'week' => ['800.8000', 3], 'month' => ['1101.1000', 4]] as $period => [$total,$count]) {
            $this->get(route('dashboard', ['period' => $period]))->assertOk()
                ->assertViewHas('dashboardSummary', fn ($summary) => $summary['sales_total'] === $total && $summary['sales_count'] === $count);
        }
        $this->get(route('dashboard', ['period' => 'year']))->assertRedirect()->assertSessionHasErrors('period');
    }

    public function test_branch_and_consolidated_dashboard_are_isolated_by_company(): void
    {
        [$company,$branch,$user] = $this->context();
        $other = Branch::create(['company_id' => $company->id, 'name' => 'Segunda', 'code' => 'B', 'is_active' => true]);
        $user->branches()->attach($other);
        [$foreign,$foreignBranch,$foreignUser] = $this->context();
        $this->sale($company, $branch, $user, now(), '10.10');
        $this->sale($company, $other, $user, now(), '20.20');
        $this->sale($foreign, $foreignBranch, $foreignUser, now(), '99999');
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('dashboardSummary', fn ($s) => $s['sales_total'] === '30.3000')
            ->assertDontSee('Sucursal activa')->assertDontSee('Abrir caja');
        $this->post(route('branch.active.update'), ['branch_id' => 'all'])->assertRedirect(route('dashboard'))->assertSessionHas('active_branch_id', $branch->id);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('dashboardSummary', fn ($s) => $s['sales_total'] === '30.3000' && $s['branch'] === 'Todas las sucursales');
        $this->get(route('dashboard', ['branch_id' => $other->id]))->assertOk()
            ->assertViewHas('dashboardSummary', fn ($s) => $s['sales_total'] === '20.2000')
            ->assertSessionHas('active_branch_id', $branch->id);
        $this->get(route('cash.history.index'))->assertOk();
        $this->post(route('branch.active.update'), ['branch_id' => $other->id])->assertRedirect();
        $this->get(route('dashboard'))->assertViewHas('dashboardSummary', fn ($s) => $s['sales_total'] === '30.3000');
        $this->get(route('dashboard', ['branch_id' => $foreignBranch->id]))->assertNotFound();
        $this->post(route('branch.active.update'), ['branch_id' => $foreignBranch->id])->assertNotFound();
        $this->get(route('dashboard', ['branch_id' => 'all']))->assertOk()->assertSessionHas('active_branch_id', $other->id);
    }

    public function test_employee_cannot_select_all_or_receive_administrative_metrics(): void
    {
        [$company,$branch,$user] = $this->context(false);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->post(route('branch.active.update'), ['branch_id' => 'all'])->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard.seller')->assertDontSee('Resumen administrativo');
    }

    public function test_admin_without_operational_branch_can_filter_and_then_choose_to_operate(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get(route('dashboard'))->assertOk()->assertSessionMissing('active_branch_id')
            ->assertViewHas('dashboardSummary', fn ($s) => $s['branch'] === 'Todas las sucursales');
        $this->get(route('dashboard', ['branch_id' => $branch->id]))->assertOk()->assertSessionMissing('active_branch_id');
        foreach (['pos.index', 'cash.index', 'cash.open.create'] as $route) {
            $this->get(route($route))->assertOk()->assertViewIs('cash.select-branch')->assertSessionMissing('active_branch_id');
        }
        $this->from(route('pos.index'))->post(route('branch.active.update'), ['branch_id' => $branch->id])
            ->assertRedirect(route('pos.index'))->assertSessionHas('active_branch_id', $branch->id);
        $this->get(route('pos.index'))->assertRedirect(route('cash.open.create'));
        $this->get(route('cash.open.create'))->assertOk()->assertViewIs('cash.open');
        $this->get(route('dashboard'))->assertOk()->assertSessionHas('active_branch_id', $branch->id)
            ->assertViewHas('dashboardSummary', fn ($s) => $s['branch'] === 'Todas las sucursales');
    }

    public function test_invalid_operational_branch_is_not_auto_selected_for_admin(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => 999999]);
        $this->get(route('dashboard'))->assertOk()->assertSessionMissing('active_branch_id');
        $this->get(route('dashboard', ['branch_id' => 'invalid']))->assertSessionHasErrors('branch_id');
        $branch->update(['is_active' => false]);
        $this->get(route('dashboard', ['branch_id' => $branch->id]))->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertSessionMissing('active_branch_id');
    }

    public function test_dashboard_permission_does_not_grant_operational_permissions(): void
    {
        [$company, $branch, $user] = $this->context(false);
        $role = $user->companies()->first()->pivot->role_id;
        Role::findOrFail($role)->permissions()->attach(Permission::firstOrCreate(['name' => 'dashboard.admin'], ['label' => 'Admin', 'module' => 'Dashboard', 'is_active' => true]));
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('pos.index'))->assertForbidden();
        $this->get(route('cash.index'))->assertForbidden();
        $this->get(route('cash.open.create'))->assertForbidden();
    }

    public function test_company_consultation_does_not_grant_assignment_to_an_operational_branch(): void
    {
        [$company, $branch, $user] = $this->context();
        $user->branches()->detach($branch);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        $this->get(route('dashboard', ['branch_id' => $branch->id]))->assertOk()->assertSessionMissing('active_branch_id');
        $this->post(route('branch.active.update'), ['branch_id' => $branch->id])->assertNotFound();
        $this->post(route('cash.open.store'), ['cash_register_id' => 999])
            ->assertRedirect()->assertSessionHasErrors('cash_register_id');
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    private function context(bool $admin = true): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.Str::random(5), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol', 'is_active' => true]);
        foreach ($admin ? ['dashboard.admin', 'pos.acceder', 'ventas.crear', 'caja.abrir', 'caja.ver', 'caja.ver_todas'] : ['dashboard.ver'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Dashboard', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return [$company, $branch, $user];
    }

    private function sale(Company $company, Branch $branch, User $user, $date, string $total, string $status = Sale::STATUS_COMPLETED): Sale
    {
        return Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'V-'.Str::random(10), 'checkout_token' => Str::uuid(), 'request_fingerprint' => hash('sha256',Str::random()), 'status' => $status, 'currency_code' => 'CRC', 'total' => $total, 'completed_at' => $date]);
    }
}
