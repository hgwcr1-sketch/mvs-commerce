<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\FiscalProfile;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\QuoteItemTax;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItemTax;
use App\Models\Unit;
use App\Models\User;
use App\Services\Fiscal\FiscalTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuoteFiscalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_creation_freezes_fiscal_snapshot_and_child_tax_rows(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, ['sale_price' => 1000, 'tax_rate' => 13]);

        $this->createQuote($user, $company, $branch, $product, ['quantity' => 1])->assertCreated();

        $quote = Quote::with('items')->firstOrFail();
        $item = $quote->items->first();

        $this->assertSame('13.0000', $item->tax_rate);
        $this->assertSame('130.0000', $item->tax_total);
        $this->assertSame('01', $item->tax_code);
        $this->assertSame('08', $item->tax_rate_code);
        $this->assertSame('taxable', $item->tax_treatment);
        $this->assertEquals('08', $item->fiscal_snapshot['taxes'][0]['codigoTarifa']);
        $this->assertEquals(13, $item->fiscal_snapshot['taxes'][0]['tarifa']);
        $this->assertSame('130.0000', (string) $quote->tax_total);

        $tax = QuoteItemTax::where('quote_item_id', $item->id)->firstOrFail();
        $this->assertSame('01', $tax->tax_code);
        $this->assertSame('08', $tax->tax_rate_code);
        $this->assertSame('taxable', $tax->treatment);
        $this->assertSame('13.0000', $tax->rate);
        $this->assertSame('1000.0000', $tax->base_amount);
        $this->assertSame('130.0000', $tax->tax_amount);
        $this->assertSame(1, (int) $tax->sequence);
        $this->assertNull($tax->exemption_snapshot);
    }

    public function test_ambiguous_legacy_tax_rates_are_blocked_without_creating_quotes(): void
    {
        foreach ([0, 8, null] as $rate) {
            [$company, $branch, $user] = $this->context();
            $product = $this->product($company, ['sale_price' => 1000, 'tax_rate' => $rate]);

            $this->createQuote($user, $company, $branch, $product, ['quantity' => 1])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('items');
        }

        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('quote_items', 0);
        $this->assertDatabaseCount('quote_item_taxes', 0);
    }

    public function test_zero_rate_exempt_and_not_subject_profiles_keep_their_own_treatment(): void
    {
        $expected = [
            ['01', 'zero_rate'],
            ['10', 'exempt'],
            ['11', 'not_subject'],
        ];

        foreach ($expected as [$rateCode, $treatment]) {
            [$company, $branch, $user] = $this->context();
            $product = $this->product($company, [
                'sale_price' => 1000,
                'tax_rate' => 0,
                'fiscal_profile_id' => $this->profile('01', $rateCode),
            ]);

            $this->createQuote($user, $company, $branch, $product, ['quantity' => 1])->assertCreated();

            $item = Quote::latest('id')->firstOrFail()->items->first();
            $this->assertSame('0.0000', $item->tax_rate);
            $this->assertSame('0.0000', $item->tax_total);
            $this->assertSame($rateCode, $item->tax_rate_code);
            $this->assertSame($treatment, $item->tax_treatment);
            $this->assertSame($treatment, $item->fiscal_snapshot['taxes'][0]['treatment']);
        }
    }

    public function test_conversion_is_blocked_when_product_fiscality_changed_after_quote(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, ['sale_price' => 1000, 'tax_rate' => 13]);
        $this->stock($branch, $product, 10);
        $quote = Quote::findOrFail($this->createQuote($user, $company, $branch, $product, ['quantity' => 1])->json('quote_id'));

        $product->update(['tax_rate' => 4]);

        $this->checkout($user, $company, $branch, $cash, $quote, [['product_id' => $product->id, 'quantity' => 1]], 1040)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_id');

        $this->assertSame(Quote::STATUS_ACTIVE, $quote->fresh()->status);
        $this->assertNull($quote->fresh()->converted_sale_id);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('sale_item_taxes', 0);
    }

    public function test_conversion_is_blocked_when_payload_product_is_not_in_the_quote(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $inQuote = $this->product($company, ['sale_price' => 1000, 'tax_rate' => 13]);
        $other = $this->product($company, ['sale_price' => 1000, 'tax_rate' => 13]);
        $this->stock($branch, $inQuote, 10);
        $this->stock($branch, $other, 10);
        $quote = Quote::findOrFail($this->createQuote($user, $company, $branch, $inQuote, ['quantity' => 1])->json('quote_id'));

        $this->checkout($user, $company, $branch, $cash, $quote, [['product_id' => $other->id, 'quantity' => 1]], 1130)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_id');

        $this->assertSame(Quote::STATUS_ACTIVE, $quote->fresh()->status);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_conversion_preserves_the_frozen_snapshot_on_the_generated_sale(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, ['sale_price' => 1000, 'tax_rate' => 13]);
        $this->stock($branch, $product, 10);
        $quote = Quote::findOrFail($this->createQuote($user, $company, $branch, $product, ['quantity' => 1])->json('quote_id'));

        $this->checkout($user, $company, $branch, $cash, $quote, [['product_id' => $product->id, 'quantity' => 1]], 1130)->assertOk();

        $quoteItem = $quote->fresh()->items()->first();
        $saleItem = Sale::firstOrFail()->items()->first();
        $fiscalTaxService = app(FiscalTaxService::class);

        $this->assertSame(
            $fiscalTaxService->serializeSnapshot($quoteItem->fiscal_snapshot),
            $fiscalTaxService->serializeSnapshot($saleItem->fiscal_snapshot),
        );
        $this->assertSame('13.0000', $saleItem->tax_rate);
        $this->assertSame('130.0000', $saleItem->tax_total);

        $tax = SaleItemTax::where('sale_item_id', $saleItem->id)->firstOrFail();
        $this->assertSame('08', $tax->tax_rate_code);
        $this->assertSame('13.0000', $tax->rate);
        $this->assertSame('1000.0000', $tax->base_amount);
        $this->assertSame('130.0000', $tax->tax_amount);

        $this->assertSame(Quote::STATUS_CONVERTED, $quote->fresh()->status);
        $this->assertSame($saleItem->sale_id, $quote->fresh()->converted_sale_id);
    }

    private function context(string $name = 'Empresa', array $permissions = ['pos.acceder', 'ventas.crear', 'cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', 'pos.cambiar_precio', 'pos.aplicar_descuento']): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'B'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);
        $cash = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'allows_change' => true]);
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'Q'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'Q-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => '0', 'opened_at' => now()]);

        return [$company, $branch, $user, $cash];
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'is_active' => true]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$id,
            'internal_code' => 'P-'.$id,
            'cost' => 500,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function profile(string $taxCode, string $rateCode): int
    {
        return (int) FiscalProfile::query()->where('tax_code', $taxCode)->where('tax_rate_code', $rateCode)->value('id');
    }

    private function createQuote(User $user, Company $company, Branch $branch, Product $product, array $line = [])
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('cotizaciones.store'), [
            'items' => [array_merge(['product_id' => $product->id, 'quantity' => 1], $line)],
        ]);
    }

    private function checkout(User $user, Company $company, Branch $branch, PaymentMethod $cash, Quote $quote, array $items, float $amount)
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'quote_id' => $quote->id,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => $amount, 'received_amount' => $amount]],
            'items' => $items,
        ]);
    }
}
