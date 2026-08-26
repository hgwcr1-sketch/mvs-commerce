<?php

namespace Tests\Feature;

use App\Http\Controllers\DataExportController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Exports\DataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_route_uses_one_controller_and_supported_formats(): void
    {
        $route = Route::getRoutes()->getByName('data-center.exports.download');
        $this->assertSame(DataExportController::class.'@download', $route->getActionName());
        $this->assertContains('permission:reportes.exportar', $route->gatherMiddleware());
        $this->assertSame('xlsx|csv', $route->wheres['format']);
    }

    public function test_export_center_only_shows_datasets_allowed_by_domain_permissions(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.exportar', 'productos.ver']);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports'))->assertOk()
            ->assertSee('data-export-dataset="products"', false)
            ->assertDontSee('data-export-dataset="customers"', false)
            ->assertSee('grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3', false)
            ->assertSee('min-h-11', false);

        [$emptyCompany, $emptyBranch, $emptyUser] = $this->context(['reportes.exportar']);
        $this->actingAs($emptyUser)->withSession($this->activeSession($emptyCompany, $emptyBranch))
            ->get(route('data-center.exports'))->assertOk()
            ->assertSee('necesita acceso de lectura');
    }

    public function test_csv_products_are_utf8_and_isolated_by_company(): void
    {
        [$company, $branch, $user, $category, $unit] = $this->context(['reportes.exportar', 'productos.ver'], true);
        [$otherCompany, $otherBranch, $otherUser, $otherCategory, $otherUnit] = $this->context([], true);
        $product = $this->product($company, $category, $unit, 'MVS-UNO', 'Artículo Ñ');
        $this->product($otherCompany, $otherCategory, $otherUnit, 'OTRO-DOS', 'Producto ajeno');
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '744100000001', 'barcode_type' => 'EAN13', 'is_primary' => false, 'is_active' => true]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports.download', ['products', 'csv']));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('MVS-UNO', $content);
        $this->assertStringContainsString('Artículo Ñ', $content);
        $this->assertStringContainsString('744100000001', $content);
        $this->assertStringNotContainsString('OTRO-DOS', $content);
    }

    public function test_xlsx_customers_has_stable_headers_and_company_isolation(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.exportar', 'clientes.ver']);
        [$otherCompany] = $this->context([]);
        Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Uno',
            'identification_type' => 'national', 'identification' => '101110111', 'is_active' => true]);
        Customer::create(['company_id' => $otherCompany->id, 'customer_type' => 'individual', 'name' => 'Cliente Ajeno',
            'identification_type' => 'national', 'identification' => '202220222', 'is_active' => true]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.exports.download', ['customers', 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'customers-export-');
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame(['Tipo identificación', 'Identificación', 'Nombre', 'Nombre comercial'], array_slice($rows[0], 0, 4));
        $this->assertSame('Cliente Uno', $rows[1][2]);
        $this->assertCount(2, $rows);
    }

    public function test_each_dataset_requires_its_read_permission_in_addition_to_export_permission(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.exportar']);
        foreach (array_keys(DataExportService::DATASETS) as $dataset) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('data-center.exports.download', [$dataset, 'csv']))->assertForbidden();
        }
    }

    public function test_inventory_export_uses_selected_authorized_branch_and_real_stock(): void
    {
        [$company, $branch, $user, $category, $unit] = $this->context([
            'reportes.exportar', 'inventario.ver', 'inventario.ver_otras_sucursales',
        ], true);
        $second = Branch::create(['company_id' => $company->id, 'name' => 'Segunda', 'code' => 'S'.uniqid(), 'is_active' => true]);
        $user->branches()->attach($second->id);
        $product = $this->product($company, $category, $unit, 'INV-1', 'Inventariable');
        foreach ([[$branch, 3], [$second, 9]] as [$target, $stock]) {
            DB::table('branch_product')->insert(['branch_id' => $target->id, 'product_id' => $product->id, 'stock' => $stock,
                'minimum_stock' => 1, 'maximum_stock' => 12, 'created_at' => now(), 'updated_at' => now()]);
        }

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(
            route('data-center.exports.download', ['inventory', 'csv']).'?branch_id='.$second->id,
        );
        $content = $response->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-1,Inventariable', $content);
        $this->assertStringContainsString(',9.00,1,12', $content);

        [$otherCompany, $otherBranch] = $this->context([]);
        $this->get(route('data-center.exports.download', ['inventory', 'csv']).'?branch_id='.$otherBranch->id)
            ->assertNotFound();
    }

    public function test_all_declared_datasets_can_generate_csv_without_business_mutations(): void
    {
        $permissions = ['reportes.exportar', ...array_column(DataExportService::DATASETS, 'permission')];
        [$company, $branch, $user] = $this->context(array_values(array_unique($permissions)));

        foreach (array_keys(DataExportService::DATASETS) as $dataset) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('data-center.exports.download', [$dataset, 'csv']))->assertOk();
        }

        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    private function context(array $permissions, bool $catalog = false): array
    {
        $company = Company::create(['trade_name' => 'Export '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Export '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Centro de Datos', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
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

        return [$company, $branch, $user, $category, $unit];
    }

    private function product(Company $company, ProductCategory $category, Unit $unit, string $code, string $name): Product
    {
        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => $name, 'internal_code' => $code, 'cost' => 10, 'sale_price' => 20, 'tax_rate' => 13,
            'track_inventory' => true, 'is_active' => true]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
