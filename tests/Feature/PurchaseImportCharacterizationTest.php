<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\PurchaseExcelImport;
use App\Services\Imports\Xml\PurchaseXmlImport;
use App\Services\Purchases\CompanyPurchaseSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseImportCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_reader_preserves_supported_columns_aliases_and_dates(): void
    {
        $path = $this->xlsx([
            ['Código *', 'Código Barras', 'Producto *', 'Categoría', 'Cantidad *', 'Costo *', 'Precio de Venta', 'Fecha de vencimiento'],
            ['SKU-1', '744100000001', 'Producto Uno', 'General>Hogar', 2, 1250.50, 2500, '2026-09-30'],
        ]);

        $rows = app(PurchaseExcelImport::class)->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('SKU-1', $rows[0]['code']);
        $this->assertSame('744100000001', $rows[0]['barcode']);
        $this->assertSame('General > Hogar', $rows[0]['category']);
        $this->assertSame(2.0, $rows[0]['quantity']);
        $this->assertSame(1250.5, $rows[0]['cost']);
        $this->assertSame('2026-09-30', $rows[0]['expires_at']);
        $this->assertSame('excel-2', $rows[0]['_row_key']);
    }

    public function test_xml_reader_preserves_invoice_supplier_and_lines(): void
    {
        $path = $this->xml();
        $data = app(PurchaseXmlImport::class)->read($path);

        $this->assertSame('50601082600011111111100100001010000000001111111111', $data['clave']);
        $this->assertSame('Proveedor XML', $data['proveedor']['nombre']);
        $this->assertSame('3101123456', $data['proveedor']['identificacion']);
        $this->assertCount(1, $data['lineas']);
        $this->assertSame('1234567890123', $data['lineas'][0]['cabys']);
        $this->assertSame(3.0, $data['lineas'][0]['quantity']);
        $this->assertSame(13.0, $data['lineas'][0]['tax_rate']);
    }

    public function test_purchase_template_keeps_the_existing_contract(): void
    {
        [$company, $branch, $user] = $this->context(['compras.crear']);
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('compras.import.template'));

        $response->assertOk()->assertDownload('plantilla_importacion_compras.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'purchase-template-');
        file_put_contents($path, $response->streamedContent());
        $headers = IOFactory::load($path)->getActiveSheet()->rangeToArray('A1:S1')[0];

        $this->assertSame([
            'Código *', 'Código Barra', 'Producto *', 'Descripción', 'Categoría', 'Marca',
            'Proveedor *', 'Unidad de medida *', 'Tipo Artículo', 'Cantidad *', 'Costo *',
            'Precio Venta', 'Impuesto %', 'Descuento %', 'CABYS', 'Mínimo Stock',
            'Máximo Stock', 'Lote', 'Fecha Vencimiento',
        ], $headers);
    }

    public function test_excel_and_xml_posts_share_active_branch_and_purchase_permission(): void
    {
        foreach (['compras.import.excel', 'compras.import.xml', 'compras.import.review', 'compras.import.confirm'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('active.branch', $middleware, $name);
            $this->assertContains('permission:compras.crear', $middleware, $name);
        }

        [$company, $branch, $user] = $this->context([]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('compras.import.xml'), ['file' => $this->uploaded($this->xml(), 'factura.xml', 'application/xml')])
            ->assertForbidden();
    }

    public function test_xml_post_builds_the_existing_review_session(): void
    {
        [$company, $branch, $user] = $this->context(['compras.crear']);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('compras.import.xml'), ['file' => $this->uploaded($this->xml(), 'factura.xml', 'application/xml')])
            ->assertRedirect(route('compras.import.review'));

        $validation = session('purchase_import_validation');
        $this->assertSame('Proveedor XML', $validation['supplier_summary']['name']);
        $this->assertSame('xml-1', $validation['missing'][0]['_row_key']);
        $this->assertSame('XML-1234567890123', $validation['missing'][0]['code']);
    }

    public function test_review_confirmation_uses_purchase_processor_and_clears_session(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context(['compras.crear'], true);
        $validation = [
            'found' => [[
                'product_id' => $product->id, 'product' => $product->name,
                'code' => $product->internal_code, 'name' => $product->name,
                'quantity' => 2, 'cost' => 500, 'tax_rate' => 0, '_row_key' => 'excel-2',
            ]],
            'missing' => [],
            'supplier_summary' => ['multiple' => false, 'names' => [$supplier->name], 'name' => $supplier->name],
            'supplier' => ['found' => true, 'id' => $supplier->id, 'name' => $supplier->name],
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch) + ['purchase_import_validation' => $validation])
            ->get(route('compras.import.review'))->assertOk()->assertSee($product->name);
        $this->post(route('compras.import.confirm'))->assertRedirect();

        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseHas('purchase_items', ['product_id' => $product->id, 'quantity' => 2]);
        $this->assertSame(2.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)
            ->where('product_id', $product->id)->value('stock'));
        $this->assertNull(session('purchase_import_validation'));
    }

    private function context(array $permissions, bool $withCatalog = false): array
    {
        $company = Company::create(['trade_name' => 'Compras '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Compras '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Compras', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        if (! $withCatalog) {
            return [$company, $branch, $user];
        }

        app(CompanyPurchaseSettingsResolver::class)->forCompany($company);
        $supplier = Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor', 'is_active' => true]);
        $suffix = Str::lower(Str::random(8));
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General', 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'Unid', 'slug' => 'unidad-'.$suffix, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto', 'internal_code' => 'P-'.$suffix, 'cost' => 400, 'sale_price' => 800,
            'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);

        return [$company, $branch, $user, $supplier, $product];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase-').'.xlsx';
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($sheet))->save($path);

        return $path;
    }

    private function xml(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase-').'.xml';
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><FacturaElectronica><Clave>50601082600011111111100100001010000000001111111111</Clave><FechaEmision>2026-08-26T10:00:00-06:00</FechaEmision><Emisor><Nombre>Proveedor XML</Nombre><Identificacion><Numero>3101123456</Numero></Identificacion></Emisor><DetalleServicio><LineaDetalle><CodigoCABYS>1234567890123</CodigoCABYS><Cantidad>3</Cantidad><UnidadMedida>Unid</UnidadMedida><Detalle>Artículo XML</Detalle><PrecioUnitario>500</PrecioUnitario><Impuesto><Tarifa>13</Tarifa></Impuesto></LineaDetalle></DetalleServicio></FacturaElectronica>');

        return $path;
    }

    private function uploaded(string $path, string $name, string $mime): UploadedFile
    {
        return new UploadedFile($path, $name, $mime, null, true);
    }
}
