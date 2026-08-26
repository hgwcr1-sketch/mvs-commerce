<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportCenterController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Reports\EssentialReportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class EssentialReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_center_has_one_internal_route_and_no_new_sidebar_entry(): void
    {
        $this->assertSame(ReportCenterController::class.'@show', Route::getRoutes()->getByName('data-center.reports.show')->getActionName());
        $sidebar = file_get_contents(resource_path('views/components/navigation/sidebar.blade.php'));
        $this->assertSame(1, substr_count($sidebar, 'route="data-center.index"'));
        $this->assertStringNotContainsString('route="data-center.reports"', $sidebar);
    }

    public function test_index_and_each_category_require_report_and_domain_permissions(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.ver']);
        $session = $this->activeSession($company, $branch);
        $this->actingAs($user)->withSession($session)->get(route('data-center.reports'))->assertOk()
            ->assertSee('necesita permiso de lectura');

        foreach (array_keys(EssentialReportQuery::CATEGORIES) as $category) {
            $this->get(route('data-center.reports.show', $category))->assertForbidden();
        }

        [$otherCompany, $otherBranch, $otherUser] = $this->context(['ventas.ver']);
        $this->actingAs($otherUser)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->get(route('data-center.reports'))->assertForbidden();
    }

    public function test_all_categories_render_with_real_empty_queries(): void
    {
        $permissions = ['reportes.ver', ...array_column(EssentialReportQuery::CATEGORIES, 'permission')];
        [$company, $branch, $user] = $this->context($permissions);

        foreach (EssentialReportQuery::CATEGORIES as $key => $definition) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('data-center.reports.show', $key))->assertOk()
                ->assertSee($definition['label']);
        }
    }

    public function test_sales_totals_filters_and_company_branch_isolation_are_exact(): void
    {
        [$company, $branch, $user, $product] = $this->context(['reportes.ver', 'ventas.ver'], true);
        $second = Branch::create(['company_id' => $company->id, 'name' => 'Segunda', 'code' => 'S'.uniqid(), 'is_active' => true]);
        $user->branches()->attach($second->id);
        [$otherCompany, $otherBranch, $otherUser, $otherProduct] = $this->context([], true);
        $otherBranchProduct = Product::create([
            'company_id' => $company->id, 'category_id' => $product->category_id, 'unit_id' => $product->unit_id,
            'name' => 'Otro producto', 'internal_code' => 'OTRO-'.Str::lower(Str::random(6)), 'cost' => 100,
            'sale_price' => 3000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true,
        ]);
        $this->sale($company, $branch, $user, $product, 'V-1', '2026-08-10', 1000, 100, 400);
        $this->sale($company, $branch, $user, $otherBranchProduct, 'V-OTRO', '2026-08-10', 3000, 0, 100);
        $this->sale($company, $second, $user, $product, 'V-2', '2026-08-11', 2000, 0, 800);
        $this->sale($otherCompany, $otherBranch, $otherUser, $otherProduct, 'OTRA-1', '2026-08-10', 9000, 0, 100);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(
            route('data-center.reports.show', 'sales').'?branch_id='.$branch->id.'&from=2026-08-01&to=2026-08-31&product_id='.$product->id,
        );

        $response->assertOk()->assertSee('₡1,000.00')->assertSee('₡100.00')->assertSee('₡600.00')
            ->assertDontSee('₡2,000.00')->assertDontSee('₡3,000.00')->assertDontSee('₡9,000.00');
    }

    public function test_cross_company_entity_filters_are_rejected(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.ver', 'ventas.ver']);
        [$otherCompany, $otherBranch, $otherUser, $otherProduct] = $this->context([], true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.reports.show', 'sales').'?product_id='.$otherProduct->id)
            ->assertSessionHasErrors('product_id');
    }

    public function test_report_export_links_reuse_d09_and_respect_export_permission(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.ver', 'inventario.ver']);
        $session = $this->activeSession($company, $branch);
        $this->actingAs($user)->withSession($session)->get(route('data-center.reports.show', 'inventory'))
            ->assertOk()->assertDontSee(route('data-center.exports.download', ['inventory', 'xlsx']), false);

        [$exportCompany, $exportBranch, $exportUser] = $this->context(['reportes.ver', 'reportes.exportar', 'inventario.ver']);
        $this->actingAs($exportUser)->withSession($this->activeSession($exportCompany, $exportBranch))
            ->get(route('data-center.reports.show', 'inventory'))->assertOk()
            ->assertSee(route('data-center.exports.download', ['inventory', 'xlsx']), false)
            ->assertSee(route('data-center.exports.download', ['inventory', 'csv']), false);
    }

    public function test_ui_is_mobile_first_at_360_768_and_1280_patterns(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.ver', 'ventas.ver']);
        $session = $this->activeSession($company, $branch);

        $this->actingAs($user)->withSession($session)->get(route('data-center.reports'))->assertOk()
            ->assertSee('grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3', false)
            ->assertSee('min-h-48', false);
        $this->get(route('data-center.reports.show', 'sales'))->assertOk()
            ->assertSee('grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4', false)
            ->assertSee('grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6', false)
            ->assertSee('overflow-x-auto', false)->assertSee('min-h-11', false);
    }

    private function context(array $permissions, bool $catalog = false): array
    {
        $company = Company::create(['trade_name' => 'Report '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Report '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Reportes', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        if (! $catalog) {
            return [$company, $branch, $user];
        }

        $suffix = Str::lower(Str::random(8));
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General', 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'unit-'.$suffix, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 400, 'sale_price' => 1000,
            'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);

        return [$company, $branch, $user, $product];
    }

    private function sale(Company $company, Branch $branch, User $user, Product $product, string $number, string $date, float $total, float $discount, float $cost): void
    {
        $saleId = DB::table('sales')->insertGetId(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'sale_number' => $number, 'document_type' => 'electronic_ticket', 'sale_condition' => 'cash', 'status' => 'completed',
            'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => $total, 'discount_total' => $discount, 'tax_total' => 0,
            'total' => $total, 'paid_total' => $total, 'balance_due' => 0, 'completed_at' => $date.' 12:00:00', 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00']);
        DB::table('sale_items')->insert(['sale_id' => $saleId, 'product_id' => $product->id, 'product_code' => $product->internal_code,
            'description' => $product->name, 'quantity' => 1, 'unit_price' => $total + $discount, 'gross_total' => $total + $discount,
            'discount_total' => $discount, 'subtotal' => $total, 'tax_rate' => 0, 'tax_total' => 0, 'total' => $total,
            'unit_cost' => $cost, 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00']);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
