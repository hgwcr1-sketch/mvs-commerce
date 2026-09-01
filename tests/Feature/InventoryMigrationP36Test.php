<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\InventoryMigrationImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InventoryMigrationP36Test extends TestCase
{
    use RefreshDatabase;

    public function test_template_and_equivalent_export_cover_initial_and_historical_contract(): void
    {
        [$company, $branch, $user, $product] = $this->context(['inventario.ajustar', 'inventario.ver', 'reportes.exportar']);
        $this->stock($branch, $product, '7.1250');
        DB::table('inventory_migration_batches')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'source_key' => 'OLD', 'row_count' => 1, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_movements')->insert(['company_id' => $company->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $user->id, 'type' => 'historical_entry', 'quantity' => '2.0000', 'previous_stock' => '1.0000', 'new_stock' => '3.0000', 'reason' => 'Histórico', 'reference_type' => 'inventory_migration', 'reference_id' => 1, 'created_at' => '2023-01-01 10:00:00', 'updated_at' => '2023-01-01 10:00:00']);

        $template = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('importaciones.inventario-migracion.template'))->assertOk();
        $this->assertSame(InventoryMigrationImportService::HEADERS, $this->spreadsheet($template->streamedContent())[0]);
        $rows = $this->spreadsheet($this->get(route('data-center.exports.download', ['inventory-migration', 'xlsx']))->assertOk()->streamedContent());
        $this->assertSame(InventoryMigrationImportService::HEADERS, $rows[0]);
        $this->assertSame('saldo_inicial', $rows[1][2]);
        $this->assertSame('7.125', (string) $rows[1][8]);
        $this->assertSame('movimiento_historico', $rows[2][2]);
    }

    public function test_preview_accepts_xlsx_xls_csv_without_mutation(): void
    {
        [$company, $branch, $user, $product] = $this->context(['inventario.ajustar']);
        foreach (['xlsx', 'xls', 'csv'] as $format) {
            $row = $this->initialRow($branch, $product, 'SRC-'.$format, 'ROW-'.$format, '5.2500');
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('importaciones.inventario-migracion.preview'), ['migration_file' => $this->upload($this->file([$row], $format), $format)])->assertOk();
            $this->assertTrue(session('inventory_migration_preview.rows.0.valid'));
        }
        $this->assertDatabaseCount('branch_product', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_migration_batches', 0);
    }

    public function test_preview_reports_company_duplicate_decimal_and_chain_errors_by_field(): void
    {
        [$company, $branch, $user, $product] = $this->context(['inventario.ajustar']);
        [$otherCompany, $otherBranch, $otherUser, $otherProduct] = $this->context([]);
        DB::table('inventory_migration_batches')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'source_key' => 'REPETIDO', 'row_count' => 1, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $rows = [
            $this->historicalRow($otherBranch, $otherProduct, 'REPETIDO', 'DUP', 'entrada', '1.00001', '0.0000', '2.0000'),
            $this->historicalRow($branch, $product, 'REPETIDO', 'DUP', 'salida', '2.0000', '9.0000', '8.0000'),
        ];
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('importaciones.inventario-migracion.preview'), ['migration_file' => $this->upload($this->file($rows, 'xlsx'), 'xlsx')])->assertOk();
        $response->assertSee('origen_migracion')->assertSee('clave_fila')->assertSee('codigo_sucursal')->assertSee('producto')->assertSee('cantidad')->assertSee('stock_nuevo')->assertSee('Corrija todas las filas');
        $this->assertFalse(session('inventory_migration_preview.rows.0.valid'));
        $this->assertFalse(session('inventory_migration_preview.rows.1.valid'));
    }

    public function test_confirmation_sets_initial_stock_and_adds_history_without_changing_current_stock(): void
    {
        [$company, $branch, $user, $product] = $this->context(['inventario.ajustar']);
        $rows = [
            $this->initialRow($branch, $product, 'MIG-2024', 'INI-1', '10.1255'),
            $this->historicalRow($branch, $product, 'MIG-2024', 'HIS-1', 'entrada', '2.1255', '3.0000', '5.1255'),
            $this->historicalRow($branch, $product, 'MIG-2024', 'HIS-2', 'salida', '1.1255', '5.1255', '4.0000'),
        ];
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('importaciones.inventario-migracion.preview'), ['migration_file' => $this->upload($this->file($rows, 'xlsx'), 'xlsx')])->assertOk();
        $this->post(route('importaciones.inventario-migracion.import'))->assertRedirect(route('inventario.index'));

        $this->assertSame('10.1255', $this->stockValue($branch, $product));
        $this->assertDatabaseHas('branch_product', ['branch_id' => $branch->id, 'product_id' => $product->id, 'minimum_stock' => 2, 'maximum_stock' => 20]);
        $batchId = DB::table('inventory_migration_batches')->value('id');
        $this->assertDatabaseHas('inventory_migration_batches', ['company_id' => $company->id, 'source_key' => 'MIG-2024', 'row_count' => 3]);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'initial_balance', 'previous_stock' => 0, 'new_stock' => 10.1255, 'reference_type' => 'inventory_migration', 'reference_id' => $batchId]);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'historical_entry', 'previous_stock' => 3, 'new_stock' => 5.1255]);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'historical_exit', 'previous_stock' => 5.1255, 'new_stock' => 4]);
        $this->assertSame('2022-01-01 08:00:00', DB::table('inventory_movements')->where('type', 'historical_entry')->value('created_at'));
        foreach (['sales', 'purchases', 'cash_sessions', 'sale_payments', 'accounts_receivable', 'loyalty_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_retry_and_concurrent_stock_change_are_idempotent_and_atomic(): void
    {
        [$company, $branch, $user, $product] = $this->context(['inventario.ajustar']);
        $row = $this->initialRow($branch, $product, 'ATOMIC', 'ROW-1', '9.0000');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('importaciones.inventario-migracion.preview'), ['migration_file' => $this->upload($this->file([$row], 'xlsx'), 'xlsx')])->assertOk();
        $this->stock($branch, $product, '1.0000');
        $this->from(route('importaciones.inventario-migracion'))->post(route('importaciones.inventario-migracion.import'))->assertSessionHasErrors('migration_file');
        $this->assertSame('1.0000', $this->stockValue($branch, $product));
        $this->assertDatabaseCount('inventory_migration_batches', 0);
        $this->assertDatabaseCount('inventory_movements', 0);

        DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->update(['stock' => '0.0000']);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('importaciones.inventario-migracion.preview'), ['migration_file' => $this->upload($this->file([$row], 'xlsx'), 'xlsx')])->assertOk();
        $this->post(route('importaciones.inventario-migracion.import'))->assertRedirect();
        $this->post(route('importaciones.inventario-migracion.preview'), ['migration_file' => $this->upload($this->file([$row], 'xlsx'), 'xlsx')])->assertOk();
        $this->assertFalse(session('inventory_migration_preview.rows.0.valid'));
        $this->from(route('importaciones.inventario-migracion'))->post(route('importaciones.inventario-migracion.import'))->assertSessionHasErrors('migration_file');
        $this->assertDatabaseCount('inventory_migration_batches', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame('9.0000', $this->stockValue($branch, $product));
    }

    public function test_permissions_and_other_company_branch_are_blocked(): void
    {
        [$company, $branch, $user, $product] = $this->context(['inventario.ajustar']);
        [$otherCompany, $otherBranch, $denied, $otherProduct] = $this->context([]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('data-center.imports'))->assertOk()->assertSee('data-existing-import="inventory-migration"', false);
        $row = $this->initialRow($otherBranch, $otherProduct, 'FOREIGN', 'F-1', '3.0000');
        $this->post(route('importaciones.inventario-migracion.preview'), ['migration_file' => $this->upload($this->file([$row], 'xlsx'), 'xlsx')])->assertOk();
        $this->assertFalse(session('inventory_migration_preview.rows.0.valid'));
        $this->actingAs($denied)->withSession($this->activeSession($otherCompany, $otherBranch))->get(route('importaciones.inventario-migracion'))->assertForbidden();
    }

    public function test_real_legacy_csv_previews_without_writes_and_confirms_exact_initial_stock_only_in_selected_branch(): void
    {
        [$company, $sanRamon, $user, $product] = $this->context(['inventario.ajustar', 'inventario.ver_otras_sucursales']);
        $sanRamon->update(['name' => 'San Ramón']);
        $liberia = Branch::create(['company_id' => $company->id, 'name' => 'Liberia', 'code' => 'LIB', 'is_active' => true]);
        $user->branches()->attach($liberia->id);
        $this->stock($liberia, $product, '8.0000');
        $csv = $this->legacyFile([
            [$product->internal_code, $product->barcode, $product->name, '12.3456'],
        ]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $sanRamon))->post(
            route('importaciones.inventario-migracion.preview'),
            $this->legacyRequest($csv, $sanRamon, 'MYM-SR-REAL'),
        )->assertOk();

        $response->assertSee('12.3456');
        $this->assertTrue(session('inventory_migration_preview.rows.0.valid'));
        $this->assertSame('12.3456', session('inventory_migration_preview.rows.0.quantity'));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseMissing('branch_product', ['branch_id' => $sanRamon->id, 'product_id' => $product->id]);

        $this->post(route('importaciones.inventario-migracion.import'))->assertRedirect(route('inventario.index'));
        $this->assertSame('12.3456', $this->stockValue($sanRamon, $product));
        $this->assertSame('8.0000', $this->stockValue($liberia, $product));
        $this->assertDatabaseHas('inventory_movements', [
            'company_id' => $company->id,
            'branch_id' => $sanRamon->id,
            'product_id' => $product->id,
            'type' => 'initial_balance',
            'new_stock' => 12.3456,
        ]);
    }

    public function test_legacy_csv_accepts_zero_and_blocks_missing_duplicate_and_conflicting_codes_without_barcode_fallback(): void
    {
        [$company, $branch, $user, $product] = $this->context(['inventario.ajustar']);
        $other = Product::create([
            'company_id' => $company->id,
            'category_id' => $product->category_id,
            'unit_id' => $product->unit_id,
            'name' => 'Otro producto',
            'internal_code' => 'SKU-OTRO',
            'barcode' => 'BAR-OTRO',
            'cost' => 1,
            'sale_price' => 2,
            'tax_rate' => 13,
            'track_inventory' => true,
            'is_active' => true,
        ]);
        $csv = $this->legacyFile([
            [$product->internal_code, $product->barcode, $product->name, '0'],
            [$product->internal_code, $product->barcode, $product->name, '1.0000'],
            ['NO-EXISTE', $other->barcode, 'No debe adivinar', '2.5000'],
            [$other->internal_code, $product->barcode, 'Barcode conflictivo', '3.0000'],
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.inventario-migracion.preview'),
            $this->legacyRequest($csv, $branch, 'MYM-SR-ERRORES'),
        )->assertOk();

        $rows = session('inventory_migration_preview.rows');
        $this->assertSame('0', $rows[0]['quantity']);
        $this->assertNotContains('cantidad', array_column($rows[0]['errors'], 'field'));
        $this->assertFalse($rows[0]['valid']);
        $this->assertFalse($rows[1]['valid']);
        $this->assertContains('codigo', array_column($rows[0]['errors'], 'field'));
        $this->assertNull($rows[2]['product_id']);
        $this->assertContains('producto', array_column($rows[2]['errors'], 'field'));
        $this->assertContains('codigo_barras', array_column($rows[3]['errors'], 'field'));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_migration_batches', 0);
    }

    private function context(array $permissions): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'P36 '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'B'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'P36 '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Inventario', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'SKU-'.$suffix, 'barcode' => 'BAR-'.$suffix, 'cost' => 10, 'sale_price' => 20, 'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true]);

        return [$company, $branch, $user, $product];
    }

    private function initialRow(Branch $branch, Product $product, string $source, string $key, string $quantity): array
    {
        return [$source, $key, 'saldo_inicial', '2022-01-01 08:00:00', $branch->code, $product->internal_code, '', '', $quantity, '', '', '2.0000', '20.0000', 'Apertura', 'Migración inicial'];
    }

    private function historicalRow(Branch $branch, Product $product, string $source, string $key, string $type, string $quantity, string $previous, string $new): array
    {
        return [$source, $key, 'movimiento_historico', '2022-01-01 08:00:00', $branch->code, $product->internal_code, '', $type, $quantity, $previous, $new, '', '', 'LEGACY-'.$key, 'Kardex anterior'];
    }

    private function file(array $rows, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p36-').'.'.$format;
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray(array_merge([InventoryMigrationImportService::HEADERS], $rows));
        match ($format) {
            'xlsx' => (new Xlsx($sheet))->save($path), 'xls' => (new Xls($sheet))->save($path), 'csv' => (new Csv($sheet))->save($path)
        };

        return $path;
    }

    private function legacyFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p36-mym-').'.csv';
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray(array_merge([
            ['CODIGO', 'CODIGO BARRA', 'DESCRIPCION', 'EXISTENCIA'],
        ], $rows));
        (new Csv($sheet))->save($path);

        return $path;
    }

    private function legacyRequest(string $path, Branch $branch, string $source): array
    {
        return [
            'migration_file' => $this->upload($path, 'csv'),
            'legacy_branch_id' => $branch->id,
            'legacy_source_key' => $source,
            'legacy_occurred_at' => '2026-09-01 08:00:00',
        ];
    }

    private function upload(string $path, string $format): UploadedFile
    {
        return new UploadedFile($path, 'p36.'.$format, match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel', default => 'text/csv'
        }, null, true);
    }

    private function spreadsheet(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'p36-sheet-');
        file_put_contents($path, $content);

        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    }

    private function stock(Branch $branch, Product $product, string $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'minimum_stock' => 0, 'maximum_stock' => 20, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stockValue(Branch $branch, Product $product): string
    {
        return bcadd((string) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'), '0', 4);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
