<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\PurchaseExcelImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseImportFiscalColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_reader_extracts_fiscal_columns(): void
    {
        $path = $this->xlsx([
            ['Código *', 'Producto *', 'Cantidad *', 'Costo *', 'Impuesto %', 'Código Impuesto', 'Código Tarifa', 'Perfil Fiscal'],
            ['SKU-FIS', 'Producto fiscal', 2, 100, '13', '01', '08', '7'],
        ]);

        $rows = app(PurchaseExcelImport::class)->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('01', $rows[0]['tax_code']);
        $this->assertSame('08', $rows[0]['tax_rate_code']);
        $this->assertSame(7.0, $rows[0]['fiscal_profile_id']);
        $this->assertSame(13.0, $rows[0]['tax_rate']);
    }

    public function test_confirmation_uses_explicit_codes_instead_of_the_product_profile(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $validation = [
            'found' => [[
                'product_id' => $product->id, 'product' => $product->name,
                'code' => $product->internal_code, 'name' => $product->name,
                'quantity' => 2, 'cost' => 500, 'tax_rate' => 13,
                'tax_code' => '01', 'tax_rate_code' => '08', '_row_key' => 'excel-2',
            ]],
            'missing' => [],
            'supplier_summary' => ['multiple' => false, 'names' => [$supplier->name], 'name' => $supplier->name],
            'supplier' => ['found' => true, 'id' => $supplier->id, 'name' => $supplier->name],
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch) + ['purchase_import_validation' => $validation])
            ->post(route('compras.import.confirm'))
            ->assertRedirect();

        $item = \App\Models\PurchaseItem::query()->sole();
        $this->assertSame('01', $item->tax_code);
        $this->assertSame('08', $item->tax_rate_code);
        $this->assertSame(13.0, (float) $item->tax_rate);

        // El producto conserva su perfil exento: el documento/la fila no lo sustituyen.
        $product->refresh();
        $this->assertSame($this->profile('01', '10')->id, (int) $product->fiscal_profile_id);
        $this->assertSame(0.0, (float) $product->tax_rate);
    }

    public function test_new_product_form_blocks_ambiguous_rate_unless_profile_is_chosen(): void
    {
        [$company, $branch, $user, $supplier] = $this->context();
        $exempt = $this->profile('01', '10');
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'CatB4 '.uniqid(), 'slug' => 'catb4-'.uniqid(), 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'UnidB4 '.uniqid(), 'abbreviation' => 'UB4', 'slug' => 'unidb4-'.uniqid(), 'is_active' => true]);

        $missing = [[
            '_row_key' => 'excel-9',
            'code' => 'NUEVO-B4', 'name' => 'Producto nuevo B4',
            'category' => $category->name, 'brand' => null, 'unit' => $unit->abbreviation, 'barcode' => null,
            'cost' => 100, 'quantity' => 1, 'tax_rate' => 0,
            'category_resolution' => ['status' => 'found', 'category_id' => $category->id],
        ]];
        $session = $this->activeSession($company, $branch) + ['purchase_import_validation' => [
            'found' => [],
            'missing' => $missing,
            'supplier_summary' => ['multiple' => false, 'names' => [$supplier->name], 'name' => $supplier->name],
            'supplier' => ['found' => true, 'id' => $supplier->id, 'name' => $supplier->name],
        ]];

        $this->actingAs($user)->withSession($session)
            ->post(route('compras.import.product.store'), [
                'row_key' => 'excel-9',
                'name' => 'Producto nuevo B4',
                'code' => 'NUEVO-B4',
                'cost' => '100',
            ])->assertSessionHasErrors('fiscal_profile_id');

        $this->assertDatabaseMissing('products', ['internal_code' => 'NUEVO-B4']);

        $this->actingAs($user)->withSession($session)
            ->post(route('compras.import.product.store'), [
                'row_key' => 'excel-9',
                'name' => 'Producto nuevo B4',
                'code' => 'NUEVO-B4',
                'cost' => '100',
                'fiscal_profile_id' => (string) $exempt->id,
            ])->assertRedirect(route('compras.import.review'));

        $product = Product::query()->where('internal_code', 'NUEVO-B4')->sole();
        $this->assertSame($exempt->id, (int) $product->fiscal_profile_id);
        $this->assertSame(0.0, (float) $product->tax_rate);
    }

    private function context(): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'ComprasFiscal '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.$suffix, 'is_active' => true]);
        foreach (['compras.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Compras', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $supplier = Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor '.$suffix, 'is_active' => true]);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto exento '.$suffix, 'internal_code' => 'P-EX-'.$suffix,
            'cost' => 400, 'sale_price' => 800, 'tax_rate' => 0,
            'fiscal_profile_id' => $this->profile('01', '10')->id,
            'track_inventory' => true, 'is_active' => true,
        ]);

        return [$company, $branch, $user, $supplier, $product];
    }

    private function profile(string $taxCode, string $rateCode): FiscalProfile
    {
        return FiscalProfile::query()
            ->where('tax_code', $taxCode)
            ->where('tax_rate_code', $rateCode)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase-fiscal-').'.xlsx';
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($sheet))->save($path);

        return $path;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
