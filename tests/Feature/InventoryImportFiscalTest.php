<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\Permission;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\InventoryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InventoryImportFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_product_row_is_normalized_from_explicit_codes(): void
    {
        [$company, $branch, $category, $unit] = $this->context();
        $path = $this->file([
            ['INV-FIS-1', 'Producto con códigos', 5, $category->name, '', $unit->abbreviation,
                '', '', 100, 250, '', '', '', 2, 10, '', '01', '08', ''],
        ]);

        $rows = app(InventoryImportService::class)->preview($path, $company->id, $branch, 'entry');

        $this->assertTrue($rows[0]['valid'], implode(' ', $rows[0]['errors']));
        $this->assertSame(13.0, (float) $rows[0]['tax_rate']);
        $this->assertSame($this->profile('01', '08')->id, (int) $rows[0]['fiscal_profile_id']);
    }

    public function test_new_product_row_with_zero_rate_without_codes_is_blocked(): void
    {
        [$company, $branch, $category, $unit] = $this->context();
        $path = $this->file([
            ['INV-FIS-2', 'Producto ambiguo', 5, $category->name, '', $unit->abbreviation,
                '', '', 100, 250, '', '', 0, 2, 10, '', '', '', ''],
        ]);

        $rows = app(InventoryImportService::class)->preview($path, $company->id, $branch, 'entry');

        $this->assertFalse($rows[0]['valid']);
        $this->assertStringContainsString('no puede resolverse', implode(' ', $rows[0]['errors']));
    }

    public function test_existing_product_rows_keep_the_legacy_range_validation(): void
    {
        [$company, $branch, $category, $unit] = $this->context();
        $path = $this->file([
            ['INV-FIS-3', 'Existente', 1, $category->name, '', $unit->abbreviation,
                '', '', 100, 250, '', '', 101, 2, 10, '', '', '', ''],
        ]);

        $rows = app(InventoryImportService::class)->preview($path, $company->id, $branch, 'entry');

        $this->assertFalse($rows[0]['valid']);
        $this->assertStringContainsString('impuesto debe estar entre', implode(' ', $rows[0]['errors']));
    }

    private function context(): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'InvFiscal '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.$suffix, 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'inventario.ver'], ['label' => 'inventario.ver', 'module' => 'Inventario', 'is_active' => true]);
        $role->permissions()->attach($permission);

        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'is_active' => true]);

        return [$company, $branch, $category, $unit];
    }

    private function profile(string $taxCode, string $rateCode): FiscalProfile
    {
        return FiscalProfile::query()
            ->where('tax_code', $taxCode)
            ->where('tax_rate_code', $rateCode)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function file(array $rows): string
    {
        $headers = ['codigo*', 'nombre*', 'cantidad*', 'categoria', 'marca', 'unidad', 'codigo_barras',
            'cabys', 'costo', 'precio_venta', 'precio_mayoreo', 'precio_especial', 'impuesto', 'minimo', 'maximo', 'descripcion',
            'codigo_impuesto', 'codigo_tarifa', 'perfil_fiscal'];
        $path = tempnam(sys_get_temp_dir(), 'inventory-fiscal-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$headers], $rows));
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
