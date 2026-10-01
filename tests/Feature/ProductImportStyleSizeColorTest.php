<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\Color;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Size;
use App\Models\Style;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImportStyleSizeColorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private ProductImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'trade_name' => 'Import Test Company ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Principal',
            'code' => 'P-' . $this->company->id,
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company->id);
        $this->importService = new ProductImportService(app(\App\Services\Fiscal\FiscalTaxService::class));

        Unit::create([
            'company_id' => $this->company->id,
            'name' => 'Unidad',
            'abbreviation' => 'UN',
            'slug' => 'unidad-imp-' . $this->company->id,
            'allows_decimals' => false,
            'is_active' => true,
        ]);
    }

    private function makeExcel(array $rows): string
    {
        $headers = ProductImportService::HEADERS;
        $data = [$headers];
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $key = rtrim($header, '*');
                $line[] = $row[$key] ?? '';
            }
            $data[] = $line;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($data, null, 'A1');

        $tempPath = tempnam(sys_get_temp_dir(), 'import_') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    private function wrapForConfirm(array $rows): array
    {
        return ['company_id' => $this->company->id, 'rows' => $rows];
    }

    // =========================================================
    // A. IMPORT REUTILIZA MAESTROS EXISTENTES
    // =========================================================

    public function test_import_reuses_existing_style(): void
    {
        $style = Style::create([
            'company_id' => $this->company->id,
            'name' => 'Clásico',
            'slug' => 'clasico-reuse-' . $this->company->id,
            'is_active' => true,
        ]);

        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-001',
            'nombre' => 'Producto Test',
            'categoria' => 'General',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'estilo' => 'Clásico',
            'costo' => '1000',
            'precio_venta' => '1500',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertNotNull($rows[0]['style_id']);
        $this->assertSame($style->id, $rows[0]['style_id']);
        $this->assertFalse($rows[0]['style_will_create']);

        unlink($path);
    }

    public function test_import_creates_missing_style(): void
    {
        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-002',
            'nombre' => 'Producto Test 2',
            'categoria' => 'General',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'estilo' => 'Nuevo Estilo',
            'costo' => '2000',
            'precio_venta' => '3000',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertTrue($rows[0]['style_will_create']);

        $preview = $this->wrapForConfirm($rows);
        $count = $this->importService->confirm($preview, $this->company->id);
        $this->assertSame(1, $count);

        $this->assertDatabaseHas('styles', [
            'company_id' => $this->company->id,
            'name' => 'Nuevo Estilo',
        ]);

        unlink($path);
    }

    public function test_import_reuses_existing_size(): void
    {
        $size = Size::create([
            'company_id' => $this->company->id,
            'name' => 'Mediana',
            'abbreviation' => 'M',
            'slug' => 'mediana-reuse-' . $this->company->id,
            'is_active' => true,
        ]);

        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-003',
            'nombre' => 'Producto Test 3',
            'categoria' => 'General',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'talla' => 'Mediana',
            'costo' => '1000',
            'precio_venta' => '1500',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertSame($size->id, $rows[0]['size_id']);
        $this->assertFalse($rows[0]['size_will_create']);

        unlink($path);
    }

    public function test_import_creates_missing_size(): void
    {
        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-004',
            'nombre' => 'Producto Test 4',
            'categoria' => 'General',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'talla' => 'XL',
            'costo' => '1000',
            'precio_venta' => '1500',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertTrue($rows[0]['size_will_create']);

        $preview = $this->wrapForConfirm($rows);
        $this->importService->confirm($preview, $this->company->id);

        $this->assertDatabaseHas('sizes', [
            'company_id' => $this->company->id,
            'name' => 'XL',
        ]);

        unlink($path);
    }

    public function test_import_reuses_existing_color(): void
    {
        $color = Color::create([
            'company_id' => $this->company->id,
            'name' => 'Negro',
            'hex_code' => '#000000',
            'slug' => 'negro-reuse-' . $this->company->id,
            'is_active' => true,
        ]);

        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-005',
            'nombre' => 'Producto Test 5',
            'categoria' => 'General',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'color' => 'Negro',
            'costo' => '1000',
            'precio_venta' => '1500',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertSame($color->id, $rows[0]['color_id']);
        $this->assertFalse($rows[0]['color_will_create']);

        unlink($path);
    }

    // =========================================================
    // B. IMPORT NO DUPLICA
    // =========================================================

    public function test_import_does_not_duplicate_same_style_in_multiple_rows(): void
    {
        $path = $this->makeExcel([
            [
                'codigo_interno' => 'IMP-006',
                'nombre' => 'Producto A',
                'categoria' => 'General',
                'unidad' => 'Unidad',
                'tipo_producto' => 'product',
                'estilo' => 'MismoEstilo',
                'costo' => '1000',
                'precio_venta' => '1500',
                'impuesto' => '13',
                'activo' => 'Sí',
            ],
            [
                'codigo_interno' => 'IMP-007',
                'nombre' => 'Producto B',
                'categoria' => 'General',
                'unidad' => 'Unidad',
                'tipo_producto' => 'product',
                'estilo' => 'MismoEstilo',
                'costo' => '2000',
                'precio_venta' => '3000',
                'impuesto' => '13',
                'activo' => 'Sí',
            ],
        ]);

        $rows = $this->importService->preview($path, $this->company->id);
        $preview = $this->wrapForConfirm($rows);
        $this->importService->confirm($preview, $this->company->id);

        $styleCount = Style::where('company_id', $this->company->id)
            ->where('name', 'MismoEstilo')
            ->count();
        $this->assertSame(1, $styleCount);

        unlink($path);
    }

    // =========================================================
    // C. EXCEL VIEJO SIN COLUMNAS NUEVAS SIGUE FUNCIONANDO
    // =========================================================

    public function test_old_excel_without_new_columns_still_works(): void
    {
        $headers = [
            'codigo_interno*', 'nombre*', 'categoria*', 'marca', 'unidad*', 'tipo_producto*',
            'codigo_barras_principal', 'codigos_barras_adicionales', 'cabys', 'descripcion_corta',
            'descripcion', 'costo*', 'precio_venta*', 'precio_mayorista', 'precio_especial',
            'precio_a', 'precio_b', 'precio_c', 'impuesto*', 'controla_inventario',
            'permite_stock_negativo', 'imprime_etiqueta', 'activo',
        ];

        $data = [$headers, [
            'IMP-OLD-001', 'Producto Viejo', 'General', '', 'Unidad', 'product',
            '', '', '', '', '', '1000', '1500', '', '', '', '', '', '13', '', '', '', 'Sí',
        ]];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($data, null, 'A1');
        $tempPath = tempnam(sys_get_temp_dir(), 'import_old_') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempPath);

        $rows = $this->importService->preview($tempPath, $this->company->id);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['style_id']);
        $this->assertNull($rows[0]['size_id']);
        $this->assertNull($rows[0]['color_id']);

        $preview = $this->wrapForConfirm($rows);
        $count = $this->importService->confirm($preview, $this->company->id);
        $this->assertSame(1, $count);

        $product = Product::where('internal_code', 'IMP-OLD-001')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->style_id);
        $this->assertNull($product->size_id);
        $this->assertNull($product->color_id);

        unlink($tempPath);
    }

    // =========================================================
    // D. EXCEL NUEVO CON 4 COLUMNAS FUNCIONA
    // =========================================================

    public function test_new_excel_with_four_new_columns_works(): void
    {
        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-NEW-001',
            'nombre' => 'Producto Nuevo',
            'categoria' => 'Ropa',
            'subcategoria_subrubro' => 'Camisas',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'estilo' => 'Formal',
            'talla' => 'M',
            'color' => 'Azul',
            'costo' => '5000',
            'precio_venta' => '8900',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertTrue($rows[0]['style_will_create']);
        $this->assertTrue($rows[0]['size_will_create']);
        $this->assertTrue($rows[0]['color_will_create']);

        $preview = $this->wrapForConfirm($rows);
        $count = $this->importService->confirm($preview, $this->company->id);
        $this->assertSame(1, $count);

        $product = Product::where('internal_code', 'IMP-NEW-001')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->style_id);
        $this->assertNotNull($product->size_id);
        $this->assertNotNull($product->color_id);
        $this->assertSame('Formal', $product->style->name);
        $this->assertSame('M', $product->size->name);
        $this->assertSame('Azul', $product->color->name);

        $category = $product->category;
        $this->assertSame('Camisas', $category->name);
        $this->assertNotNull($category->parent_id);

        $parentCategory = ProductCategory::find($category->parent_id);
        $this->assertSame('Ropa', $parentCategory->name);

        unlink($path);
    }

    // =========================================================
    // E. SUBCATEGORY IMPORT
    // =========================================================

    public function test_import_creates_subcategory_under_parent(): void
    {
        $parent = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Calzado',
            'slug' => 'calzado-parent-' . $this->company->id,
            'is_active' => true,
        ]);

        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-SUB-001',
            'nombre' => 'Zapatilla',
            'categoria' => 'Calzado',
            'subcategoria_subrubro' => 'Deportivo',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'costo' => '10000',
            'precio_venta' => '15000',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $preview = $this->wrapForConfirm($rows);
        $this->importService->confirm($preview, $this->company->id);

        $subcategory = ProductCategory::where('company_id', $this->company->id)
            ->where('name', 'Deportivo')
            ->where('parent_id', $parent->id)
            ->first();

        $this->assertNotNull($subcategory);

        $product = Product::where('internal_code', 'IMP-SUB-001')->first();
        $this->assertSame($subcategory->id, $product->category_id);

        unlink($path);
    }

    public function test_import_reuses_existing_subcategory(): void
    {
        $parent = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Electrónica',
            'slug' => 'electronica-parent-' . $this->company->id,
            'is_active' => true,
        ]);

        $existing = ProductCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
            'name' => 'Audífonos',
            'slug' => 'audifonos-' . $this->company->id,
            'is_active' => true,
        ]);

        $path = $this->makeExcel([[
            'codigo_interno' => 'IMP-SUB-002',
            'nombre' => 'Audífono Bluetooth',
            'categoria' => 'Electrónica',
            'subcategoria_subrubro' => 'Audífonos',
            'unidad' => 'Unidad',
            'tipo_producto' => 'product',
            'costo' => '15000',
            'precio_venta' => '25000',
            'impuesto' => '13',
            'activo' => 'Sí',
        ]]);

        $rows = $this->importService->preview($path, $this->company->id);
        $this->assertSame($existing->id, $rows[0]['category_id']);

        $preview = $this->wrapForConfirm($rows);
        $this->importService->confirm($preview, $this->company->id);

        $subCount = ProductCategory::where('company_id', $this->company->id)
            ->where('name', 'Audífonos')
            ->where('parent_id', $parent->id)
            ->count();
        $this->assertSame(1, $subCount);

        unlink($path);
    }
}
