<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\InventoryImportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InventoryImportHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_real_stock_and_additional_barcode_without_mutating_data(): void
    {
        [$company, $branch, $user, $category, $unit, $product] = $this->context(['inventario.ver']);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 8,
            'minimum_stock' => 1, 'maximum_stock' => 20, 'created_at' => now(), 'updated_at' => now()]);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '744100000099', 'barcode_type' => 'EAN13', 'is_primary' => false, 'is_active' => true]);
        $path = $this->inventoryFile([['744100000099', '', 3, '', '', '', '', 0, 0, '', '', '', 13, 2, 15, '']]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.inventario.preview'),
            ['branch_id' => $branch->id, 'movement_type' => 'entry', 'inventory_file' => $this->uploaded($path)],
        );

        $response->assertOk()->assertSee('8.00')->assertSee('11.00')
            ->assertSee('necesita el permiso de ajuste de inventario');
        $preview = session('inventory_import_preview');
        $this->assertSame($product->id, $preview['rows'][0]['product_id']);
        $this->assertSame(8.0, $preview['rows'][0]['current_stock']);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(8.0, (float) DB::table('branch_product')->where('product_id', $product->id)->value('stock'));
    }

    public function test_confirmation_requires_adjust_permission_while_preview_remains_read_only(): void
    {
        [$company, $branch, $user, $category, $unit, $product] = $this->context(['inventario.ver']);
        $preview = $this->validPreview($company, $branch, $product);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch) + ['inventory_import_preview' => $preview])
            ->post(route('importaciones.inventario.import'))->assertForbidden();

        $this->assertContains('permission:inventario.ajustar', Route::getRoutes()
            ->getByName('importaciones.inventario.import')->gatherMiddleware());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_preview_rejects_another_company_and_unpermitted_branch(): void
    {
        [$company, $branch, $user] = $this->context(['inventario.ver']);
        [$otherCompany, $otherBranch] = $this->context([]);
        $secondBranch = Branch::create(['company_id' => $company->id, 'name' => 'Secundaria', 'code' => 'S'.uniqid(), 'is_active' => true]);
        $path = $this->inventoryFile([['SKU-X', 'Nuevo', 1, 'General', '', 'Unidad', '', 0, 0, '', '', '', 0, 0, 5, '']]);

        foreach ([$otherBranch, $secondBranch] as $forbiddenBranch) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
                route('importaciones.inventario.preview'),
                ['branch_id' => $forbiddenBranch->id, 'movement_type' => 'entry', 'inventory_file' => $this->uploaded($path)],
            )->assertForbidden();
        }
    }

    public function test_other_company_catalog_is_not_used_for_new_products(): void
    {
        [$company, $branch, $user] = $this->context(['inventario.ver']);
        [$otherCompany] = $this->context([]);
        ProductCategory::where('company_id', $company->id)->delete();
        Unit::where('company_id', $company->id)->delete();
        ProductCategory::query()->where('company_id', $otherCompany->id)->update(['name' => 'Externa']);
        Unit::query()->where('company_id', $otherCompany->id)->update(['name' => 'Unidad Externa']);
        $path = $this->inventoryFile([['SKU-X', 'Nuevo', 1, 'Externa', '', 'Unidad Externa', '', 0, 0, '', '', '', 0, 0, 5, '']]);

        $rows = app(InventoryImportService::class)->preview($path, $company->id, $branch, 'entry');

        $this->assertFalse($rows[0]['valid']);
        $this->assertStringContainsString('no existe o no pertenece', implode(' ', $rows[0]['errors']));
    }

    public function test_valid_confirmation_creates_company_catalog_product_barcode_stock_and_movement(): void
    {
        [$company, $branch, $user, $category, $unit] = $this->context(['inventario.ver', 'inventario.ajustar']);
        $path = $this->inventoryFile([['NUEVO-1', 'Producto nuevo', 5, $category->name, '', $unit->abbreviation,
            '744100000123', '1234567890123', 100, 250, 225, 240, 13, 2, 12, 'Descripción']]);
        $service = app(InventoryImportService::class);
        $rows = $service->preview($path, $company->id, $branch, 'entry');

        $this->assertTrue($rows[0]['valid'], implode(' ', $rows[0]['errors']));
        $service->confirm(['company_id' => $company->id, 'branch_id' => $branch->id, 'movement_type' => 'entry', 'rows' => $rows], $company->id, $user->id);

        $product = Product::where('company_id', $company->id)->where('internal_code', 'NUEVO-1')->sole();
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($unit->id, $product->unit_id);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => '744100000123', 'is_primary' => true]);
        $this->assertDatabaseHas('branch_product', ['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 5, 'minimum_stock' => 2, 'maximum_stock' => 12]);
        $this->assertDatabaseHas('inventory_movements', ['company_id' => $company->id, 'branch_id' => $branch->id,
            'product_id' => $product->id, 'type' => 'entry', 'previous_stock' => 0, 'new_stock' => 5]);
    }

    public function test_preview_flags_invalid_numbers_negative_exit_and_duplicates(): void
    {
        [$company, $branch, $user, $category, $unit, $product] = $this->context(['inventario.ver']);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 2,
            'minimum_stock' => 0, 'maximum_stock' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $path = $this->inventoryFile([
            [$product->internal_code, '', 3, '', '', '', '', -1, 0, '', '', '', 101, 5, 2, ''],
            [$product->internal_code, '', 1, '', '', '', '', 0, 0, '', '', '', 0, 0, 5, ''],
        ]);

        $rows = app(InventoryImportService::class)->preview($path, $company->id, $branch, 'exit');

        $this->assertFalse($rows[0]['valid']);
        $this->assertStringContainsString('stock negativo', implode(' ', $rows[0]['errors']));
        $this->assertStringContainsString('máximo no puede ser menor', implode(' ', $rows[0]['errors']));
        $this->assertStringContainsString('impuesto debe estar entre', implode(' ', $rows[0]['errors']));
        $this->assertFalse($rows[1]['valid']);
        $this->assertStringContainsString('repetido', implode(' ', $rows[1]['errors']));
    }

    public function test_confirmation_is_atomic_when_a_later_row_is_no_longer_valid(): void
    {
        [$company, $branch, $user, $category, $unit, $product] = $this->context(['inventario.ajustar']);
        [$otherCompany, $otherBranch, $otherUser, $otherCategory, $otherUnit, $otherProduct] = $this->context([]);
        $preview = $this->validPreview($company, $branch, $product);
        $preview['rows'][] = $preview['rows'][0] + ['product_id' => $otherProduct->id, 'code' => $otherProduct->internal_code];
        $preview['rows'][1]['product_id'] = $otherProduct->id;

        try {
            app(InventoryImportService::class)->confirm($preview, $company->id, $user->id);
            $this->fail('La confirmación debía fallar por producto de otra empresa.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('inventory_movements', 0);
            $this->assertDatabaseMissing('branch_product', ['branch_id' => $branch->id, 'product_id' => $product->id]);
        }
    }

    private function context(array $permissions): array
    {
        $company = Company::create(['trade_name' => 'Inventario '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Inventario '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Inventario', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $suffix = Str::lower(Str::random(8));
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix, 'internal_code' => 'SKU-'.$suffix, 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true]);

        return [$company, $branch, $user, $category, $unit, $product];
    }

    private function validPreview(Company $company, Branch $branch, Product $product): array
    {
        return ['company_id' => $company->id, 'branch_id' => $branch->id, 'movement_type' => 'entry', 'rows' => [[
            'code' => $product->internal_code, 'product_id' => $product->id, 'product_name' => $product->name,
            'category_id' => null, 'brand_id' => null, 'unit_id' => null, 'barcode' => null, 'cabys' => null,
            'cost' => 10, 'sale_price' => 20, 'wholesale_price' => null, 'special_price' => null, 'tax_rate' => 13,
            'description' => null, 'quantity' => 1, 'minimum' => 0, 'maximum' => 10, 'current_stock' => 0,
            'is_new' => false, 'valid' => true, 'errors' => [],
        ]]];
    }

    private function inventoryFile(array $dataRows): string
    {
        $headers = ['codigo*', 'nombre*', 'cantidad*', 'categoria', 'marca', 'unidad', 'codigo_barras',
            'cabys', 'costo', 'precio_venta', 'precio_mayoreo', 'precio_especial', 'impuesto', 'minimo', 'maximo', 'descripcion'];
        $path = tempnam(sys_get_temp_dir(), 'inventory-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$headers], $dataRows));
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function uploaded(string $path): UploadedFile
    {
        return new UploadedFile($path, 'inventario.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
