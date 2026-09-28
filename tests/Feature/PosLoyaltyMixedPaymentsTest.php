<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashDenomination;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
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
use App\Services\Cash\CashClosingService;
use App\Services\Cash\CashExpectedAmountService;
use App\Services\Cash\CashPaymentExpectedAmountService;
use App\Services\CashDenominationProvisioner;
use App\Services\CompanyCashSettingsProvisioner;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosLoyaltyMixedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_with_each_single_method_combination_work_dynamically(): void
    {
        [$company, $branch, $user] = $this->context();

        $combinations = [
            ['type' => 'cash', 'amount' => 800, 'received' => 800, 'reference' => null],
            ['type' => 'card', 'amount' => 800, 'received' => null, 'reference' => 'CARD-X'],
            ['type' => 'sinpe', 'amount' => 800, 'received' => null, 'reference' => 'SINPE-X'],
            ['type' => 'other', 'amount' => 800, 'received' => null, 'reference' => null],
        ];

        PaymentMethod::create(['company_id' => $company->id, 'code' => 'wallet-'.uniqid(), 'name' => 'Billetera', 'type' => 'other', 'is_active' => true, 'requires_reference' => false, 'allows_change' => false]);

        foreach ($combinations as $index => $combination) {
            $customer = $this->customerWithBalance($company, '9000.0000');
            $method = PaymentMethod::forCompany($company->id)->where('type', $combination['type'])->firstOrFail();

            $response = $this->checkout($user, $company, $branch, [
                $this->payload($method, $combination['amount'], $combination['received'], $combination['reference']),
            ], $customer->id, (string) Str::uuid(), '200');

            $response->assertOk()->assertJsonPath('duplicate', false)->assertJsonPath('sale_number', 'POS-'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT));
        }

        $this->assertSame(8, DB::table('sale_payments')->count());
        $this->assertSame(4, DB::table('loyalty_movements')->where('type', 'redemption')->count());
    }

    public function test_points_with_three_methods_and_custom_method_cover_pending_exactly(): void
    {
        [$company, $branch, $user] = $this->context();

        $paypal = PaymentMethod::create(['company_id' => $company->id, 'code' => 'paypal-'.uniqid(), 'name' => 'PayPal', 'type' => 'other', 'is_active' => true, 'requires_reference' => false, 'allows_change' => false]);
        $card = PaymentMethod::forCompany($company->id)->where('type', 'card')->firstOrFail();
        $sinpe = PaymentMethod::forCompany($company->id)->where('type', 'sinpe')->firstOrFail();
        $customer = $this->customerWithBalance($company, '5000.0000');

        $response = $this->checkout($user, $company, $branch, [
            $this->payload($card, 300, null, 'CARD-1'),
            $this->payload($sinpe, 300, null, 'SINPE-1'),
            $this->payload($paypal, 200),
        ], $customer->id, null, '200');

        $response->assertOk()->assertJsonPath('total_change', '0.0000');

        $sale = Sale::firstOrFail();
        $this->assertSame(4, $sale->payments()->count());
        $this->assertEquals(1000, (float) $sale->payments()->sum('amount'));

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $loyaltyPayment = $sale->payments()->where('payment_method_id', $loyaltyMethod->id)->firstOrFail();
        $this->assertSame('200.0000', $loyaltyPayment->amount);
        $this->assertSame('200.0000', $loyaltyPayment->received_amount);
        $this->assertSame('0.0000', $loyaltyPayment->change_amount);
        $this->assertFalse($loyaltyPayment->affects_cash_snapshot);
        $this->assertSame('0.0000', $loyaltyPayment->cash_effect_amount);
        $this->assertSame($sale->cash_session_id, $loyaltyPayment->cash_session_id);
        $this->assertNull($loyaltyPayment->reference);
        $this->assertSame(SalePayment::STATUS_COMPLETED, $loyaltyPayment->status);

        $this->assertSame('CARD-1', $sale->payments()->where('payment_method_id', $card->id)->value('reference'));
        $this->assertSame('SINPE-1', $sale->payments()->where('payment_method_id', $sinpe->id)->value('reference'));
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_change_rules_are_kept_alongside_points(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithBalance($company, '5000.0000');
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();

        $this->checkout($user, $company, $branch, [$this->payload($cash, 500, 800)], $customer->id, null, '500')
            ->assertOk()
            ->assertJsonPath('total_change', '300.0000');

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $sale = Sale::firstOrFail();
        $this->assertSame('0.0000', (string) $sale->payments()->where('payment_method_id', $loyaltyMethod->id)->value('change_amount'));
        $this->assertSame('300.0000', (string) $sale->payments()->where('payment_method_id', $cash->id)->value('change_amount'));
    }

    public function test_missing_reference_inactive_method_credit_and_over_or_under_payment_are_rejected(): void
    {
        [$company, $branch, $user] = $this->context();

        $card = PaymentMethod::forCompany($company->id)->where('type', 'card')->firstOrFail();
        $credit = PaymentMethod::forCompany($company->id)->where('type', 'credit')->firstOrFail();
        $customer = $this->customerWithBalance($company, '9000.0000');

        $this->checkout($user, $company, $branch, [$this->payload($card, 800)], $customer->id, null, '200')
            ->assertUnprocessable();

        $inactive = PaymentMethod::create(['company_id' => $company->id, 'code' => 'off-'.uniqid(), 'name' => 'Inactivo', 'type' => 'card', 'is_active' => false, 'requires_reference' => false, 'allows_change' => false]);
        $this->checkout($user, $company, $branch, [$this->payload($inactive, 800, null, 'REF')], $customer->id, (string) Str::uuid(), '200')
            ->assertUnprocessable();

        $this->checkout($user, $company, $branch, [
            ['payment_method_id' => $credit->id, 'amount' => 1000, 'received_amount' => null, 'reference' => null],
        ], $customer->id, (string) Str::uuid(), '200')->assertUnprocessable();

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 600, 600)], $customer->id, (string) Str::uuid(), '500')
            ->assertUnprocessable();

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 400, 400)], $customer->id, (string) Str::uuid(), '500')
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertSame('9000.0000', LoyaltyAccount::query()->where('customer_id', $customer->id)->value('balance'));
    }

    public function test_plain_sale_without_points_remains_intact(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->checkout($user, $company, $branch, [$this->cashPayload($company, 1000, 1200)])
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $this->assertSame(1, DB::table('sale_payments')->count());
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_mixed_checkout_with_points_is_idempotent_without_duplicates(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithBalance($company, '5000.0000');
        $card = PaymentMethod::forCompany($company->id)->where('type', 'card')->firstOrFail();
        $token = (string) Str::uuid();
        $payments = [$this->payload($card, 800, null, 'CARD-IDEM')];

        $first = $this->checkout($user, $company, $branch, $payments, $customer->id, $token, '200')->assertOk();
        $second = $this->checkout($user, $company, $branch, $payments, $customer->id, $token, '200')->assertOk();

        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertTrue((bool) $second->json('duplicate'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 2);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'redemption')->count());
    }

    public function test_cash_closing_includes_loyalty_in_reconciliation_but_not_in_expected_cash(): void
    {
        [$company, $branch, $user, $session] = $this->closingContext();

        $expensive = $this->product($company, 20000);
        $this->stock($branch, $expensive, 5);
        $customer = $this->customerWithBalance($company, '90000.0000');

        $this->checkoutProduct($user, $company, $branch, $expensive, [$this->cashPayload($company, 15000, 15000)], $customer->id, null, '5000')
            ->assertOk();

        $expectedCash = app(CashExpectedAmountService::class)->calculate($session->fresh());
        $this->assertSame(15000.0, $expectedCash);

        $breakdown = app(CashPaymentExpectedAmountService::class)->breakdown($session->fresh());
        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $cashMethod = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $this->assertSame(5000.0, $breakdown->get($loyaltyMethod->id)['sales']);
        $this->assertSame(15000.0, $breakdown->get($cashMethod->id)['sales']);

        $token = (string) Str::uuid();
        $service = app(CashClosingService::class);
        $service->start($user, $company->id, $branch->id, $session->id, $token);

        $denominations = [];
        foreach (CashDenomination::query()->forCompany($company->id)->forCurrency('CRC')->active()->get() as $denomination) {
            $denominations[$denomination->id] = match ((float) $denomination->value) {
                10000.0, 5000.0 => 1,
                default => 0,
            };
        }

        $methods = app(CashPaymentExpectedAmountService::class)->methods($session->fresh());
        $reported = [];
        foreach ($methods as $method) {
            $expected = $breakdown->get($method->id)['total'] ?? 0.0;
            $reported[$method->id] = ['reported_amount' => number_format($expected, 2, '.', ''), 'reference' => null, 'notes' => null];
        }

        $result = $service->submit($user, $company->id, $branch->id, $session->id, [
            'request_token' => $token,
            'denominations' => $denominations,
            'payments' => $reported,
        ]);

        $this->assertFalse($result['duplicate']);
        $this->assertFalse($result['requires_authorization']);
        $closedSession = $result['session'];
        $this->assertSame(CashSession::STATUS_CLOSED, $closedSession->status);
        $this->assertSame(15000.0, (float) $closedSession->expected_cash);
        $this->assertSame(15000.0, (float) $closedSession->counted_cash);
        $this->assertSame(0.0, (float) $closedSession->difference_amount);

        $reconciliations = $closedSession->paymentReconciliations()->get()->keyBy('payment_method_id');
        $loyaltyRow = $reconciliations->get($loyaltyMethod->id);
        $this->assertNotNull($loyaltyRow);
        $this->assertSame(PaymentMethod::TYPE_LOYALTY_POINTS, $loyaltyRow->payment_method_type_snapshot);
        $this->assertSame(5000.0, (float) $loyaltyRow->sales_amount);
        $this->assertSame(5000.0, (float) $loyaltyRow->expected_amount);
        $this->assertSame(5000.0, (float) $loyaltyRow->reported_amount);
        $this->assertSame(0.0, (float) $loyaltyRow->difference_amount);

        $cashRow = $reconciliations->get($cashMethod->id);
        $this->assertSame(15000.0, (float) $cashRow->sales_amount);
        $this->assertSame(0.0, (float) $cashRow->difference_amount);
    }

    private function closingContext(): array
    {
        [$company, $branch, $user] = $this->context();
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        app(CashDenominationProvisioner::class)->provision($company);

        return [$company, $branch, $user, $this->ensureCashSession($company, $branch, $user)];
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = $this->company($name);
        app(PaymentMethodProvisioner::class)->provision($company);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => '1.0000', 'maximum_redemption_percent' => '100.0000', 'redeem_on_offers' => false]);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch);
        $product = $this->product($company, 1000);
        $this->stock($branch, $product, 50);

        return [$company, $branch, $user];
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
        foreach (['pos.acceder', 'ventas.crear', 'caja.cerrar'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function product(Company $company, float $price): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => $price, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function customerWithBalance(Company $company, string $balance): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);

        return $customer;
    }

    private function payload(PaymentMethod $method, float $amount, ?float $received = null, ?string $reference = null): array
    {
        return ['payment_method_id' => $method->id, 'amount' => $amount, 'received_amount' => $received, 'reference' => $reference];
    }

    private function cashPayload(Company $company, float $amount, float $received): array
    {
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();

        return ['payment_method_id' => $cash->id, 'amount' => $amount, 'received_amount' => $received, 'reference' => null];
    }

    private function checkout(User $user, Company $company, Branch $branch, array $payments, ?int $customer = null, ?string $token = null, ?string $requestedPoints = null, ?Product $product = null)
    {
        $product ??= Product::query()->where('company_id', $company->id)->orderBy('id')->firstOrFail();

        return $this->checkoutProduct($user, $company, $branch, $product, $payments, $customer, $token, $requestedPoints);
    }

    private function checkoutProduct(User $user, Company $company, Branch $branch, Product $product, array $payments, ?int $customer = null, ?string $token = null, ?string $requestedPoints = null)
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), array_filter([
            'checkout_token' => $token ?? (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer,
            'payments' => $payments,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
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
