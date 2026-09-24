<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportSupplierTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private ProductCategory $category;
    private Brand $brand;
    private Unit $unit;
    private ProductImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'trade_name' => 'Supplier Import '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Principal',
            'code' => 'PSI-'.$this->company->id,
            'is_active' => true,
        ]);
        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company->id);
        $this->category = ProductCategory::create([
            'company_id' => $this->company->id, 'name' => 'General',
            'slug' => 'general-sup-'.$this->company->id, 'is_active' => true,
        ]);
        $this->brand = Brand::create([
            'company_id' => $this->company->id, 'name' => 'Marca', 'is_active' => true,
        ]);
        $this->unit = Unit::create([
            'company_id' => $this->company->id, 'name' => 'Unidad', 'abbreviation' => 'UN',
            'slug' => 'unidad-sup-'.$this->company->id, 'allows_decimals' => false, 'is_active' => true,
        ]);
        $this->importService = new ProductImportService();
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Proveedor '.uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'codigo_interno' => 'SUP-'.uniqid(),
            'nombre' => 'Producto proveedor',
            'categoria' => $this->category->name,
            'marca' => $this->brand->name,
            'unidad' => $this->unit->name,
            'tipo_producto' => 'product',
            'costo' => '1000',
            'precio_venta' => '1500',
            'impuesto' => '13',
            'controla_inventario' => 'Sí',
            'permite_stock_negativo' => 'No',
            'imprime_etiqueta' => 'No',
            'activo' => 'Sí',
        ], $overrides);
    }

    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sup-import_').'.xlsx';
        $matrix = [ProductImportService::HEADERS];
        foreach ($rows as $row) {
            $line = [];
            foreach (ProductImportService::HEADERS as $header) {
                $line[] = $row[rtrim($header, '*')] ?? '';
            }
            $matrix[] = $line;
        }
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($matrix);
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_valid_supplier_preview_and_confirm_assigns_primary_active_relation(): void
    {
        $supplier = $this->supplier(['name' => 'Distribuidora Norte']);
        $path = $this->file([$this->row([
            'proveedor' => 'Distribuidora Norte',
            'codigo_producto_proveedor' => 'SKU-PROV-9',
        ])]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertTrue($rows[0]['valid'], json_encode($rows[0]['errors']));
        $this->assertSame($supplier->id, $rows[0]['supplier_id']);
        $this->assertSame('SKU-PROV-9', $rows[0]['supplier_product_code']);

        $this->importService->confirm(['company_id' => $this->company->id, 'rows' => $rows], $this->company->id);

        $product = Product::where('company_id', $this->company->id)->sole();
        $relation = ProductSupplier::where('product_id', $product->id)->sole();
        $this->assertSame($supplier->id, $relation->supplier_id);
        $this->assertSame($this->company->id, $relation->company_id);
        $this->assertSame('SKU-PROV-9', $relation->supplier_product_code);
        $this->assertTrue((bool) $relation->is_primary);
        $this->assertTrue((bool) $relation->is_active);

        unlink($path);
    }

    public function test_supplier_matches_commercial_name_normalized(): void
    {
        $supplier = $this->supplier(['name' => 'Razon Social SA', 'commercial_name' => 'Comercial del Sur']);
        $path = $this->file([$this->row(['proveedor' => '  comercial   DEL sur '])]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertTrue($rows[0]['valid'], json_encode($rows[0]['errors']));
        $this->assertSame($supplier->id, $rows[0]['supplier_id']);

        unlink($path);
    }

    public function test_missing_supplier_reports_clear_row_error_without_creating_suppliers(): void
    {
        $path = $this->file([$this->row(['proveedor' => 'Proveedor Fantasma', 'codigo_producto_proveedor' => 'X-1'])]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertFalse($rows[0]['valid']);
        $this->assertSame('proveedor', $rows[0]['errors'][0]['field']);
        $this->assertStringContainsString('no existe', $rows[0]['errors'][0]['message']);
        $this->assertSame(0, Supplier::count());

        unlink($path);
    }

    public function test_ambiguous_supplier_reports_clear_row_error(): void
    {
        $this->supplier(['name' => 'Duplicado']);
        $other = $this->supplier(['name' => 'Otro Nombre', 'commercial_name' => 'Duplicado']);
        $this->assertNotSame(Supplier::where('name', 'Duplicado')->sole()->id, $other->id);

        $path = $this->file([$this->row(['proveedor' => 'Duplicado'])]);
        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertFalse($rows[0]['valid']);
        $this->assertSame('proveedor', $rows[0]['errors'][0]['field']);
        $this->assertStringContainsString('ambiguo', $rows[0]['errors'][0]['message']);

        unlink($path);
    }

    public function test_supplier_is_company_scoped(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Otra '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);
        Supplier::create([
            'company_id' => $otherCompany->id, 'name' => 'Solo Otra Empresa', 'is_active' => true,
        ]);

        $path = $this->file([$this->row(['proveedor' => 'Solo Otra Empresa'])]);
        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertFalse($rows[0]['valid']);
        $this->assertSame('proveedor', $rows[0]['errors'][0]['field']);

        unlink($path);
    }

    public function test_inactive_supplier_is_not_matched(): void
    {
        $this->supplier(['name' => 'Inactivo Ya', 'is_active' => false]);
        $path = $this->file([$this->row(['proveedor' => 'Inactivo Ya'])]);
        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertFalse($rows[0]['valid']);
        $this->assertSame('proveedor', $rows[0]['errors'][0]['field']);

        unlink($path);
    }

    public function test_empty_supplier_columns_do_not_fail_and_create_no_relation(): void
    {
        $path = $this->file([$this->row(['proveedor' => '', 'codigo_producto_proveedor' => ''])]);
        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertTrue($rows[0]['valid'], json_encode($rows[0]['errors']));
        $this->assertNull($rows[0]['supplier_id']);

        $this->importService->confirm(['company_id' => $this->company->id, 'rows' => $rows], $this->company->id);
        $this->assertSame(0, ProductSupplier::count());

        unlink($path);
    }

    public function test_supplier_product_code_without_supplier_is_rejected(): void
    {
        $path = $this->file([$this->row(['codigo_producto_proveedor' => 'SIN-PROV'])]);
        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertFalse($rows[0]['valid']);
        $this->assertSame('codigo_producto_proveedor', $rows[0]['errors'][0]['field']);

        unlink($path);
    }

    public function test_old_template_without_supplier_columns_still_works(): void
    {
        $headers = [
            'codigo_interno*', 'nombre*', 'categoria*', 'subcategoria_subrubro', 'marca', 'unidad*', 'tipo_producto*',
            'estilo', 'talla', 'color',
            'codigo_barras_principal', 'codigos_barras_adicionales', 'cabys', 'descripcion_corta',
            'descripcion', 'costo*', 'precio_venta*', 'precio_mayorista', 'precio_especial',
            'precio_a', 'precio_b', 'precio_c', 'impuesto*', 'controla_inventario',
            'permite_stock_negativo', 'imprime_etiqueta', 'activo',
        ];
        $row = $this->row();
        $path = tempnam(sys_get_temp_dir(), 'sup-old_').'.xlsx';
        $matrix = [$headers];
        $line = [];
        foreach ($headers as $header) {
            $line[] = $row[rtrim($header, '*')] ?? '';
        }
        $matrix[] = $line;
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($matrix);
        (new Xlsx($spreadsheet))->save($path);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertTrue($rows[0]['valid'], json_encode($rows[0]['errors']));
        $this->assertNull($rows[0]['supplier_id']);
        $this->assertSame(1, $this->importService->confirm(['company_id' => $this->company->id, 'rows' => $rows], $this->company->id));
        $this->assertSame(0, ProductSupplier::count());

        unlink($path);
    }

    public function test_product_supplier_model_rejects_cross_company_assignment(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Cruzada '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);
        $foreignSupplier = Supplier::create([
            'company_id' => $otherCompany->id, 'name' => 'Ajena', 'is_active' => true,
        ]);
        $product = Product::create([
            'company_id' => $this->company->id, 'category_id' => $this->category->id,
            'unit_id' => $this->unit->id, 'name' => 'P', 'internal_code' => 'P-CROSS',
            'product_type' => 'product', 'cost' => '1', 'sale_price' => '2', 'tax_rate' => '13',
            'track_inventory' => true, 'is_active' => true,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        ProductSupplier::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'supplier_id' => $foreignSupplier->id,
            'is_primary' => true,
            'is_active' => true,
        ]);
    }
}
