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
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\SaleVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SaleVoidLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_without_points_voids_without_loyalty_movements(): void
    {
        [$company, $branch, $user] = $this->context();
        $sale = $this->checkoutWithoutCustomer($user, $company, $branch);

        app(SaleVoidService::class)->void($sale, $user, 'Error de caja');

        $this->assertSame(Sale::STATUS_VOIDED, $sale->fresh()->status);
        $this->assertSame(0, DB::table('loyalty_movements')->where('type', 'redemption')->count());
        $this->assertSame(0, DB::table('loyalty_movements')->where('type', 'void')->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_voiding_sale_with_redemption_reverses_points_and_keeps_traceability(): void
    {
        [$company, $branch, $user, $customer, $sale] = $this->contextWithSale('8000.0000', '500');

        app(SaleVoidService::class)->void($sale, $user, 'Cliente se arrepintió');

        $voidedSale = $sale->fresh();
        $this->assertSame(Sale::STATUS_VOIDED, $voidedSale->status);

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $loyaltyPayment = SalePayment::query()->where('sale_id', $sale->id)->where('payment_method_id', $loyaltyMethod->id)->firstOrFail();
        $this->assertSame(SalePayment::STATUS_VOIDED, $loyaltyPayment->status);
        $this->assertSame($user->id, $loyaltyPayment->voided_by);
        $this->assertNotNull($loyaltyPayment->voided_at);
        $this->assertSame(0.0, (float) $loyaltyPayment->cash_effect_amount);
        $this->assertDatabaseCount('cash_movements', 0);

        $account = LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('8050.0000', $account->balance);
        $this->assertSame('0.0000', $account->total_redeemed);
        $this->assertSame('50.0000', $account->total_earned);

        $redemption = LoyaltyMovement::query()->where('type', 'redemption')->where('source_id', $sale->id)->firstOrFail();
        $this->assertSame('-500.0000', $redemption->points);
        $this->assertSame("sale:{$sale->id}:loyalty:redemption", $redemption->event_key);

        $reversal = LoyaltyMovement::query()->where('type', 'void')->firstOrFail();
        $this->assertSame('500.0000', $reversal->points);
        $this->assertSame($redemption->id, $reversal->related_movement_id);
        $this->assertSame('7550.0000', $reversal->balance_before);
        $this->assertSame('8050.0000', $reversal->balance_after);
        $this->assertSame("sale:{$sale->id}:loyalty:redemption:void", $reversal->event_key);
        $this->assertSame(Sale::class, $reversal->source_type);
        $this->assertSame($sale->id, $reversal->source_id);
        $this->assertSame('Cliente se arrepintió', $reversal->metadata['void_reason']);
        $this->assertSame($user->id, $reversal->user_id);

        $chain = LoyaltyMovement::query()->where('loyalty_account_id', $account->id)->orderBy('id')->get(['type', 'points']);
        $this->assertSame(
            [
                ['type' => 'redemption', 'points' => '-500.0000'],
                ['type' => 'purchase', 'points' => '50.0000'],
                ['type' => 'void', 'points' => '500.0000'],
            ],
            $chain->map(fn ($movement) => ['type' => $movement->type, 'points' => $movement->points])->all(),
        );
    }

    public function test_second_void_attempt_does_not_duplicate_reversal(): void
    {
        [$company, $branch, $user, , $sale] = $this->contextWithSale('8000.0000', '500');

        app(SaleVoidService::class)->void($sale, $user, 'Primera anulación');

        try {
            app(SaleVoidService::class)->void($sale->fresh(), $user, 'Segundo intento');
            $this->fail('La segunda anulación debía rechazarse.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'void')->count());
        $account = LoyaltyAccount::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('8050.0000', $account->balance);
    }

    public function test_failed_reversal_rolls_back_entire_void_atomically(): void
    {
        [$company, $branch, $user, , $sale] = $this->contextWithSale('8000.0000', '500');

        $mock = Mockery::mock(LoyaltyAccountService::class);
        $mock->shouldReceive('reverseMovement')
            ->once()
            ->andThrow(ValidationException::withMessages(['reversal' => 'Fallo simulado de reversión.']));
        $this->instance(LoyaltyAccountService::class, $mock);

        try {
            app(SaleVoidService::class)->void($sale, $user, 'Motivo');
            $this->fail('La anulación debía fallar por la reversión.');
        } catch (ValidationException) {
        }

        $this->app->forgetInstance(LoyaltyAccountService::class);

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
        $this->assertSame(SalePayment::STATUS_COMPLETED, SalePayment::query()->where('sale_id', $sale->id)->where('payment_method_id', PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->value('id'))->value('status'));
        $this->assertSame(0, DB::table('loyalty_movements')->where('type', 'void')->count());
        $account = LoyaltyAccount::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('7550.0000', $account->balance);
    }

    public function test_invalid_reason_fails_before_any_write(): void
    {
        [$company, $branch, $user, , $sale] = $this->contextWithSale('8000.0000', '500');

        try {
            app(SaleVoidService::class)->void($sale, $user, '   ');
            $this->fail('El motivo vacío debía rechazarse.');
        } catch (ValidationException) {
        }

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
        $this->assertSame('7550.0000', LoyaltyAccount::query()->where('company_id', $company->id)->value('balance'));
    }

    public function test_cross_company_void_is_blocked_and_reverts_nothing(): void
    {
        [$companyA, $branchA] = $this->context('A');
        [$companyB, $branchB, $userB] = $this->context('B');
        $customer = $this->customerWithAccount($companyA, '8000.0000');
        $owner = $this->user($companyA, $branchA);
        $sale = $this->checkout($owner, $companyA, $branchA, $customer, '500');

        session(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id]);

        try {
            app(SaleVoidService::class)->void($sale, $userB, 'Intento ajeno');
            $this->fail('La anulación cruzada debía bloquearse.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'redemption')->count());
        $this->assertSame(0, DB::table('loyalty_movements')->where('type', 'void')->count());
    }

    public function test_two_sales_keep_independent_redemptions_on_void(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithAccount($company, '9000.0000');

        $saleOne = $this->checkout($user, $company, $branch, $customer, '500');
        $saleTwo = $this->checkout($user, $company, $branch, $customer, '300', (string) Str::uuid());

        app(SaleVoidService::class)->void($saleOne, $user, 'Anulación primera venta');

        $this->assertSame(1, DB::table('loyalty_movements')->where('type', 'void')->count());
        $this->assertSame(
            "sale:{$saleOne->id}:loyalty:redemption:void",
            LoyaltyMovement::query()->where('type', 'void')->value('event_key'),
        );

        $secondRedemption = LoyaltyMovement::query()
            ->where('type', 'redemption')
            ->where('source_id', $saleTwo->id)
            ->firstOrFail();
        $this->assertNull($secondRedemption->related_movement_id);

        $account = $customer->fresh() ? LoyaltyAccount::query()->where('customer_id', $customer->id)->firstOrFail() : null;
        $this->assertSame('8800.0000', $account->balance);
        $this->assertSame('300.0000', $account->total_redeemed);
    }

    private function checkoutWithoutCustomer(User $user, Company $company, Branch $branch): Sale
    {
        $product = $this->product($company);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();

        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1000, 'received_amount' => 1200, 'reference' => null]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertOk();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        app(PaymentMethodProvisioner::class)->provision($company);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => '1.0000', 'maximum_redemption_percent' => '100.0000', 'redeem_on_offers' => false]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P-'.uniqid(), 'is_active' => true]);
        $user = $this->user($company, $branch);

        return [$company, $branch, $user];
    }

    private function user(Company $company, Branch $branch): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear', 'ventas.anular'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function product(Company $company): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    private function customerWithAccount(Company $company, string $balance): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);

        return $customer;
    }

    private function checkout(User $user, Company $company, Branch $branch, Customer $customer, string $requestedPoints, ?string $token = null): Sale
    {
        $product = $this->product($company);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $pending = 1000 - (float) $requestedPoints;

        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->postJson(route('pos.checkout'), array_filter([
            'checkout_token' => $token ?? (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => $pending, 'received_amount' => $pending, 'reference' => null]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'requested_points' => $requestedPoints,
        ], fn ($value) => $value !== null));

        $response->assertOk();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function contextWithSale(string $balance = '8000.0000', string $requestedPoints = '500'): array
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithAccount($company, $balance);
        $sale = $this->checkout($user, $company, $branch, $customer, $requestedPoints);

        return [$company, $branch, $user, $customer, $sale];
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
