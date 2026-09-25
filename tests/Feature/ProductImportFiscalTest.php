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
use App\Services\Imports\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_normalizes_explicit_codes_and_profile_with_correct_precedence(): void
    {
        [$company, $branch, $user, $category, $unit] = $this->context();
        $exempt = $this->profile('01', '10');
        $notSubject = $this->profile('01', '11');

        $rows = [
            $this->row($category, $unit, 'FIS-A', ['impuesto' => '0', 'codigo_impuesto' => '01', 'codigo_tarifa' => '08']),
            $this->row($category, $unit, 'FIS-B', ['impuesto' => '13', 'codigo_impuesto' => '01', 'codigo_tarifa' => '10']),
            $this->row($category, $unit, 'FIS-C', ['impuesto' => '13', 'perfil_fiscal' => (string) $notSubject->id]),
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->file($rows))],
        )->assertOk();

        $preview = collect(session('product_import_preview.rows'));
        $this->assertTrue($preview[0]['valid'], json_encode($preview[0]['errors']));
        $this->assertTrue($preview[1]['valid'], json_encode($preview[1]['errors']));
        $this->assertTrue($preview[2]['valid'], json_encode($preview[2]['errors']));

        // Códigos explícitos ganan a la tasa legada (0 bloqueada por defecto).
        $this->assertSame(13.0, (float) $preview[0]['tax_rate']);
        $this->assertSame($this->profile('01', '08')->id, (int) $preview[0]['fiscal_profile_id']);

        // Códigos explícitos ganan a la tasa legada (exento declare 13).
        $this->assertSame(0.0, (float) $preview[1]['tax_rate']);
        $this->assertSame($exempt->id, (int) $preview[1]['fiscal_profile_id']);

        // El perfil explícito tiene máxima precedencia.
        $this->assertSame(0.0, (float) $preview[2]['tax_rate']);
        $this->assertSame($notSubject->id, (int) $preview[2]['fiscal_profile_id']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_preview_blocks_zero_and_eight_without_explicit_codes_or_profile(): void
    {
        [$company, $branch, $user, $category, $unit] = $this->context();

        $rows = [
            $this->row($category, $unit, 'AMB-0', ['impuesto' => '0']),
            $this->row($category, $unit, 'AMB-8', ['impuesto' => '8']),
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->file($rows))],
        )->assertOk();

        $preview = session('product_import_preview.rows');
        $this->assertFalse($preview[0]['valid']);
        $this->assertStringContainsString('ambiguo', json_encode($preview[0]['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertFalse($preview[1]['valid']);
        $this->assertStringContainsString('inequívoca', json_encode($preview[1]['errors'], JSON_UNESCAPED_UNICODE));

        $this->assertDatabaseCount('products', 0);
    }

    public function test_confirmation_persists_normalized_fiscal_profile_and_rate(): void
    {
        [$company, $branch, $user, $category, $unit] = $this->context();
        $rows = [
            $this->row($category, $unit, 'FIS-CONF', ['impuesto' => '0', 'codigo_impuesto' => '01', 'codigo_tarifa' => '08']),
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.productos.preview'),
            ['product_file' => $this->uploaded($this->file($rows))],
        )->assertOk();

        $this->post(route('importaciones.productos.import'))->assertRedirect(route('productos.index'));

        $product = \App\Models\Product::query()
            ->where('company_id', $company->id)
            ->where('internal_code', 'FIS-CONF')
            ->sole();

        $this->assertSame($this->profile('01', '08')->id, (int) $product->fiscal_profile_id);
        $this->assertSame(13.0, (float) $product->tax_rate);
    }

    private function context(): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'ImpFiscal '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.$suffix, 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'productos.crear'], ['label' => 'productos.crear', 'module' => 'Productos', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'is_active' => true]);

        return [$company, $branch, $user, $category, $unit];
    }

    private function profile(string $taxCode, string $rateCode): FiscalProfile
    {
        return FiscalProfile::query()
            ->where('tax_code', $taxCode)
            ->where('tax_rate_code', $rateCode)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function row(ProductCategory $category, Unit $unit, string $code, array $overrides = []): array
    {
        $row = array_fill_keys(array_map(
            static fn (string $header): string => rtrim($header, '*'),
            ProductImportService::HEADERS,
        ), null);

        return array_merge($row, [
            'codigo_interno' => $code,
            'nombre' => 'Producto '.$code,
            'categoria' => $category->name,
            'unidad' => $unit->abbreviation,
            'tipo_producto' => 'product',
            'costo' => '100.25',
            'precio_venta' => '200.50',
            'impuesto' => '13',
            'controla_inventario' => 'Sí',
            'permite_stock_negativo' => 'No',
            'imprime_etiqueta' => 'No',
            'activo' => 'Sí',
        ], $overrides);
    }

    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prod-fiscal-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([ProductImportService::HEADERS], $rows));
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function uploaded(string $path): UploadedFile
    {
        return new UploadedFile($path, 'productos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
