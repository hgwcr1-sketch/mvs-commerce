<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ManagesQuoteFixtures;
use Tests\TestCase;

class PosQuoteConversionTest extends TestCase
{
    use RefreshDatabase;
    use ManagesQuoteFixtures;

    public function test_quote_conversion_uses_quote_snapshots_and_marks_quote_converted(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, true, false, ['sale_price' => 1000, 'cost' => 600, 'tax_rate' => 13, 'stock' => 123]);
        $this->stock($branch, $product, 5);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 2]]);
        $this->assertSame('2260.0000', (string) $quote->total);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 2]], 2260, ['quote_id' => $quote->id])
            ->assertOk()->assertJsonPath('duplicate', false);

        $sale = Sale::firstOrFail();
        $this->assertSame('2260.0000', (string) $sale->total);
        $this->assertSame('2000.0000', (string) $sale->subtotal);
        $this->assertSame('260.0000', (string) $sale->tax_total);
        $this->assertSame('0.0000', (string) $sale->discount_total);
        $this->assertCount(1, $sale->payments);

        $item = $sale->items->first();
        $this->assertSame((string) $product->id, (string) $item->product_id);
        $this->assertSame('1000.0000', (string) $item->unit_price);
        $this->assertSame('2.0000', (string) $item->quantity);
        $this->assertSame('0.0000', (string) $item->discount_total);
        $this->assertSame('13.0000', (string) $item->tax_rate);
        $this->assertSame('2260.0000', (string) $item->total);

        $quote->refresh();
        $this->assertTrue($quote->converted);
        $this->assertSame(Quote::STATUS_CONVERTED, $quote->status);
        $this->assertSame($sale->id, (int) $quote->converted_sale_id);
        $this->assertNotNull($quote->converted_at);

        $this->assertSame(3, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_quote_conversion_rejects_tampered_unit_price(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 9999]], 1130, ['quote_id' => $quote->id])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $quote->refresh();
        $this->assertTrue($quote->isActive(), 'La cotización debe quedar activa tras un cobro rechazado.');
    }

    public function test_quote_conversion_rejects_tampered_quantity(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 5]], 1130, ['quote_id' => $quote->id])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }


    public function test_quote_conversion_cannot_use_expired_quote(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]], ['expires_at' => now()->subDay()->toIso8601String()]);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 1130, ['quote_id' => $quote->id])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $quote->refresh();
        $this->assertSame(Quote::STATUS_ACTIVE, $quote->status);
    }

    public function test_quote_conversion_cannot_use_cancelled_quote(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $cancelUser = $this->user($company, $branch, ['cotizaciones.ver', 'ventas.anular']);
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);
        $this->cancelQuote($cancelUser, $company, $branch, $quote, 'No aplica')->assertOk();

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 1130, ['quote_id' => $quote->id])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_quote_conversion_cannot_convert_a_quote_twice(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, true, false, ['sale_price' => 1000, 'cost' => 600, 'tax_rate' => 13, 'stock' => 123]);
        $this->stock($branch, $product, 5);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 2]]);
        $token = (string) Str::uuid();

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 2]], 2260, ['quote_id' => $quote->id], null, $token)
            ->assertOk();
        $this->assertDatabaseCount('sales', 1);

        // Same token -> idempotent duplicate, no second sale.
        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 2]], 2260, ['quote_id' => $quote->id], null, $token)
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('sales', 1);

        // New token -> the quote is already converted and must be rejected.
        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 2]], 2260, ['quote_id' => $quote->id])
            ->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_quote_conversion_is_scoped_to_active_branch(): void
    {
        $company = $this->company('Empresa ');
        $branchA = $this->branch($company, 'A');
        $branchB = $this->branch($company, 'B');
        $userA = $this->user($company, $branchA, ['pos.acceder', 'ventas.crear', 'cotizaciones.ver']);
        $userB = $this->user($company, $branchB, ['pos.acceder', 'ventas.crear', 'cotizaciones.ver']);
        $cash = $this->payment($company);
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($userB, $company, $branchB, [['product_id' => $product->id, 'quantity' => 1]]);

        $this->checkout($userA, $company, $branchA, $cash, [['product_id' => $product->id, 'quantity' => 1]], 1130, ['quote_id' => $quote->id])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_quote_conversion_rejects_tampered_product_id(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);
        $other = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $other->id, 'quantity' => 1]], 1130, ['quote_id' => $quote->id])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_quote_conversion_rejects_tampered_discount(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);

        $quote = $this->makeQuote($user, $company, $branch, [['product_id' => $product->id, 'quantity' => 1]]);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1, 'discount' => 50, 'discount_type' => 'fixed']], 1130, ['quote_id' => $quote->id])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }
}
