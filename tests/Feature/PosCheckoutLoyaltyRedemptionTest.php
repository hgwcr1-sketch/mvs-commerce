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
        $this->assertSame('4550.0000', $account->fresh()->balance);
        $this->assertSame('500.0000', $account->fresh()->total_redeemed);
        $this->assertSame('50.0000', $account->fresh()->total_earned);

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
        $this->assertSame('4550.0000', $account->fresh()->balance);
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
        $this->assertSame('4300.0000', $account->fresh()->balance);
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
