<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SaleItemTax;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItemTax;
use App\Models\Unit;
use App\Models\User;
use App\Services\Sales\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleReturnFiscalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_return_copies_frozen_fiscal_columns_and_tax_rows(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $sale = $this->saleWithFrozenFiscalLine($company, $branch, $user, $product);
        $item = $sale->items->firstOrFail();
        $originalRow = $item->taxes()->firstOrFail();

        $return = $this->return($user, $company, $branch, $sale, $item->id, 3);
        $returnItem = $return->items()->firstOrFail();

        $this->assertSame($item->tax_code, $returnItem->tax_code);
        $this->assertSame($item->tax_rate_code, $returnItem->tax_rate_code);
        $this->assertSame($item->tax_treatment, $returnItem->tax_treatment);
        $this->assertSame($item->fiscal_source, $returnItem->fiscal_source);
        $this->assertSame($item->fiscal_source_version, $returnItem->fiscal_source_version);
        $this->assertSame($item->fiscal_snapshot, $returnItem->fiscal_snapshot);
        $this->assertSame(13.0, (float) $returnItem->tax_rate);

        $row = $returnItem->taxes()->firstOrFail();
        $this->assertSame($originalRow->tax_code, $row->tax_code);
        $this->assertSame($originalRow->tax_rate_code, $row->tax_rate_code);
        $this->assertSame($originalRow->treatment, $row->treatment);
        $this->assertSame((float) $originalRow->rate, (float) $row->rate);
        $this->assertSame((float) $originalRow->base_amount, (float) $row->base_amount);
        $this->assertSame((float) $originalRow->tax_amount, (float) $row->tax_amount);
        $this->assertSame($originalRow->sequence, $row->sequence);
        $this->assertSame($originalRow->source, $row->source);
        $this->assertSame($originalRow->source_version, $row->source_version);
    }

    public function test_partial_returns_prorate_tax_rows_and_final_closes_exact_remainder(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $sale = $this->saleWithFrozenFiscalLine($company, $branch, $user, $product);
        $itemId = $sale->items->firstOrFail()->id;

        $this->return($user, $company, $branch, $sale, $itemId, 1);
        $this->return($user, $company, $branch, $sale, $itemId, 1);
        $this->return($user, $company, $branch, $sale, $itemId, 1);

        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);

        $totals = SaleReturnItemTax::query()
            ->selectRaw('SUM(base_amount) as base, SUM(tax_amount) as tax')
            ->firstOrFail();

        $this->assertSame(3000.0, round((float) $totals->base, 4));
        $this->assertSame(390.0, round((float) $totals->tax, 4));

        $lastRow = SaleReturnItemTax::query()->latest('id')->firstOrFail();
        $this->assertSame(1000.0, (float) $lastRow->base_amount);
        $this->assertSame(130.0, (float) $lastRow->tax_amount);
    }

    public function test_return_ignores_current_product_fiscal_profile(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $sale = $this->saleWithFrozenFiscalLine($company, $branch, $user, $product);
        $item = $sale->items->firstOrFail();

        $product->forceFill([
            'tax_rate' => 0,
            'fiscal_profile_id' => (int) FiscalProfile::query()
                ->where('tax_code', '01')
                ->where('tax_rate_code', '10')
                ->value('id'),
        ])->save();

        $return = $this->return($user, $company, $branch, $sale, $item->id, 3);
        $returnItem = $return->items()->firstOrFail();

        $this->assertSame('08', $returnItem->tax_rate_code);
        $this->assertSame('taxable', $returnItem->tax_treatment);
        $this->assertSame(13.0, (float) $returnItem->tax_rate);
        $this->assertSame($item->fiscal_snapshot, $returnItem->fiscal_snapshot);
        $this->assertSame(390.0, (float) $returnItem->taxes()->firstOrFail()->tax_amount);
    }

    public function test_legacy_sale_item_without_snapshot_returns_without_tax_rows(): void
    {
        [$company, $branch, $user, $product] = $this->context();
        $sale = $this->saleWithFrozenFiscalLine($company, $branch, $user, $product, false);
        $item = $sale->items->firstOrFail();

        $return = $this->return($user, $company, $branch, $sale, $item->id, 3);
        $returnItem = $return->items()->firstOrFail();

        $this->assertNull($returnItem->tax_code);
        $this->assertNull($returnItem->tax_rate_code);
        $this->assertNull($returnItem->tax_treatment);
        $this->assertNull($returnItem->fiscal_source);
        $this->assertNull($returnItem->fiscal_source_version);
        $this->assertNull($returnItem->fiscal_snapshot);
        $this->assertSame(390.0, (float) $returnItem->tax_total);
        $this->assertSame(0, $returnItem->taxes()->count());
    }

    private function return(
        User $user,
        Company $company,
        Branch $branch,
        Sale $sale,
        int $saleItemId,
        int $quantity,
    ): SaleReturn {
        $this->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);

        return app(SaleReturnService::class)->store($sale, $user, 'Devolución fiscal', [
            ['sale_item_id' => $saleItemId, 'quantity' => $quantity],
        ]);
    }

    private function saleWithFrozenFiscalLine(
        Company $company,
        Branch $branch,
        User $user,
        Product $product,
        bool $withFrozenTaxes = true,
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('sale', true)),
            'sale_number' => 'POS-FISCAL-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 3000,
            'discount_total' => 0,
            'tax_total' => 390,
            'rounding_total' => 0,
            'total' => 3390,
            'paid_total' => 3390,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        $item = $sale->items()->create([
            'product_id' => $product->id,
            'product_code' => 'P-CODE',
            'barcode' => null,
            'cabys_code' => null,
            'description' => 'Producto fiscal',
            'unit_code' => 'U',
            'quantity' => 3,
            'unit_price' => 1000,
            'gross_total' => 3000,
            'discount_total' => 0,
            'subtotal' => 3000,
            'tax_rate' => 13,
            'tax_total' => 390,
            'total' => 3390,
            'unit_cost' => 600,
            'tax_code' => $withFrozenTaxes ? '01' : null,
            'tax_rate_code' => $withFrozenTaxes ? '08' : null,
            'tax_treatment' => $withFrozenTaxes ? 'taxable' : null,
            'fiscal_source' => $withFrozenTaxes ? 'Hacienda v4.4 / Facturaencr OpenAPI' : null,
            'fiscal_source_version' => $withFrozenTaxes ? 'v4.4' : null,
            'fiscal_snapshot' => $withFrozenTaxes ? [
                'source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
                'source_version' => 'v4.4',
                'taxes' => [[
                    'codigo' => '01',
                    'codigoTarifa' => '08',
                    'tarifa' => 13.0,
                    'factorIVA' => null,
                    'codigoTarifaOtro' => null,
                    'treatment' => 'taxable',
                ]],
            ] : null,
        ]);

        if ($withFrozenTaxes) {
            SaleItemTax::create([
                'sale_item_id' => $item->id,
                'tax_code' => '01',
                'tax_rate_code' => '08',
                'description' => 'IVA 13%',
                'treatment' => 'taxable',
                'rate' => 13,
                'factor_iva' => null,
                'base_amount' => 3000,
                'tax_amount' => 390,
                'specific_tax_data' => null,
                'exemption_snapshot' => null,
                'source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
                'source_version' => 'v4.4',
                'sequence' => 1,
            ]);
        }

        $sale->payments()->create([
            'payment_method_id' => $this->paymentMethodId($company),
            'affects_cash_snapshot' => true,
            'created_by' => $user->id,
            'amount' => 3390,
            'received_amount' => 3390,
            'change_amount' => 0,
            'cash_effect_amount' => 3390,
            'reference' => null,
            'status' => SalePayment::STATUS_COMPLETED,
        ]);

        return $sale;
    }

    private function paymentMethodId(Company $company): int
    {
        return (int) PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'EFECTIVO-'.$company->id,
            'name' => 'Efectivo',
            'type' => 'cash',
            'allows_change' => true,
            'affects_cash' => true,
            'is_active' => true,
        ])->id;
    }

    /**
     * @return array{0: Company, 1: Branch, 2: User, 3: Product}
     */
    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P'.uniqid(),
            'is_active' => true,
        ]);
        $user = User::factory()->create();

        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.uniqid(),
            'slug' => 'categoria-'.uniqid(),
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'u-'.uniqid(),
            'allows_decimals' => false,
            'is_active' => true,
        ]);
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.uniqid(),
            'internal_code' => 'P-'.uniqid(),
            'cost' => 600,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        return [$company, $branch, $user, $product];
    }
}
