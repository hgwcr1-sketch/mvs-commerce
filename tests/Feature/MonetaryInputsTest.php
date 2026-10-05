<?php

namespace Tests\Feature;

use App\Http\Controllers\ProductController;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MonetaryInputsTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = ['cost', 'sale_price', 'wholesale_price', 'special_price', 'price_a', 'price_b', 'price_c'];

    public function test_product_create_and_update_persist_both_separators_and_cents(): void
    {
        // Exercise actual controllers/FormRequests through the HTTP middleware stack.
        Route::middleware('web')->post('/_test/money/products', [ProductController::class, 'store']);
        Route::middleware('web')->put('/_test/money/products/{producto}', [ProductController::class, 'update']);
        $company = Company::create(['trade_name' => 'Decimal test', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Decimal', 'slug' => 'decimal', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'decimal-unit', 'is_active' => true]);
        $this->withSession(['active_company_id' => $company->id]);

        foreach (['370,87', '370.87', '0.01', '100.50', '500'] as $index => $value) {
            $payload = array_merge([
                'category_id' => $category->id, 'unit_id' => $unit->id,
                'name' => 'Decimal '.$index, 'internal_code' => 'DEC-'.$index,
                'product_type' => 'product', 'tax_rate' => '13', 'is_active' => true,
            ], array_fill_keys(self::FIELDS, $value));
            $this->postJson('/_test/money/products', $payload)->assertSuccessful();
            $product = Product::where('internal_code', 'DEC-'.$index)->firstOrFail();
            $this->assertMoney($product, $value);

            $product->update(array_fill_keys(self::FIELDS, '0'));
            // Regular form data also normalizes before UpdateProductRequest validation.
            $this->put('/_test/money/products/'.$product->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
            $this->assertMoney($product->fresh(), $value);
        }
    }

    public function test_nested_costs_and_supplier_cost_normalize_without_changing_quantities_or_codes(): void
    {
        Route::middleware('web')->post('/_test/money/nested', function (Request $request) {
            $request->validate([
                'current_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
                'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
                'items.*.new_sale_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            return $request->all();
        });
        foreach (['370,87', '370.87', '0.01', '100.50', '500', '0,1234'] as $value) {
            $expected = str_replace(',', '.', $value);
            $this->postJson('/_test/money/nested', [
                'current_cost' => $value,
                'items' => [['unit_cost' => $value, 'new_sale_price' => $value, 'quantity' => '1,25']],
                'internal_code' => '370,87',
            ])->assertOk()->assertJsonPath('current_cost', $expected)
                ->assertJsonPath('items.0.unit_cost', $expected)
                ->assertJsonPath('items.0.new_sale_price', $expected)
                ->assertJsonPath('items.0.quantity', '1,25')
                ->assertJsonPath('internal_code', '370,87');
        }
        foreach (['1,2,3', '1.234,56', 'abc', '-0,01'] as $invalid) {
            $this->post('/_test/money/nested', ['items' => [['unit_cost' => $invalid]]])
                ->assertRedirect()->assertSessionHasErrors('items.0.unit_cost');
        }
        $this->postJson('/_test/money/nested', ['current_cost' => null, 'items' => [['unit_cost' => '0.01', 'new_sale_price' => null]]])
            ->assertOk()->assertJsonPath('current_cost', null)->assertJsonPath('items.0.new_sale_price', null);
    }

    private function assertMoney(Product $product, string $value): void
    {
        foreach (self::FIELDS as $field) {
            $this->assertSame(bcadd(str_replace(',', '.', $value), '0', $field === 'cost' ? 4 : 2), $product->{$field}, $field);
        }
    }
}
