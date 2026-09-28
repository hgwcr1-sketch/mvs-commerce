<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryPostingSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_debits_only_its_branch_and_records_stock_and_reference(): void
    {
        [$sale, $product, $branch] = $this->context();
        $other = Branch::create(['company_id' => $sale->company_id, 'name' => 'Otra', 'code' => 'OTRA', 'is_active' => true]);
        $other->products()->attach($product->id, ['stock' => '50.0000']);

        $movement = DB::transaction(fn () => app(InventoryPostingService::class)->postSale($sale, $product, 2.125));

        $this->assertEquals(7.875, $this->stock($branch, $product));
        $this->assertEquals(50, $this->stock($other, $product));
        $this->assertEquals(123, $product->fresh()->stock);
        $this->assertSame('sale', $movement->type);
        $this->assertEquals(10, $movement->previous_stock);
        $this->assertEquals(7.875, $movement->new_stock);
        $this->assertEquals(2.125, $movement->quantity);
        $this->assertEquals($sale->company_id, $movement->company_id);
        $this->assertEquals($branch->id, $movement->branch_id);
        $this->assertEquals($product->id, $movement->product_id);
        $this->assertEquals($sale->user_id, $movement->user_id);
        $this->assertNull($movement->inventory_lot_id);
        $this->assertSame(Sale::class, $movement->reference_type);
        $this->assertEquals($sale->id, $movement->reference_id);
        $this->assertSame('Salida por venta', $movement->reason);
        $this->assertSame('Venta '.$sale->sale_number, $movement->notes);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    #[DataProvider('rejectedLines')]
    public function test_invalid_line_leaves_stock_and_movements_untouched(float $quantity, bool $missing, bool $foreign): void
    {
        [$sale, $product, $branch] = $this->context();
        if ($missing) {
            $branch->products()->detach($product->id);
        }
        if ($foreign) {
            $company = Company::create(['trade_name' => 'Ajena', 'currency' => 'CRC', 'is_active' => true]);
            $sale->company_id = $company->id;
        }
        try {
            DB::transaction(fn () => app(InventoryPostingService::class)->postSale($sale, $product, $quantity));
            $this->fail('La línea debe ser rechazada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertEquals($missing ? null : 10, $this->stock($branch, $product));
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function rejectedLines(): array
    {
        return [
            'zero' => [0, false, false],
            'negative' => [-1, false, false],
            'insufficient stock' => [11, false, false],
            'missing branch stock' => [1, true, false],
            'foreign company' => [1, false, true],
        ];
    }

    private function stock(Branch $branch, Product $product): mixed
    {
        return DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock');
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Hotfix venta', 'currency' => 'CRC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PR', 'is_active' => true]);
        $user = User::factory()->create();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General', 'slug' => 'general', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'unidad', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto', 'internal_code' => 'SKU', 'cost' => 1, 'sale_price' => 10, 'stock' => 123, 'track_inventory' => true, 'is_active' => true]);
        $branch->products()->attach($product->id, ['stock' => '10.0000']);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'checkout_token' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'hotfix'), 'sale_number' => 'POS-HOTFIX', 'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET, 'sale_condition' => Sale::CONDITION_CASH, 'status' => Sale::STATUS_COMPLETED, 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 10, 'discount_total' => 0, 'tax_total' => 0, 'rounding_total' => 0, 'total' => 10, 'paid_total' => 10, 'balance_due' => 0, 'completed_at' => now()]);

        return [$sale, $product, $branch];
    }
}
