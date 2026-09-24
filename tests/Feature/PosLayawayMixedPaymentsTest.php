<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\LayawayPayment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cash\CashExpectedAmountService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosLayawayMixedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_layaway_single_payment_array_keeps_working(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 200,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 200, 'reference' => null, 'notes' => null]],
            'cash_session_id' => $session->id,
        ])->assertCreated();

        $layaway = Layaway::firstOrFail();
        $this->assertSame('1800.0000', $layaway->balance_due);
        $this->assertSame(1, LayawayPayment::where('layaway_id', $layaway->id)->count());
        $this->assertDatabaseHas('layaway_payments', ['layaway_id' => $layaway->id, 'payment_method_id' => $cash->id, 'amount' => '200.0000']);
        $this->assertSame('APT-00000001', $response->json('layaway_number'));
    }

    public function test_pos_layaway_legacy_single_payment_still_works(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 200,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
        ])->assertCreated();

        $layaway = Layaway::firstOrFail();
        $this->assertSame('1800.0000', $layaway->balance_due);
        $this->assertSame(1, LayawayPayment::where('layaway_id', $layaway->id)->count());
    }

    public function test_pos_layaway_mixed_cash_and_card_creates_one_line_per_method(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $card = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_CARD)->firstOrFail();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 1000,
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 400, 'reference' => null, 'notes' => null],
                ['payment_method_id' => $card->id, 'amount' => 600, 'reference' => 'TARJ-1', 'notes' => null],
            ],
            'cash_session_id' => $session->id,
        ])->assertCreated();

        $layaway = Layaway::firstOrFail();
        $this->assertSame('2000.0000', $layaway->total);
        $this->assertSame('1000.0000', $layaway->paid_total);
        $this->assertSame('1000.0000', $layaway->balance_due);
        $this->assertSame(2, LayawayPayment::where('layaway_id', $layaway->id)->count());
        $this->assertDatabaseHas('layaway_payments', ['layaway_id' => $layaway->id, 'payment_method_id' => $cash->id, 'amount' => '400.0000', 'affects_cash_snapshot' => true]);
        $this->assertDatabaseHas('layaway_payments', ['layaway_id' => $layaway->id, 'payment_method_id' => $card->id, 'amount' => '600.0000', 'reference' => 'TARJ-1', 'affects_cash_snapshot' => false]);
        $this->assertSame(400.0, app(CashExpectedAmountService::class)->calculate($session));
        $this->assertSame(3.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_mixed_card_and_sinpe_requires_no_cash_session(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $card = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_CARD)->firstOrFail();
        $sinpe = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_SINPE)->firstOrFail();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 1000,
            'payments' => [
                ['payment_method_id' => $card->id, 'amount' => 500, 'reference' => 'TARJ-9', 'notes' => null],
                ['payment_method_id' => $sinpe->id, 'amount' => 500, 'reference' => 'SINPE-9', 'notes' => null],
            ],
            'cash_session_id' => null,
        ])->assertCreated();

        $layaway = Layaway::firstOrFail();
        $this->assertSame('1000.0000', $layaway->paid_total);
        $this->assertSame(2, LayawayPayment::where('layaway_id', $layaway->id)->count());
        $this->assertDatabaseHas('layaway_payments', ['layaway_id' => $layaway->id, 'payment_method_id' => $card->id, 'cash_session_id' => null, 'reference' => 'TARJ-9']);
        $this->assertDatabaseHas('layaway_payments', ['layaway_id' => $layaway->id, 'payment_method_id' => $sinpe->id, 'cash_session_id' => null, 'reference' => 'SINPE-9']);
    }

    public function test_pos_layaway_mixed_wrong_sum_is_rejected(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $card = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_CARD)->firstOrFail();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 1000,
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 400, 'reference' => null, 'notes' => null],
                ['payment_method_id' => $card->id, 'amount' => 500, 'reference' => 'TARJ-2', 'notes' => null],
            ],
            'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('payments');

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_mixed_repeated_method_is_rejected(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 1000,
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 300, 'reference' => null, 'notes' => null],
                ['payment_method_id' => $cash->id, 'amount' => 700, 'reference' => null, 'notes' => null],
            ],
            'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('payments');

        $this->assertDatabaseCount('layaways', 0);
    }

    public function test_pos_layaway_mixed_reference_required_when_method_requires_it(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $card = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_CARD)->firstOrFail();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 1000,
            'payments' => [['payment_method_id' => $card->id, 'amount' => 1000, 'reference' => null, 'notes' => null]],
            'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('payments.0.reference');

        $this->assertDatabaseCount('layaways', 0);
    }

    public function test_pos_layaway_mixed_cash_part_requires_session(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $sinpe = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_SINPE)->firstOrFail();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 1000,
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 500, 'reference' => null, 'notes' => null],
                ['payment_method_id' => $sinpe->id, 'amount' => 500, 'reference' => 'SINPE-1', 'notes' => null],
            ],
            'cash_session_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('cash_session_id');

        $this->assertDatabaseCount('layaways', 0);
    }

    public function test_pos_layaway_mixed_rejects_credit_and_loyalty_methods(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        foreach ([PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS] as $type) {
            $method = PaymentMethod::forCompany($company->id)->where('type', $type)->firstOrFail();
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'initial_amount' => 1000,
                'payments' => [
                    ['payment_method_id' => $cash->id, 'amount' => 500, 'reference' => null, 'notes' => null],
                    ['payment_method_id' => $method->id, 'amount' => 500, 'reference' => null, 'notes' => null],
                ],
                'cash_session_id' => $session->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('payments.1.payment_method_id');
        }

        $this->assertDatabaseCount('layaways', 0);
    }

    public function test_pos_layaway_mixed_cannot_exceed_layaway_total(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $sinpe = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_SINPE)->firstOrFail();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 2500,
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 1500, 'reference' => null, 'notes' => null],
                ['payment_method_id' => $sinpe->id, 'amount' => 1000, 'reference' => 'SINPE-7', 'notes' => null],
            ],
            'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('payments');

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    private function context(string $name = 'Empresa', array $permissions = ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear']): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'layaway_validity_days' => 30, 'layaway_alert_days' => 5, 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P-'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);
        app(PaymentMethodProvisioner::class)->provision($company);
        $cash = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_CASH)->firstOrFail();
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        $session = CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => '0', 'opened_at' => now()]);

        return [$company, $branch, $user, $cash, $session];
    }

    private function product(Company $company): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
