<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\Imports\HistoricalSaleImportService;
use App\Services\Sales\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HistoricalSaleImportP34P35Test extends TestCase
{
    use RefreshDatabase;

    public function test_template_and_equivalent_export_use_the_same_contract(): void
    {
        [$company, $branch, $user, $customer, $product] = $this->context(['ventas.crear', 'ventas.ver', 'reportes.exportar']);
        $sale = $this->historicalSale($company, $branch, $user, $customer, $product, 'H-EXPORT');

        $template = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('importaciones.ventas-historicas.template'))->assertOk();
        $this->assertSame(HistoricalSaleImportService::HEADERS, $this->spreadsheet($template->streamedContent())[0]);

        $export = $this->get(route('data-center.exports.download', ['sales', 'xlsx']))->assertOk();
        $rows = $this->spreadsheet($export->streamedContent());
        $this->assertSame(HistoricalSaleImportService::HEADERS, $rows[0]);
        $this->assertSame('H-EXPORT', $rows[1][0]);
        $this->assertSame($product->internal_code, $rows[1][13]);
        $this->assertSame(0, bccomp($sale->total, (string) $rows[1][11], 4));
    }

    public function test_preview_accepts_xlsx_xls_and_csv_without_writes(): void
    {
        [$company, $branch, $user, $customer, $product] = $this->context(['ventas.crear']);
        foreach (['xlsx', 'xls', 'csv'] as $format) {
            $row = $this->row($branch, $customer, $product, 'FORM-'.$format);
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
                route('importaciones.ventas-historicas.preview'),
                ['sales_file' => $this->upload($this->file([$row], $format), $format)],
            )->assertOk()->assertSee('FORM-'.$format);
            $this->assertTrue(session('historical_sale_import_preview.rows.0.valid'));
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
    }

    public function test_preview_reports_row_field_document_totals_duplicates_and_company_isolation(): void
    {
        [$company, $branch, $user, $customer, $product] = $this->context(['ventas.crear']);
        [$otherCompany, $otherBranch, $otherUser, $otherCustomer, $otherProduct] = $this->context([]);
        $this->historicalSale($company, $branch, $user, $customer, $product, 'YA-EXISTE');
        $bad = $this->row($otherBranch, $otherCustomer, $otherProduct, 'YA-EXISTE');
        $bad[8] = '999.0000';
        $bad[12] = '';
        $bad[16] = '-1';

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.ventas-historicas.preview'),
            ['sales_file' => $this->upload($this->file([$bad], 'xlsx'), 'xlsx')],
        )->assertOk();

        $response->assertSee('numero_documento')->assertSee('codigo_sucursal')->assertSee('producto')
            ->assertSee('subtotal_documento')->assertSee('cantidad')->assertSee('Corrija todos los documentos');
        $this->assertFalse(session('historical_sale_import_preview.rows.0.valid'));
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_confirmation_writes_headers_and_lines_with_exact_decimals_and_no_operational_effects(): void
    {
        Mail::fake();
        [$company, $branch, $user, $customer, $product] = $this->context(['ventas.crear']);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => '8.0000', 'minimum_stock' => 0, 'maximum_stock' => 20, 'created_at' => now(), 'updated_at' => now()]);
        $first = $this->row($branch, $customer, $product, 'HIST-001', '1', '2.5000', '100.2500', '10.1250', '13.0000');
        $second = $this->row($branch, $customer, $product, 'HIST-001', '2', '1.0000', '50.0000', '0.0000', '0.0000');
        $first[8] = $second[8] = '290.5000';
        $first[9] = $second[9] = '10.1250';
        $first[10] = $second[10] = '31.2650';
        $first[11] = $second[11] = '321.7650';

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(
            route('importaciones.ventas-historicas.preview'),
            ['sales_file' => $this->upload($this->file([$first, $second], 'xlsx'), 'xlsx')],
        )->assertOk();
        $this->post(route('importaciones.ventas-historicas.import'))->assertRedirect(route('ventas.index'));

        $sale = Sale::where('company_id', $company->id)->where('sale_number', 'HIST-001')->sole();
        $this->assertTrue($sale->is_historical);
        $this->assertNull($sale->cash_session_id);
        $this->assertSame('290.5000', $sale->subtotal);
        $this->assertSame('321.7650', $sale->total);
        $this->assertSame('0.0000', $sale->balance_due);
        $this->assertCount(2, $sale->items);
        $this->assertSame('31.2650', $sale->items->reduce(fn ($carry, $item) => bcadd($carry, $item->tax_total, 4), '0.0000'));
        $this->assertSame('8.0000', bcadd((string) DB::table('branch_product')->value('stock'), '0', 4));
        foreach (['inventory_movements', 'sale_payments', 'accounts_receivable', 'loyalty_movements', 'cash_sessions', 'company_sequences'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        Mail::assertNothingSent();
        $this->assertNull(session('historical_sale_import_preview'));
    }

    public function test_confirmation_revalidates_duplicate_and_rolls_back_every_document(): void
    {
        [$company, $branch, $user, $customer, $product] = $this->context(['ventas.crear']);
        $rows = [$this->row($branch, $customer, $product, 'ROLL-1'), $this->row($branch, $customer, $product, 'ROLL-2')];
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('importaciones.ventas-historicas.preview'), ['sales_file' => $this->upload($this->file($rows, 'xlsx'), 'xlsx')])->assertOk();
        $this->historicalSale($company, $branch, $user, $customer, $product, 'ROLL-2');

        $this->from(route('importaciones.ventas-historicas'))->post(route('importaciones.ventas-historicas.import'))
            ->assertRedirect(route('importaciones.ventas-historicas'))->assertSessionHasErrors('sales_file');
        $this->assertDatabaseMissing('sales', ['sale_number' => 'ROLL-1']);
        $this->assertSame(1, Sale::where('company_id', $company->id)->count());
    }

    public function test_historical_actions_are_hidden_and_import_permission_is_enforced(): void
    {
        [$company, $branch, $user, $customer, $product] = $this->context(['ventas.crear', 'ventas.ver', 'ventas.anular', 'devoluciones.crear']);
        $sale = $this->historicalSale($company, $branch, $user, $customer, $product, 'H-SAFE');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('ventas.show', $sale))
            ->assertOk()->assertDontSee('Anular venta')->assertDontSee('Devolver productos');
        $this->from(route('ventas.show', $sale))->post(route('ventas.void', $sale), ['reason' => 'No aplica'])
            ->assertRedirect(route('ventas.show', $sale))->assertSessionHasErrors('sale');
        try {
            app(SaleReturnService::class)->store($sale, $user, 'No aplica', [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1.0000']]);
            $this->fail('La devolución histórica debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('sale_returns', 0);
        [$otherCompany, $otherBranch, $denied] = $this->context([]);
        $this->actingAs($denied)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->get(route('importaciones.ventas-historicas'))->assertForbidden();
    }

    private function context(array $permissions): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'Hist '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'B'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Hist '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Ventas', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.$suffix, 'identification_type' => 'national', 'identification' => 'ID'.$suffix, 'is_active' => true]);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'SKU-'.$suffix, 'barcode' => 'BAR-'.$suffix, 'cost' => '40.0000', 'sale_price' => '100.0000', 'tax_rate' => '13.0000', 'track_inventory' => true, 'is_active' => true]);

        return [$company, $branch, $user, $customer, $product];
    }

    private function row(Branch $branch, Customer $customer, Product $product, string $number, string $line = '1', string $qty = '1.0000', string $price = '100.0000', string $discount = '0.0000', string $tax = '13.0000'): array
    {
        $gross = bcmul($qty, $price, 4);
        $subtotal = bcsub($gross, $discount, 4);
        $taxTotal = bcmul($subtotal, bcdiv($tax, '100', 4), 4);
        $total = bcadd($subtotal, $taxTotal, 4);

        return [$number, '2024-06-15 10:30:00', $branch->code, 'factura', 'contado', 'CRC', '1.0000', $customer->identification, $subtotal, $discount, $taxTotal, $total, $line, $product->internal_code, $product->barcode, $product->name, $qty, $price, $discount, $tax, '40.0000'];
    }

    private function historicalSale(Company $company, Branch $branch, User $user, Customer $customer, Product $product, string $number): Sale
    {
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => $number, 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'is_historical' => true, 'currency_code' => 'CRC', 'exchange_rate' => '1.0000', 'subtotal' => '100.0000', 'discount_total' => '0.0000', 'tax_total' => '13.0000', 'rounding_total' => '0.0000', 'total' => '113.0000', 'paid_total' => '113.0000', 'balance_due' => '0.0000', 'completed_at' => '2024-01-01 10:00:00']);
        $sale->items()->create(['product_id' => $product->id, 'product_code' => $product->internal_code, 'barcode' => $product->barcode, 'description' => $product->name, 'unit_code' => 'U', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'gross_total' => '100.0000', 'discount_total' => '0.0000', 'subtotal' => '100.0000', 'tax_rate' => '13.0000', 'tax_total' => '13.0000', 'total' => '113.0000', 'unit_cost' => '40.0000']);

        return $sale;
    }

    private function file(array $rows, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'historical-sales-').'.'.$format;
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray(array_merge([HistoricalSaleImportService::HEADERS], $rows));
        match ($format) {
            'xlsx' => (new Xlsx($sheet))->save($path), 'xls' => (new Xls($sheet))->save($path), 'csv' => (new Csv($sheet))->save($path)
        };

        return $path;
    }

    private function upload(string $path, string $format): UploadedFile
    {
        return new UploadedFile($path, 'ventas.'.$format, match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel', default => 'text/csv'
        }, null, true);
    }

    private function spreadsheet(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sales-sheet-');
        file_put_contents($path, $content);

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
