<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCheckoutLoyaltyRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_without_points_never_touches_loyalty(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 1000, 1000)])
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $this->assertSame(1, DB::table('sale_payments')->count());
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_redemption_creates_loyalty_payment_movement_and_updates_balance(): void
    {
        [$company, $branch, $user, , , $customer, $account] = $this->context('5000.0000');

        $response = $this->checkout(
            $user,
            $company,
            $branch,
            [$this->cashPayload($company, 500, 500)],
            $customer->id,
            null,
            '500',
        );
        $response->assertOk()->assertJsonPath('duplicate', false);

        $sale = Sale::firstOrFail();
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);

        $this->assertSame(2, DB::table('sale_payments')->count());

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $loyaltyPayment = SalePayment::query()->where('payment_method_id', $loyaltyMethod->id)->firstOrFail();
        $this->assertSame($sale->id, $loyaltyPayment->sale_id);
        $this->assertSame('500.0000', $loyaltyPayment->amount);
        $this->assertSame('500.0000', $loyaltyPayment->received_amount);
        $this->assertSame('0.0000', $loyaltyPayment->change_amount);
        $this->assertFalse($loyaltyPayment->affects_cash_snapshot);
        $this->assertSame('0.0000', $loyaltyPayment->cash_effect_amount);
        $this->assertNull($loyaltyPayment->reference);
        $this->assertSame($sale->cash_session_id, $loyaltyPayment->cash_session_id);
        $this->assertSame(SalePayment::STATUS_COMPLETED, $loyaltyPayment->status);

        $movement = LoyaltyMovement::query()->where('company_id', $company->id)->where('type', 'redemption')->firstOrFail();
        $this->assertSame('-500.0000', $movement->points);
        $this->assertSame('500.0000', $movement->base_amount);
        $this->assertSame(Sale::class, $movement->source_type);
        $this->assertSame($sale->id, $movement->source_id);
        $this->assertSame("sale:{$sale->id}:loyalty:redemption", $movement->event_key);
        $this->assertSame($customer->id, $movement->customer_id);

        $this->assertSame(2, DB::table('loyalty_movements')->count());
        $this->assertSame('4525.0000', $account->fresh()->balance);
        $this->assertSame('500.0000', $account->fresh()->total_redeemed);
        $this->assertSame('25.0000', $account->fresh()->total_earned);

        $cashPayment = SalePayment::query()->where('payment_method_id', '!=', $loyaltyMethod->id)->firstOrFail();
        $this->assertSame('500.0000', $cashPayment->amount);
        $this->assertSame('500.0000', $cashPayment->cash_effect_amount);

        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_insufficient_balance_rolls_back_entire_checkout(): void
    {
        [$company, $branch, $user, , , $customer, $account] = $this->context('100.0000');

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 500, 500)], $customer->id, null, '500')
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame('100.0000', $account->fresh()->balance);
        $this->assertSame('0.0000', $account->fresh()->total_redeemed);
    }

    public function test_payment_coverage_mismatch_after_redemption_rolls_back_everything(): void
    {
        [$company, $branch, $user, , , $customer, $account] = $this->context('5000.0000');

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 800, 800)], $customer->id, null, '500')
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame('5000.0000', $account->fresh()->balance);
        $this->assertSame('0.0000', $account->fresh()->total_redeemed);
    }

    public function test_credit_cannot_be_combined_with_points(): void
    {
        [$company, $branch, $user, , , $customer] = $this->context('5000.0000');

        $credit = PaymentMethod::forCompany($company->id)->where('type', 'credit')->firstOrFail();
        $this->checkout($user, $company, $branch, [
            ['payment_method_id' => $credit->id, 'amount' => 1000, 'received_amount' => null, 'reference' => null],
        ], $customer->id, null, '500')->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_zero_requested_points_is_rejected_without_touching_loyalty(): void
    {
        [$company, $branch, $user, , , $customer] = $this->context('5000.0000');

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 1000, 1000)], $customer->id, null, '0')
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_same_token_with_points_is_idempotent_and_does_not_duplicate_movement(): void
    {
        [$company, $branch, $user, , , $customer, $account] = $this->context('5000.0000');
        $token = (string) Str::uuid();
        $payments = [$this->cashPayload($company, 500, 500)];

        $first = $this->checkout($user, $company, $branch, $payments, $customer->id, $token, '500')->assertOk();
        $second = $this->checkout($user, $company, $branch, $payments, $customer->id, $token, '500')->assertOk();

        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertTrue((bool) $second->json('duplicate'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 2);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(2, DB::table('loyalty_movements')->count());
        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'redemption')->count());
        $this->assertSame('4525.0000', $account->fresh()->balance);
    }

    public function test_two_sales_produce_distinct_event_keys_and_consistent_balance(): void
    {
        [$company, $branch, $user, , , $customer, $account] = $this->context('5000.0000');

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 500, 500)], $customer->id, null, '500')->assertOk();
        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 700, 700)], $customer->id, (string) Str::uuid(), '300')->assertOk();

        $movements = DB::table('loyalty_movements')->where('company_id', $company->id)->where('type', 'redemption')->get();
        $this->assertCount(2, $movements);
        $saleIds = Sale::query()->pluck('id');
        foreach ($saleIds as $id) {
            $this->assertTrue($movements->contains(fn ($movement) => $movement->event_key === "sale:{$id}:loyalty:redemption"));
        }
        $this->assertSame('4260.0000', $account->fresh()->balance);
        $this->assertSame('800.0000', $account->fresh()->total_redeemed);
    }

    public function test_mixed_methods_with_points_keep_existing_method_rules(): void
    {
        [$company, $branch, $user, , , $customer] = $this->context('5000.0000');

        $card = PaymentMethod::forCompany($company->id)->where('type', 'card')->firstOrFail();

        $response = $this->checkout($user, $company, $branch, [
            ['payment_method_id' => $card->id, 'amount' => 300, 'received_amount' => null, 'reference' => 'CARD-1'],
            $this->cashPayload($company, 500, 700),
        ], $customer->id, null, '200');
        $response->assertOk()->assertJsonPath('total_change', '200.0000');

        $sale = Sale::firstOrFail();
        $this->assertSame('1000.0000', $sale->total);
        $this->assertSame(3, $sale->payments()->count());
        $this->assertEquals(1000, (float) $sale->payments()->sum('amount'));

        $cardPayment = $sale->payments()->where('payment_method_id', $card->id)->firstOrFail();
        $this->assertSame('CARD-1', $cardPayment->reference);
        $this->assertSame('0.0000', $cardPayment->cash_effect_amount);

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $loyaltyPayment = $sale->payments()->where('payment_method_id', $loyaltyMethod->id)->firstOrFail();
        $this->assertSame('200.0000', $loyaltyPayment->amount);
        $this->assertSame('0.0000', $loyaltyPayment->cash_effect_amount);
    }

    public function test_earning_base_excludes_real_redemption_for_cash_card_sinpe_and_full_payment(): void
    {
        [$company, $branch, $user, $product, , $customer, $account] = $this->context('50000.0000');
        $product->update(['sale_price' => 10000]);
        LoyaltySetting::where('company_id', $company->id)->update(['point_value' => '2.0000']);
        [$otherCompany, , , , , , $otherAccount] = $this->context('1234.0000');
        $otherBefore = $otherAccount->fresh()->getAttributes();

        foreach ([['cash', null, '10000.0000', '500.0000'], ['cash', '1000', '8000.0000', '400.0000'], ['card', '1000', '8000.0000', '400.0000'], ['sinpe', '1000', '8000.0000', '400.0000'], ['cash', '5000', '0.0000', '0.0000']] as [$type, $points, $base, $earned]) {
            $method = PaymentMethod::forCompany($company->id)->where('type', $type)->firstOrFail();
            $payments = $base === '0.0000' ? [] : [['payment_method_id' => $method->id, 'amount' => (int) $base, 'reference' => 'REF']];
            $token = (string) Str::uuid();
            $before = $account->fresh()->balance;
            $first = $this->checkout($user, $company, $branch, $payments, $customer->id, $token, $points)->assertOk();
            $this->checkout($user, $company, $branch, $payments, $customer->id, $token, $points)->assertOk()->assertJsonPath('duplicate', true);
            $movements = LoyaltyMovement::where('source_type', Sale::class)->where('source_id', $first->json('sale_id'))->get();
            $earning = $movements->where('type', LoyaltyMovement::TYPE_PURCHASE);
            if ($base === '0.0000') {
                $this->assertCount(0, $earning);
            } else {
                $this->assertCount(1, $earning);
                $this->assertSame($base, $earning->first()->base_amount);
                $this->assertSame($earned, $earning->first()->points);
                if ($points !== null) {
                    $this->assertSame(bcmul($points, '2', 4), $earning->first()->metadata['redeemed_amount']);
                    $this->assertSame($base, $earning->first()->metadata['earning_base_after_redemption']);
                }
            }
            $redemptions = $movements->where('type', LoyaltyMovement::TYPE_REDEMPTION);
            $this->assertCount($points === null ? 0 : 1, $redemptions);
            if ($points !== null) {
                $this->assertSame(bcsub('0', $points, 4), $redemptions->first()->points);
                $this->assertSame(bcmul($points, '2', 4), $redemptions->first()->base_amount);
                $this->assertSame('2.0000', $redemptions->first()->point_value);
            }
            $this->assertSame(bcadd(bcsub($before, $points ?? '0', 4), $earned, 4), $account->fresh()->balance);
        }
        $this->assertSame($otherBefore, $otherAccount->fresh()->getAttributes());
        $this->assertSame(0, LoyaltyMovement::where('company_id', $otherCompany->id)->count());
        $this->assertSame(5, Sale::where('company_id', $company->id)->count());
    }

    public function test_earning_reduction_preserves_offer_filter_taxes_discounts_and_multiplier(): void
    {
        [$company, $branch, $user, $product, , $customer] = $this->context('50000.0000');
        $product->update(['sale_price' => 6000, 'tax_rate' => 13]);
        $offer = $product->replicate();
        $offer->fill(['internal_code' => 'OFFER-'.uniqid(), 'sale_price' => 5500, 'special_price' => 5000, 'track_inventory' => false])->save();
        $role = $user->companies()->whereKey($company->id)->first()->pivot->role_id;
        $permission = Permission::firstOrCreate(['name' => 'pos.aplicar_descuento'], ['label' => 'Descuento', 'module' => 'POS', 'is_active' => true]);
        Role::findOrFail($role)->permissions()->syncWithoutDetaching($permission);
        $multiplier = \App\Models\LoyaltyMultiplier::create(['company_id' => $company->id, 'name' => 'Doble', 'multiplier' => '2.0000', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
        $session = $this->ensureCashSession($company, $branch, $user);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        foreach ([[false, '2260', '4800.0000', '480.0000'], [true, '2260', '8000.0000', '800.0000'], [false, '11300', '0.0000', '0.0000']] as [$earnOffers, $points, $base, $expected]) {
            LoyaltySetting::where('company_id', $company->id)->update(['earn_on_offers' => $earnOffers, 'redeem_on_offers' => true]);
            $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(), 'cash_session_id' => $session->id, 'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1], ['product_id' => $offer->id, 'quantity' => 1, 'discount' => 1000, 'discount_type' => 'fixed']],
                'requested_points' => $points, 'payments' => $points === '11300' ? [] : [['payment_method_id' => $cash->id, 'amount' => 11300 - (int) $points]],
            ])->assertOk();
            $sale = Sale::findOrFail($response->json('sale_id'));
            $this->assertSame('10000.0000', $sale->subtotal);
            $this->assertSame('1300.0000', $sale->tax_total);
            $this->assertSame('11300.0000', $sale->total);
            $movement = LoyaltyMovement::where('source_id', $sale->id)->where('source_type', Sale::class)->where('type', LoyaltyMovement::TYPE_PURCHASE)->first();
            if ($base === '0.0000') {
                $this->assertNull($movement);
            } else {
                $this->assertSame($base, $movement->base_amount);
                $this->assertSame($expected, $movement->points);
                $this->assertSame($multiplier->id, $movement->metadata['multiplier_id']);
                $this->assertSame('6000.0000', $movement->metadata['offer_eligibility']['normal_amount']);
                $this->assertSame('4000.0000', $movement->metadata['offer_eligibility']['offer_amount']);
            }
        }
    }

    public function test_taxed_sale_without_redemption_earns_only_on_pre_tax_base(): void
    {
        $this->assertTaxedEarning(null, '10000.0000', '500.0000');
    }

    public function test_taxed_sale_with_partial_redemption_excludes_tax_and_redeemed_amount(): void
    {
        $this->assertTaxedEarning('2260', '8000.0000', '400.0000');
    }

    public function test_taxed_sale_fully_paid_with_points_earns_nothing(): void
    {
        $this->assertTaxedEarning('11300', '0.0000', '0.0000');
    }

    public function test_non_terminating_allocation_rounds_once_at_four_decimals(): void
    {
        $this->assertTaxedEarning('1', '9999.1150', '499.9558');
    }

    public function test_mixed_tax_rates_and_exempt_lines_use_actual_invoice_funding_ratio(): void
    {
        [$company, $branch, $user, $product, , $customer] = $this->context('30000.0000');
        $product->update(['sale_price' => 6000, 'tax_rate' => 13]);
        $second = $product->replicate();
        $second->fill(['internal_code' => 'MIX-'.uniqid(), 'sale_price' => 4000, 'tax_rate' => 4, 'track_inventory' => false])->save();
        $session = $this->ensureCashSession($company, $branch, $user);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        foreach ([[4, 10940, 2188, 940], [0, 10780, 2156, 780]] as [$rate, $total, $points, $tax]) {
            $second->update(['tax_rate' => $rate]);
            $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(), 'cash_session_id' => $session->id, 'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1], ['product_id' => $second->id, 'quantity' => 1]],
                'requested_points' => (string) $points, 'payments' => [['payment_method_id' => $cash->id, 'amount' => $total - $points]],
            ])->assertOk();
            $sale = Sale::findOrFail($response->json('sale_id'));
            $this->assertSame('10000.0000', $sale->subtotal);
            $this->assertSame(bcadd((string) $tax, '0', 4), $sale->tax_total);
            $this->assertSame(bcadd((string) $total, '0', 4), $sale->total);
            $movement = LoyaltyMovement::where('source_id', $sale->id)->where('source_type', Sale::class)->where('type', LoyaltyMovement::TYPE_PURCHASE)->sole();
            $this->assertSame('8000.0000', $movement->base_amount);
            $this->assertSame('400.0000', $movement->points);
            $this->assertSame('2000.0000', $movement->metadata['eligible_base_paid_with_points']);
            $this->assertSame($sale->total, $movement->metadata['redemption_allocation_total']);
        }
    }

    private function assertTaxedEarning(?string $requestedPoints, string $expectedBase, string $expectedPoints): void
    {
        [$company, $branch, $user, $product, , $customer, $account] = $this->context('30000.0000');
        $product->update(['sale_price' => '10000.0000', 'tax_rate' => '13.0000']);
        $remaining = bcsub('11300', $requestedPoints ?? '0', 4);
        $payments = bccomp($remaining, '0', 4) === 0 ? [] : [$this->cashPayload($company, (float) $remaining, (float) $remaining)];
        $response = $this->checkout($user, $company, $branch, $payments, $customer->id, null, $requestedPoints)->assertOk();
        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertSame('10000.0000', $sale->subtotal);
        $this->assertSame('1300.0000', $sale->tax_total);
        $this->assertSame('11300.0000', $sale->total);
        $earning = LoyaltyMovement::where('source_type', Sale::class)->where('source_id', $sale->id)->where('type', LoyaltyMovement::TYPE_PURCHASE)->get();
        if ($expectedBase === '0.0000') {
            $this->assertCount(0, $earning);
        } else {
            $this->assertCount(1, $earning);
            $this->assertSame($expectedBase, $earning->sole()->base_amount);
            $this->assertSame($expectedPoints, $earning->sole()->points);
            $this->assertSame('10000.0000', $earning->sole()->metadata['offer_eligibility']['eligible_amount']);
            if ($requestedPoints !== null) {
                $this->assertSame(bcsub('10000', $expectedBase, 4), $earning->sole()->metadata['eligible_base_paid_with_points']);
            }
        }
        $redemptions = LoyaltyMovement::where('source_type', Sale::class)->where('source_id', $sale->id)->where('type', LoyaltyMovement::TYPE_REDEMPTION)->get();
        $this->assertCount($requestedPoints === null ? 0 : 1, $redemptions);
        if ($requestedPoints !== null) {
            $this->assertSame(bcadd($requestedPoints, '0', 4), $redemptions->sole()->base_amount);
        }
        $this->assertSame(bcadd(bcsub('30000', $requestedPoints ?? '0', 4), $expectedPoints, 4), $account->fresh()->balance);
    }

    public function test_full_redemption_never_earns_from_rounding_remainder(): void
    {
        [$company, $branch, $user, $product, , $customer, $account] = $this->context('20000.0000');
        $product->update(['sale_price' => '10000.4000']);
        $response = $this->checkout($user, $company, $branch, [], $customer->id, null, '10000')->assertOk();
        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertSame('10000.4000', $sale->subtotal);
        $this->assertSame('10000.0000', $sale->total);
        $this->assertSame(0, LoyaltyMovement::where('type', LoyaltyMovement::TYPE_PURCHASE)->count());
        $this->assertSame('10000.0000', $account->fresh()->balance);
    }

    private function context(string $balance = '5000.0000'): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        app(PaymentMethodProvisioner::class)->provision($company);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P-'.uniqid(), 'is_active' => true]);
        $user = $this->user($company, $branch);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => '1.0000', 'maximum_redemption_percent' => '100.0000', 'redeem_on_offers' => false]);

        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 50, 'created_at' => now(), 'updated_at' => now()]);

        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);

        return [$company, $branch, $user, $product, null, $customer, $account];
    }

    private function user(Company $company, Branch $branch): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    public function test_full_redemption_uses_configured_value_and_retries_without_duplicate_effects(): void
    {
        [$company, $branch, $user, , , $customer, $account] = $this->context();
        LoyaltySetting::where('company_id', $company->id)->update(['point_value' => '2.0000']);
        $before = $account->balance;
        $token = (string) Str::uuid();
        $first = $this->checkout($user, $company, $branch, [], $customer->id, $token, '500')->assertOk();
        $this->checkout($user, $company, $branch, [], $customer->id, $token, '500.0000')
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('sale_id', $first->json('sale_id'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $payment = SalePayment::firstOrFail();
        $this->assertSame('1000.0000', $payment->amount);
        $this->assertSame('0.0000', $payment->change_amount);
        $this->assertFalse($payment->affects_cash_snapshot);
        $movement = LoyaltyMovement::where('type', 'redemption')->sole();
        $this->assertSame('-500.0000', $movement->points);
        $this->assertSame('2.0000', $movement->point_value);
        $this->assertSame(0, LoyaltyMovement::where('type', LoyaltyMovement::TYPE_PURCHASE)->count());
        $this->assertSame(bcsub($before, '500', 4), $account->fresh()->balance);
    }

    public function test_empty_payments_require_full_valid_redemption_and_rollback_otherwise(): void
    {
        [$company, $branch, $user, , , $customer, $account] = $this->context();
        $before = $account->balance;
        foreach ([null, '0', '-1', '500', '1001', '999999'] as $points) {
            $this->checkout($user, $company, $branch, [], $customer->id, null, $points)->assertUnprocessable();
        }
        $this->checkout($user, $company, $branch, [], null, null, '1000')->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame($before, $account->fresh()->balance);
    }

    private function cashPayload(Company $company, float $amount, float $received): array
    {
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();

        return ['payment_method_id' => $cash->id, 'amount' => $amount, 'received_amount' => $received, 'reference' => null];
    }

    private function checkout(User $user, Company $company, Branch $branch, array $payments, ?int $customer = null, ?string $token = null, ?string $requestedPoints = null)
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), array_filter([
            'checkout_token' => $token ?? (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer,
            'payments' => $payments,
            'items' => [['product_id' => Product::query()->where('company_id', $company->id)->firstOrFail()->id, 'quantity' => 1]],
            'requested_points' => $requestedPoints,
        ], fn ($value) => $value !== null));
    }

    private function ensureCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $session = CashSession::query()->forCompany($company->id)->forBranch($branch->id)->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->first();
        if ($session) {
            return $session;
        }
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
