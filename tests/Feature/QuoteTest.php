<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ManagesQuoteFixtures;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;
    use ManagesQuoteFixtures;

    public function test_quote_number_uses_cot_format_and_increments(): void
    {
        [$company, $branch, $user] = $this->context('Cotizaciones ');

        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $first = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);
        $second = $this->makeQuote($user, $company, $branch, [['product_id' => $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13])->id, 'quantity' => 1]]);

        $this->assertSame('COT-00000001', $first->quote_number);
        $this->assertSame('COT-00000002', $second->quote_number);
    }

    public function test_quote_creation_persists_exact_snapshots_and_creates_no_side_effects(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, true, false, ['sale_price' => 1000, 'cost' => 600, 'tax_rate' => 13, 'stock' => 123]);
        $this->stock($branch, $product, 5);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 2]]);
        $quote->load('items');

        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('quote_items', 1);

        $this->assertSame('2000.0000', (string) $quote->subtotal);
        $this->assertSame('0.0000', (string) $quote->discount_total);
        $this->assertSame('260.0000', (string) $quote->tax_total);
        $this->assertSame('2260.0000', (string) $quote->total);
        $this->assertNull($quote->converted_sale_id);
        $this->assertNull($quote->converted_at);
        $this->assertFalse($quote->converted);
                $this->assertFalse($quote->cancelled);
        $this->assertTrue($quote->cancellation_enabled);

        $item = $quote->items->first();
        $this->assertSame((string) $product->id, (string) $item->product_id);
        $this->assertSame('2.0000', (string) $item->quantity);
        $this->assertSame('1000.0000', (string) $item->unit_price);
        $this->assertSame('0.0000', (string) $item->discount_total);
        $this->assertSame('13.0000', (string) $item->tax_rate);
        $this->assertSame('260.0000', (string) $item->tax_total);
        $this->assertSame('2260.0000', (string) $item->total);
        $this->assertSame('600.0000', (string) $item->unit_cost);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
                $this->assertSame(5, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame('5.00', $product->fresh()->stock);
    }

    public function test_quote_snapshots_are_immutable_when_product_price_changes_after_creation(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);
        $quote->load('items');

        $this->assertSame('1000.0000', (string) $quote->items->first()->unit_price);
        $this->assertSame('1130.0000', (string) $quote->total);

        $product->update(['sale_price' => 5000, 'tax_rate' => 0]);

        $quote->refresh()->load('items');

        $this->assertSame('1000.0000', (string) $quote->items->first()->unit_price);
        $this->assertSame('1130.0000', (string) $quote->total);
        $this->assertSame('5000.0000', (string) $product->fresh()->sale_price);
    }

    public function test_cancellation_records_user_date_and_reason(): void
    {
        [$company, $branch, $user] = $this->context();
        $cancelUser = $this->user($company, $branch, ['cotizaciones.ver', 'ventas.anular']);
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);
        $before = now()->subMinute();

        $this->cancelQuote($cancelUser, $company, $branch, $quote, 'Cliente no retoma')->assertOk();

        $quote->refresh();

        $this->assertTrue($quote->cancelled);
        $this->assertSame(Quote::STATUS_CANCELLED, $quote->status);
        $this->assertFalse($quote->cancellation_enabled);
        $this->assertNotNull($quote->cancelled_at);
        $this->assertSame($cancelUser->id, (int) $quote->cancelled_by);
        $this->assertSame('Cliente no retoma', $quote->cancellation_reason);
        $this->assertGreaterThanOrEqual($before, $quote->cancelled_at);
        $this->assertFalse($quote->isActive());
        $this->assertFalse($quote->canBeConverted());
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cancelled_quote_cannot_be_cancelled_again(): void
    {
        [$company, $branch, $user] = $this->context();
        $cancelUser = $this->user($company, $branch, ['cotizaciones.ver', 'ventas.anular']);
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);

        $this->cancelQuote($cancelUser, $company, $branch, $quote, 'Primera')->assertOk();
        $this->cancelQuote($cancelUser, $company, $branch, $quote, 'Segunda')->assertUnprocessable();

        $quote->refresh();
        $this->assertSame(Quote::STATUS_CANCELLED, $quote->status);
        $this->assertSame('Primera', $quote->cancellation_reason);
    }
}
