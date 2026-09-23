<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
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
        // Sale in the previous year to confirm year boundary excludes it.
        $this->sale($company, $branch, $user, '2025-12-31 06:00:00', '77.70');
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        foreach (['today' => ['100.1000', 1], 'week' => ['800.8000', 3], 'month' => ['1101.1000', 4], 'year' => ['2402.4000', 6]] as $period => [$total, $count]) {
            $this->get(route('dashboard', ['period' => $period]))->assertOk()
                ->assertViewHas('dashboardSummary', fn ($summary) => $summary['sales_total'] === $total && $summary['sales_count'] === $count);
        }
    }

    public function test_custom_period_filters_by_date_range_in_company_timezone(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->travelTo(Carbon::parse('2026-09-08 18:00:00', 'UTC'));
        $this->sale($company, $branch, $user, '2026-09-01 06:00:00', '300.30');
        $this->sale($company, $branch, $user, '2026-09-08 05:59:59', '200.20');
        $this->sale($company, $branch, $user, '2026-09-08 06:00:00', '100.10');
        $this->sale($company, $branch, $user, '2026-09-09 06:00:00', '500.50');
        $this->sale($company, $branch, $user, '2025-12-31 06:00:00', '77.70');
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('dashboard', ['period' => 'custom', 'date_from' => '2026-09-01', 'date_to' => '2026-09-08']))->assertOk()
            ->assertViewHas('dashboardSummary', fn ($s) => $s['sales_total'] === '600.6000' && $s['sales_count'] === 3);
        $this->get(route('dashboard', ['period' => 'custom', 'date_from' => '2026-09-07', 'date_to' => '2026-09-07']))->assertOk()
            ->assertViewHas('dashboardSummary', fn ($s) => $s['sales_total'] === '200.2000' && $s['sales_count'] === 1);
        $this->get(route('dashboard', ['period' => 'custom', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->assertOk()
            ->assertViewHas('dashboardSummary', fn ($s) => $s['sales_total'] === '1101.1000' && $s['sales_count'] === 4);
    }

    public function test_custom_period_requires_valid_date_range_and_period_is_validated(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('dashboard', ['period' => 'custom']))->assertRedirect()->assertSessionHasErrors(['date_from', 'date_to']);
        $this->get(route('dashboard', ['period' => 'custom', 'date_from' => '2026-09-08', 'date_to' => '2026-09-01']))->assertRedirect()->assertSessionHasErrors('date_to');
        $this->get(route('dashboard', ['period' => 'custom', 'date_from' => 'invalid', 'date_to' => '2026-09-01']))->assertRedirect()->assertSessionHasErrors('date_from');
        $this->get(route('dashboard', ['period' => 'invalid']))->assertRedirect()->assertSessionHasErrors('period');
    }

    public function test_period_selector_shows_year_custom_and_date_inputs_and_keeps_branch(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Año')
            ->assertSee('Personalizado')
            ->assertSee('name="date_from"', false)
            ->assertSee('name="date_to"', false);
        $this->get(route('dashboard', ['branch_id' => $branch->id, 'period' => 'week']))->assertOk()
            ->assertSee('branch_id='.$branch->id)
            ->assertSee('value="'.$branch->id.'"', false);
    }

    public function test_account_summaries_do_not_change_with_period(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $today = $this->get(route('dashboard'))->assertOk()->viewData('creditSummary');
        $year = $this->get(route('dashboard', ['period' => 'year']))->assertOk()->viewData('creditSummary');
        $this->assertSame($today, $year);
    }

    public function test_movements_include_credit_notes_for_the_period(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->travelTo(Carbon::parse('2026-09-08 18:00:00', 'UTC'));
        $sale = $this->sale($company, $branch, $user, '2026-09-08 06:00:00', '100.10');
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente NC', 'is_active' => true]);
        $return = SaleReturn::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'return_number' => 'DEV-DASH-1',
            'reason' => 'Prueba dashboard',
            'status' => SaleReturn::STATUS_COMPLETED,
            'returned_at' => '2026-09-08 08:00:00',
        ]);
        CreditNote::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'sale_return_id' => $return->id,
            'credit_note_number' => 'NC-DASH-TEST',
            'currency_code' => 'CRC',
            'issued_amount' => '50.0000',
            'offset_amount' => '0.0000',
            'applied_amount' => '0.0000',
            'balance' => '50.0000',
            'status' => CreditNote::STATUS_ISSUED,
            'reason' => 'Nota de prueba',
            'issued_by' => $user->id,
            'issued_at' => '2026-09-08 10:00:00',
            'requires_ar_review' => false,
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Movimientos del período')
            ->assertSee('Nota de crédito')
            ->assertSee('NC-DASH-TEST')
            ->assertSee('Emitida')
            ->assertSee('Venta')
            ->assertSee($sale->sale_number);
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

    public function test_global_selector_restores_stock_and_transfer_origin_without_changing_dashboard(): void
    {
        [$company, $sanRamon, $user] = $this->context();
        $sanRamon->update(['name' => 'San Ramón']);
        $liberia = Branch::create(['company_id' => $company->id, 'name' => 'Liberia', 'code' => 'LIB', 'is_active' => true]);
        $user->branches()->attach($liberia);
        $role = Role::findOrFail($user->companies()->first()->pivot->role_id);
        foreach (['productos.ver', 'inventario.ver', 'inventario.transferir', 'compras.ver'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Inventario', 'is_active' => true]));
        }
        $category = \App\Models\ProductCategory::create(['company_id' => $company->id, 'name' => 'General', 'slug' => 'general', 'is_active' => true]);
        $unit = \App\Models\Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'unidad', 'is_active' => true]);
        $product = \App\Models\Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto de prueba', 'internal_code' => 'TEST', 'cost' => 10, 'sale_price' => 20, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
        $brand = \App\Models\Brand::create(['company_id' => $company->id, 'name' => 'Marca', 'is_active' => true]);
        $product->update(['brand_id' => $brand->id]);
        $product->branches()->attach([$sanRamon->id => ['stock' => 12], $liberia->id => ['stock' => 0]]);
        $before = \DB::table('branch_product')->orderBy('branch_id')->get()->toJson();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        $this->get(route('dashboard'))->assertOk()->assertSessionMissing('active_branch_id')->assertDontSee('id="header-branch"', false);
        foreach (['productos.index', 'inventario.index', 'compras.index', 'transferencias.create'] as $route) {
            $response = $this->get(route($route))->assertOk()->assertSee('id="header-branch"', false);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $options = (new \DOMXPath($dom))->query('//select[@id="header-branch"]/option');
            $values = [];
            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
            }
            $this->assertEqualsCanonicalizing(['', (string) $sanRamon->id, (string) $liberia->id], $values);
        }
        foreach ([[$sanRamon, 12], [$liberia, 0]] as [$branch, $stock]) {
            $this->from(route('productos.index'))->post(route('branch.active.update'), ['branch_id' => $branch->id])
                ->assertRedirect(route('productos.index'))->assertSessionHas('active_branch_id', $branch->id);
            foreach (['productos.index', 'inventario.index'] as $route) {
                $this->get(route($route))->assertOk()->assertViewHas('products', fn ($products) => $products->count() === 1 && $products->first()->id === $product->id && bccomp((string) $products->first()->branch_stock, (string) $stock, 4) === 0);
            }
            $this->get(route('transferencias.create'))->assertOk()
                ->assertViewHas('fromBranch', fn ($origin) => $origin->id === $branch->id)
                ->assertViewHas('branches', fn ($destinations) => $destinations->count() === 1 && ! $destinations->contains('id', $branch->id));
            foreach (['all', $sanRamon->id, $liberia->id] as $filter) {
                $this->get(route('dashboard', ['branch_id' => $filter]))->assertOk()
                    ->assertDontSee('id="header-branch"', false)->assertSessionHas('active_branch_id', $branch->id);
            }
        }
        $this->assertSame($before, \DB::table('branch_product')->orderBy('branch_id')->get()->toJson());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_global_selection_rejects_foreign_inactive_and_unassigned_branches(): void
    {
        [$company, $branch, $user] = $this->context();
        [, $foreign] = $this->context();
        $inactive = Branch::create(['company_id' => $company->id, 'name' => 'Inactiva', 'code' => 'I', 'is_active' => false]);
        $unassigned = Branch::create(['company_id' => $company->id, 'name' => 'No asignada', 'code' => 'N', 'is_active' => true]);
        $user->branches()->attach([$inactive->id, $foreign->id]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        foreach ([$foreign, $inactive, $unassigned] as $invalid) {
            $this->post(route('branch.active.update'), ['branch_id' => $invalid->id])
                ->assertNotFound()->assertSessionHas('active_branch_id', $branch->id);
        }
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
