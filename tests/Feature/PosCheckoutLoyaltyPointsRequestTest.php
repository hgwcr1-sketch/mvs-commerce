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
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCheckoutLoyaltyPointsRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_without_requested_points_keeps_working(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 2000)
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $this->assertSame(1, DB::table('sale_payments')->count());
    }

    public function test_valid_requested_points_is_accepted_and_executes_redemption(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $account = $this->account($company, $customer, '5000.0000');

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 950, [], $customer->id, null, '50', 950)
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $sale = Sale::firstOrFail();
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame(2, DB::table('sale_payments')->count());
        $this->assertSame(PaymentMethod::TYPE_LOYALTY_POINTS, $sale->payments->first()->paymentMethod->type);
        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'redemption')->count());
        $this->assertSame('5000.0000', $account->fresh()->balance);
        $this->assertSame('50.0000', $account->fresh()->total_redeemed);
    }

    public function test_invalid_requested_points_is_rejected(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        foreach (['abc', '-5', '-1.5', '5000.00001', '1,5'] as $requestedPoints) {
            $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 2000, [], $customer->id, null, $requestedPoints)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_zero_requested_points_is_rejected(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        foreach (['0', '0.0000'] as $requestedPoints) {
            $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 2000, [], $customer->id, null, $requestedPoints)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_fractional_requested_points_keep_decimal_precision_and_rollback_on_non_integer_cash(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $account = $this->account($company, $customer, '5000.0000');

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 1000, [], $customer->id, null, '12.3456')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La suma de los pagos debe ser exactamente igual al total de la venta menos el monto canjeado con puntos.');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame('5000.0000', $account->fresh()->balance);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 950, [], $customer->id, (string) Str::uuid(), '50', 950)
            ->assertOk();

        $movement = LoyaltyMovement::query()->where('type', 'redemption')->firstOrFail();
        $this->assertSame('-50.0000', $movement->points);
        $this->assertSame('50.0000', $movement->base_amount);
    }

    public function test_requested_points_requires_customer(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 2000, [], null, null, '5000')
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_same_token_with_equivalent_requested_points_is_idempotent(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $account = $this->account($company, $customer, '5000.0000');
        $token = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $first = $this->checkout($user, $company, $branch, $cash, $items, 950, [], $customer->id, $token, '50', 950)->assertOk();
        $second = $this->checkout($user, $company, $branch, $cash, $items, 950, [], $customer->id, $token, '50.0000', 950)->assertOk();

        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertTrue((bool) $second->json('duplicate'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(2, DB::table('loyalty_movements')->count());
        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'redemption')->count());
        $this->assertSame('5000.0000', $account->fresh()->balance);
    }

    public function test_same_token_with_different_requested_points_conflicts(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $account = $this->account($company, $customer, '5000.0000');
        $token = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $this->checkout($user, $company, $branch, $cash, $items, 950, [], $customer->id, $token, '50', 950)->assertOk();

        $this->checkout($user, $company, $branch, $cash, $items, 940, [], $customer->id, $token, '60', 940)->assertConflict();

        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'redemption')->count());
        $this->assertSame('5000.0000', $account->fresh()->balance);
    }

    public function test_removing_requested_points_with_same_token_conflicts(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $account = $this->account($company, $customer, '5000.0000');
        $token = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $this->checkout($user, $company, $branch, $cash, $items, 950, [], $customer->id, $token, '50', 950)->assertOk();

        $this->checkout($user, $company, $branch, $cash, $items, 2000, [], $customer->id, $token)->assertConflict();

        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'redemption')->count());
        $this->assertSame('5000.0000', $account->fresh()->balance);
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = $this->company($name);
        app(PaymentMethodProvisioner::class)->provision($company);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => '1.0000', 'maximum_redemption_percent' => '100.0000', 'redeem_on_offers' => false]);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch);

        return [$company, $branch, $user, $this->payment($company)];
    }

    private function account(Company $company, Customer $customer, string $balance): LoyaltyAccount
    {
        return LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
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

    private function payment(Company $company): PaymentMethod
    {
        return PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'allows_change' => true]);
    }

    private function product(Company $company): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => 1000, 'stock' => 123, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function checkout(User $user, Company $company, Branch $branch, PaymentMethod $method, array $items, float $received, array $extra = [], ?int $customer = null, ?string $token = null, ?string $requestedPoints = null, ?float $amount = null)
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $appliedAmount = $amount ?? 1000;

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), array_filter([
            'checkout_token' => $token ?? (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer,
            'payments' => [['payment_method_id' => $method->id, 'amount' => $appliedAmount, 'received_amount' => $received, 'reference' => null]],
            'items' => $items,
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
