<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRewardRedemption;
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
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyRewardRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyMultiBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_company_keeps_a_fully_separate_balance(): void
    {
        [$companyA, $branchA, $customerA] = $this->context();
        [$companyB, $branchB, $customerB] = $this->context();

        $this->fund($customerA, $companyA, $branchA, 300);
        $this->fund($customerB, $companyB, $branchB, 120);

        $accounts = app(LoyaltyAccountService::class);

        $this->assertSame(2, LoyaltyAccount::query()->count());
        $this->assertSame('300.0000', $this->balance($customerA, $companyA));
        $this->assertSame('120.0000', $this->balance($customerB, $companyB));

        try {
            $accounts->getOrCreateAccount($customerB, $companyA);
            $this->fail('El cliente de otra empresa debió ser rechazado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer', $exception->errors());
        }

        $this->assertSame(2, LoyaltyAccount::query()->count());
        $this->assertSame('300.0000', $this->balance($customerA, $companyA));
        $this->assertSame('120.0000', $this->balance($customerB, $companyB));
    }

    public function test_pos_redemption_at_branch_b_spends_balance_earned_at_branch_a(): void
    {
        [$company, $user, $product] = $this->posContext();
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'Origen', 'code' => 'OR-'.uniqid(), 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'Ejecutora', 'code' => 'EJ-'.uniqid(), 'is_active' => true]);
        $user->branches()->syncWithoutDetaching([$branchA->id, $branchB->id]);
        DB::table('branch_product')->insert(['branch_id' => $branchB->id, 'product_id' => $product->id, 'stock' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);

        $this->fund($customer, $company, $branchA, 5000);

        $token = (string) Str::uuid();
        $response = $this->checkout($user, $company, $branchB, [$this->cashPayload($company, 500, 500)], $customer->id, $token, '500');
        $response->assertOk()->assertJsonPath('duplicate', false);

        $sale = Sale::firstOrFail();
        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $loyaltyPayment = SalePayment::query()->where('payment_method_id', $loyaltyMethod->id)->firstOrFail();
        $this->assertSame('500.0000', (string) $loyaltyPayment->amount);

        $redemption = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->sole();
        $this->assertSame('-500.0000', (string) $redemption->points);
        $this->assertSame($branchB->id, $redemption->branch_id);
        $this->assertSame("sale:{$sale->id}:loyalty:redemption", $redemption->event_key);
        $this->assertSame($customer->id, $redemption->customer_id);

        $earn = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_PURCHASE)->where('source_type', Sale::class)->first();
        if ($earn !== null) {
            $this->assertSame($branchB->id, $earn->branch_id);
        }

        $this->assertSame(1, LoyaltyAccount::query()->count());
        // 5000 iniciales - 500 canjeados (+ 50 si esta venta acumuló en B).
        $expected = bcsub(bcadd('5000.0000', (string) ($earn?->points ?? '0'), 4), '500.0000', 4);
        $this->assertSame($expected, (string) LoyaltyAccount::firstOrFail()->balance);

        $retry = $this->checkout($user, $company, $branchB, [$this->cashPayload($company, 500, 500)], $customer->id, $token, '500');
        $retry->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->count());
        $this->assertSame($expected, (string) LoyaltyAccount::firstOrFail()->balance);
    }

    public function test_unlimited_reward_can_be_redeemed_from_any_active_branch(): void
    {
        [$company, $branchA, $customer, $user] = $this->context();
        $branchB = $this->branchOf($company);

        $this->fund($customer, $company, $branchA, 500);
        $reward = $this->reward($company, 'unlimited');

        $service = app(LoyaltyRewardRedemptionService::class);
        $redemption = $service->redeem($customer, $reward, $company, $branchB, $user, ['event_key' => 'mb-u1']);

        $this->assertSame($branchB->id, $redemption->branch_id);
        $this->assertSame($branchB->id, $redemption->loyaltyMovement->branch_id);
        $this->assertSame('-100.0000', (string) $redemption->loyaltyMovement->points);
        $this->assertSame('400.0000', $this->balance($customer, $company));
        $this->assertSame(1, LoyaltyAccount::query()->count());

        $second = $service->redeem($customer, $reward, $company, $branchA, $user, ['event_key' => 'mb-u1']);
        $this->assertTrue($redemption->is($second));
        $this->assertSame('400.0000', $this->balance($customer, $company));
    }

    public function test_limited_reward_quota_is_global_across_branches(): void
    {
        [$company, $branchA, $customer, $user] = $this->context();
        $branchB = $this->branchOf($company);
        $reward = $this->reward($company, 'limited', ['stock_quantity' => '1.0000']);
        $this->fund($customer, $company, $branchA, 500);

        $service = app(LoyaltyRewardRedemptionService::class);
        $service->redeem($customer, $reward, $company, $branchA, $user, ['event_key' => 'mb-l1']);
        $this->assertSame('0.0000', (string) $reward->fresh()->stock_quantity);

        try {
            $service->redeem($customer, $reward, $company, $branchB, $user, ['event_key' => 'mb-l2']);
            $this->fail('El cupo limitado debió bloquear el canje desde otra sucursal.');
        } catch (ValidationException $exception) {
            $this->assertSame(['reward' => ['El premio no tiene cupo disponible.']], $exception->errors());
        }

        $this->assertSame(1, LoyaltyRewardRedemption::query()->count());
        $this->assertSame('400.0000', $this->balance($customer, $company));
    }

    public function test_product_reward_consumes_stock_of_the_executing_branch_only(): void
    {
        [$company, $branchA, $customer, $user] = $this->context();
        $branchB = $this->branchOf($company);
        $product = $this->product($company);
        DB::table('branch_product')->insert(['branch_id' => $branchA->id, 'product_id' => $product->id, 'stock' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $reward = $this->reward($company, 'product', ['product_id' => $product->id]);
        $this->fund($customer, $company, $branchA, 500);

        $service = app(LoyaltyRewardRedemptionService::class);

        try {
            $service->redeem($customer, $reward, $company, $branchB, $user, ['event_key' => 'mb-p1']);
            $this->fail('El canje debió bloquearse por falta de stock en la sucursal ejecutora.');
        } catch (ValidationException $exception) {
            $this->assertSame('El premio no tiene existencias disponibles en esta sucursal.', $exception->errors()['reward'][0]);
        }

        DB::table('branch_product')->insert(['branch_id' => $branchB->id, 'product_id' => $product->id, 'stock' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $redemption = $service->redeem($customer, $reward, $company, $branchB, $user, ['event_key' => 'mb-p2']);

        $movement = InventoryMovement::query()->sole();
        $this->assertSame($branchB->id, $movement->branch_id);
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame(0.0, (float) $this->stockAt($product, $branchB));
        $this->assertSame(5.0, (float) $this->stockAt($product, $branchA));
        $this->assertSame($branchB->id, $redemption->branch_id);
        $this->assertSame('400.0000', $this->balance($customer, $company));
        $this->assertDatabaseCount('loyalty_accounts', 1);
    }

    private function branchOf(Company $company): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => 'Sucursal B', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'maximum_redemption_percent' => '100.0000', 'redeem_on_offers' => false]);
        $user = User::factory()->create();

        return [$company, $branch, $customer, $user];
    }

    private function posContext(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        app(PaymentMethodProvisioner::class)->provision($company);
        $seedBranch = Branch::create(['company_id' => $company->id, 'name' => 'Semilla', 'code' => 'SM-'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'POS '.uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($seedBranch->id);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'maximum_redemption_percent' => '100.0000', 'redeem_on_offers' => false]);

        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => false, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);

        return [$company, $user, $product];
    }

    private function fund(Customer $customer, Company $company, Branch $branch, float $points): void
    {
        $accounts = app(LoyaltyAccountService::class);
        $account = $accounts->getOrCreateAccount($customer, $company);
        $accounts->addPoints($account, number_format($points, 4, '.', ''), \App\Models\LoyaltyMovement::TYPE_PURCHASE, [
            'branch' => $branch,
            'base_amount' => number_format($points * 20, 4, '.', ''),
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'description' => 'Acumulación inicial',
            'event_key' => 'seed:'.$customer->id.':'.uniqid(),
        ]);
    }

    private function reward(Company $company, string $mode, array $extra = []): LoyaltyReward
    {
        return LoyaltyReward::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Premio multigrupo',
            'type' => 'gift',
            'points_cost' => '100.0000',
            'is_active' => true,
            'availability_mode' => $mode,
        ], $extra));
    }

    private function product(Company $company): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoria '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    private function stockAt(Product $product, Branch $branch): ?float
    {
        $value = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock');

        return $value === null ? null : (float) $value;
    }

    private function balance(Customer $customer, Company $company): string
    {
        return (string) LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->value('balance');
    }

    private function cashPayload(Company $company, float $amount, float $received): array
    {
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();

        return ['payment_method_id' => $cash->id, 'amount' => $amount, 'received_amount' => $received, 'reference' => null];
    }

    private function checkout(User $user, Company $company, Branch $branch, array $payments, int $customerId, string $token, string $requestedPoints)
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->postJson(route('pos.checkout'), [
            'checkout_token' => $token,
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customerId,
            'payments' => $payments,
            'items' => [['product_id' => Product::query()->where('company_id', $company->id)->firstOrFail()->id, 'quantity' => 1]],
            'requested_points' => $requestedPoints,
        ]);
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
}
