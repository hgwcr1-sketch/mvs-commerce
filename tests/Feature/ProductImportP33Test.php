<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportP33Test extends TestCase
{
    use RefreshDatabase;

    public function test_template_and_existing_export_cover_catalog_prices_and_codes(): void
    {
        [$company, $branch, $user, $category, $brand, $unit] = $this->context([
            'productos.crear', 'productos.ver', 'reportes.exportar',
        ]);
        $product = $this->product($company, $category, $brand, $unit, ['internal_code' => 'EXP-1']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '744100000099', 'barcode_type' => 'EAN13', 'is_primary' => false, 'is_active' => true]);

        $template = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('importaciones.productos.template'))->assertOk();
        $this->assertSame(ProductImportService::HEADERS, $this->spreadsheetRows($template->streamedContent())[0]);

        $export = $this->get(route('data-center.exports.download', ['products', 'xlsx']))->assertOk();
        $rows = $this->spreadsheetRows($export->streamedContent());
        $this->assertContains('Precio A', $rows[0]);
        $this->assertContains('Precio B', $rows[0]);
        $this->assertContains('Precio C', $rows[0]);
        $this->assertContains('744100000099', $rows[1]);
        $this->assertContains('permission:productos.crear', Route::getRoutes()->getByName('importaciones.productos.import')->gatherMiddleware());
    }

    public function test_preview_accepts_xlsx_xls_and_csv_without_writing(): void
    {
        [$company, $branch, $user, $category, $brand, $unit] = $this->context(['productos.crear']);
        foreach (['xlsx', 'xls', 'csv'] as $format) {
            $row = $this->validRow($category, $brand, $unit, 'FORMATO-'.Str::upper($format));
            $file = $this->productFile([$row], $format);
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
                route('importaciones.productos.preview'),
                ['product_file' => $this->uploaded($file, $format)],
            )->assertOk()->assertSee('FORMATO-'.Str::upper($format));
            $this->assertTrue(session('product_import_preview.rows.0.valid'));
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('branch_product', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_preview_reports_catalog_decimal_and_duplicate_errors_by_row_and_field(): void
    {
        [$company, $branch, $user, $category, $brand, $unit] = $this->context(['productos.crear']);
        [$otherCompany, $otherBranch, $otherUser, $otherCategory, $otherBrand, $otherUnit] = $this->context([]);
        $existing = $this->product($company, $category, $brand, $unit, [
            'internal_code' => 'EXISTE', 'barcode' => '744100000001',
        ]);
        ProductBarcode::create(['product_id' => $existing->id, 'barcode' => '744100000002', 'barcode_type' => 'EAN13', 'is_primary' => false, 'is_active' => true]);
        $rows = [
            ['EXISTE', '', $otherCategory->name, $otherBrand->name, $otherUnit->name, 'otro', '744100000001', '744100000002', '', '', '', '-1', '1.999', '', '', '', '', '', '101', 'Sí', 'No', 'No', 'Sí'],
            $this->validRow($category, $brand, $unit, 'ARCHIVO-1', '744100000010'),
            $this->validRow($category, $brand, $unit, 'archivo-1', '744100000010'),
        ];

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->productFile($rows, 'xlsx'), 'xlsx')],
        );

        $response->assertOk()->assertSee('codigo_interno')->assertSee('codigos_barras')->assertSee('precio_venta')
            ->assertSee('fila 3')->assertSee('Corrija todas las filas antes de confirmar');
        $preview = session('product_import_preview.rows');
        $this->assertFalse($preview[0]['valid']);
        $this->assertTrue($preview[1]['valid']);
        $this->assertFalse($preview[2]['valid']);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_confirmation_creates_catalog_and_barcodes_with_decimal_strings_but_no_inventory(): void
    {
        [$company, $branch, $user, $category, $brand, $unit] = $this->context(['productos.crear']);
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id, 'product_id' => $this->product($company, $category, $brand, $unit)->id,
            'stock' => '9.0000', 'minimum_stock' => 1, 'maximum_stock' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = $this->validRow($category, $brand, $unit, 'NUEVO-P33', '744100000100');
        $row[7] = '744100000101 | 744100000102';
        $row[11] = '1234.56';
        $row[12] = '2500.75';
        $row[15] = '2300.25';
        $row[16] = '2200.10';
        $row[17] = '2100.05';

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->productFile([$row], 'xlsx'), 'xlsx')],
        )->assertOk();
        $this->assertSame('1234.56', session('product_import_preview.rows.0.cost'));
        $this->post(route('importaciones.productos.import'))->assertRedirect(route('productos.index'));

        $product = Product::where('company_id', $company->id)->where('internal_code', 'NUEVO-P33')->sole();
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame($unit->id, $product->unit_id);
        $this->assertSame('1234.56', $product->cost);
        $this->assertSame('2500.75', $product->sale_price);
        $this->assertSame('2300.25', $product->price_a);
        $this->assertSame('2200.10', $product->price_b);
        $this->assertSame('2100.05', $product->price_c);
        $this->assertSame(
            ['744100000100', '744100000101', '744100000102'],
            $product->barcodes()->orderBy('id')->get()->map(fn ($barcode) => $barcode->barcode)->all(),
        );
        $this->assertDatabaseCount('branch_product', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame('9.0000', bcadd((string) DB::table('branch_product')->value('stock'), '0', 4));
        $this->assertNull(session('product_import_preview'));
    }

    public function test_real_catalog_values_are_reused_and_four_zero_decimals_are_accepted(): void
    {
        [$company, $branch, $user] = $this->context(['productos.crear']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Unas', 'slug' => 'unas-'.$company->id, 'is_active' => true]);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'General', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'General', 'abbreviation' => 'GEN', 'slug' => 'general-'.$company->id, 'allows_decimals' => false, 'is_active' => true]);
        $row = ['REAL-P33', 'Producto real', '  UNAS  ', ' GENERAL ', ' general ', 'product', '', '', '', '', '',
            '5500.0000', '1000.0000', '', '', '', '', '', '13.0000', 'Sí', 'No', 'No', 'Sí'];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->productFile([$row], 'xlsx'), 'xlsx')],
        )->assertOk();

        $preview = session('product_import_preview.rows.0');
        $this->assertTrue($preview['valid'], json_encode($preview['errors']));
        $this->assertSame($category->id, $preview['category_id']);
        $this->assertSame($brand->id, $preview['brand_id']);
        $this->assertSame($unit->id, $preview['unit_id']);
        $this->assertSame('5500', $preview['cost']);
        $this->assertSame('1000', $preview['sale_price']);

        $this->post(route('importaciones.productos.import'))->assertRedirect(route('productos.index'));
        $product = Product::where('company_id', $company->id)->where('internal_code', 'REAL-P33')->sole();
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame($unit->id, $product->unit_id);
        $this->assertSame('5500.00', $product->cost);
        $this->assertSame('1000.00', $product->sale_price);
        $this->assertSame(2, ProductCategory::where('company_id', $company->id)->count());
        $this->assertSame(2, Brand::where('company_id', $company->id)->count());
        $this->assertSame(2, Unit::where('company_id', $company->id)->count());
    }

    public function test_missing_catalogs_are_previewed_without_writes_and_created_once_on_confirmation(): void
    {
        [$company, $branch, $user] = $this->context(['productos.crear']);
        $catalogCounts = [
            ProductCategory::where('company_id', $company->id)->count(),
            Brand::where('company_id', $company->id)->count(),
            Unit::where('company_id', $company->id)->count(),
        ];
        $rows = [
            ['CAT-1', 'Producto uno', 'UNAS', 'GENERAL', 'GENERAL', 'product', '', '', '', '', '', '5500.0000', '1000.0000', '', '', '', '', '', '13.0000', 'Sí', 'No', 'No', 'Sí'],
            ['CAT-2', 'Producto dos', '  unas ', ' general ', '  GENERAL  ', 'product', '', '', '', '', '', '5500.0000', '1000.0000', '', '', '', '', '', '13.0000', 'Sí', 'No', 'No', 'Sí'],
        ];

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->productFile($rows, 'xlsx'), 'xlsx')],
        )->assertOk()->assertSee('se creará al confirmar');

        foreach (session('product_import_preview.rows') as $previewRow) {
            $this->assertTrue($previewRow['valid'], json_encode($previewRow['errors']));
            $this->assertTrue($previewRow['category_will_create']);
            $this->assertTrue($previewRow['brand_will_create']);
            $this->assertTrue($previewRow['unit_will_create']);
        }
        $this->assertSame($catalogCounts[0], ProductCategory::where('company_id', $company->id)->count());
        $this->assertSame($catalogCounts[1], Brand::where('company_id', $company->id)->count());
        $this->assertSame($catalogCounts[2], Unit::where('company_id', $company->id)->count());
        $this->assertDatabaseCount('products', 0);

        $this->post(route('importaciones.productos.import'))->assertRedirect(route('productos.index'));
        $this->assertSame($catalogCounts[0] + 1, ProductCategory::where('company_id', $company->id)->count());
        $this->assertSame($catalogCounts[1] + 1, Brand::where('company_id', $company->id)->count());
        $this->assertSame($catalogCounts[2] + 1, Unit::where('company_id', $company->id)->count());
        $this->assertSame(2, Product::where('company_id', $company->id)->count());
        $this->assertDatabaseCount('branch_product', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_catalog_creation_is_company_scoped_and_rolls_back_with_products(): void
    {
        [$company, $branch, $user] = $this->context(['productos.crear']);
        [$otherCompany] = $this->context([]);
        ProductCategory::create(['company_id' => $otherCompany->id, 'name' => 'UNAS', 'slug' => 'unas-'.$otherCompany->id, 'is_active' => true]);
        Brand::create(['company_id' => $otherCompany->id, 'name' => 'GENERAL', 'is_active' => true]);
        Unit::create(['company_id' => $otherCompany->id, 'name' => 'GENERAL', 'abbreviation' => 'GEN', 'slug' => 'general-'.$otherCompany->id, 'is_active' => true]);
        $rows = [
            ['ROLL-CAT-1', 'Primero', 'UNAS', 'GENERAL', 'GENERAL', 'product', '', '', '', '', '', '5500.0000', '1000.0000', '', '', '', '', '', '13.0000', 'Sí', 'No', 'No', 'Sí'],
            ['ROLL-CAT-2', 'Segundo', 'UNAS', 'GENERAL', 'GENERAL', 'product', '', '', '', '', '', '5500.0000', '1000.0000', '', '', '', '', '', '13.0000', 'Sí', 'No', 'No', 'Sí'],
        ];
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->productFile($rows, 'xlsx'), 'xlsx')],
        )->assertOk();
        $this->assertTrue(session('product_import_preview.rows.0.category_will_create'));

        Product::creating(function (Product $product): void {
            if ($product->internal_code === 'ROLL-CAT-2') {
                throw new \RuntimeException('fallo controlado P33');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->post(route('importaciones.productos.import'));
            $this->fail('La confirmación debía fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo controlado P33', $exception->getMessage());
        }

        $this->assertDatabaseMissing('product_categories', ['company_id' => $company->id, 'name' => 'UNAS']);
        $this->assertDatabaseMissing('brands', ['company_id' => $company->id, 'name' => 'GENERAL']);
        $this->assertDatabaseMissing('units', ['company_id' => $company->id, 'name' => 'GENERAL']);
        $this->assertDatabaseMissing('products', ['company_id' => $company->id, 'internal_code' => 'ROLL-CAT-1']);
        $this->assertDatabaseHas('product_categories', ['company_id' => $otherCompany->id, 'name' => 'UNAS']);
    }

    public function test_confirmation_revalidates_and_rolls_back_when_a_code_appears_concurrently(): void
    {
        [$company, $branch, $user, $category, $brand, $unit] = $this->context(['productos.crear']);
        $rows = [
            $this->validRow($category, $brand, $unit, 'ROLLBACK-1', '744100000201'),
            $this->validRow($category, $brand, $unit, 'ROLLBACK-2', '744100000202'),
        ];
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->productFile($rows, 'xlsx'), 'xlsx')],
        )->assertOk();
        $this->product($company, $category, $brand, $unit, ['internal_code' => 'ROLLBACK-2']);

        $this->from(route('importaciones.productos'))->post(route('importaciones.productos.import'))
            ->assertRedirect(route('importaciones.productos'))->assertSessionHasErrors('product_file');
        $this->assertDatabaseMissing('products', ['internal_code' => 'ROLLBACK-1']);
        $this->assertSame(1, Product::where('company_id', $company->id)->count());
        $this->assertDatabaseCount('product_barcodes', 0);
    }

    public function test_product_only_user_can_enter_data_center_and_permission_blocks_import(): void
    {
        [$company, $branch, $allowed] = $this->context(['productos.crear']);
        [$deniedCompany, $deniedBranch, $denied] = $this->context([]);
        $this->actingAs($allowed)->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.index'))->assertOk();
        $this->get(route('data-center.imports'))->assertOk()->assertSee('data-existing-import="products"', false);
        $this->actingAs($denied)->withSession($this->activeSession($deniedCompany, $deniedBranch))
            ->get(route('importaciones.productos'))->assertForbidden();
    }

    private function context(array $permissions): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'Productos '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Productos '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Productos', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$suffix, 'slug' => 'categoria-'.$suffix, 'is_active' => true]);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Marca '.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'allows_decimals' => true, 'is_active' => true]);

        return [$company, $branch, $user, $category, $brand, $unit];
    }

    private function product(Company $company, ProductCategory $category, Brand $brand, Unit $unit, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $company->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'unit_id' => $unit->id,
            'name' => 'Producto '.uniqid(), 'internal_code' => 'SKU-'.uniqid(), 'product_type' => 'product',
            'cost' => '10.00', 'sale_price' => '20.00', 'tax_rate' => '13.00', 'track_inventory' => true, 'is_active' => true,
        ], $overrides));
    }

    private function validRow(ProductCategory $category, Brand $brand, Unit $unit, string $code, ?string $barcode = null): array
    {
        return [$code, 'Producto '.$code, $category->name, $brand->name, $unit->abbreviation, 'product', $barcode, '',
            '1234567890123', 'Descripción corta', 'Descripción', '100.25', '200.50', '190.00', '180.00',
            '175.00', '170.00', '165.00', '13.00', 'Sí', 'No', 'Sí', 'Sí'];
    }

    private function productFile(array $rows, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'products-').'.'.$format;
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([ProductImportService::HEADERS], $rows));
        match ($format) {
            'xlsx' => (new Xlsx($spreadsheet))->save($path),
            'xls' => (new Xls($spreadsheet))->save($path),
            'csv' => (new Csv($spreadsheet))->save($path),
        };

        return $path;
    }

    private function uploaded(string $path, string $format): UploadedFile
    {
        $mime = match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
        };

        return new UploadedFile($path, 'productos.'.$format, $mime, null, true);
    }

    private function spreadsheetRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'spreadsheet-');
        file_put_contents($path, $content);

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
