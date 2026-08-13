<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_creates_complete_cash_sale_for_final_consumer_from_server_values(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, true, false, ['sale_price' => 1000, 'cost' => 600, 'tax_rate' => 13, 'stock' => 999]);
        $this->stock($branch, $product, 5);

        $this->checkout($user, $company, $branch, $cash, [[
            'product_id' => $product->id, 'quantity' => 1,
        ]], 1200, [
            'price' => 1, 'total' => 1,
        ])->assertUnprocessable();

        $response = $this->checkout($user, $company, $branch, $cash, [[
            'product_id' => $product->id, 'quantity' => 1,
        ]], 1200);

        $response->assertOk()->assertJsonPath('duplicate', false)->assertJsonPath('sale_number', 'POS-00000001');
        $sale = Sale::with(['items', 'payments'])->firstOrFail();
        $this->assertNull($sale->customer_id);
        $this->assertSame('1000.0000', $sale->subtotal);
        $this->assertSame('130.0000', $sale->tax_total);
        $this->assertSame('0.0000', $sale->rounding_total);
        $this->assertSame('1130.0000', $sale->total);
        $this->assertSame('600.0000', $sale->items->first()->unit_cost);
        $this->assertSame('U', $sale->items->first()->unit_code);
        $this->assertCount(1, $sale->payments);
        $this->assertSame('70.0000', $sale->payments->first()->change_amount);
        $this->assertEquals(4, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame('999.00', $product->fresh()->stock);
    }

    public function test_customer_must_be_active_not_deleted_and_from_company(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        [$otherCompany] = $this->context('Otra');
        $product = $this->product($company, false);
        $valid = $this->customer($company);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 2000, [], $valid->id)->assertOk();

        foreach ([
            $this->customer($company, ['is_active' => false]),
            tap($this->customer($company), fn ($customer) => $customer->delete()),
            $this->customer($otherCompany),
        ] as $customer) {
            $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 2000, [], $customer->id, (string) Str::uuid())
                ->assertUnprocessable();
        }
    }

    public function test_crc_rounding_preserves_equation_and_rejects_insufficient_cash(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false, false, ['sale_price' => 10, 'tax_rate' => 13]);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 10)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 200)->assertOk();
        $sale = Sale::firstOrFail();
        $this->assertSame('10.0000', $sale->subtotal);
        $this->assertSame('1.3000', $sale->tax_total);
        $this->assertSame('-0.3000', $sale->rounding_total);
        $this->assertEquals((float) $sale->total, (float) $sale->subtotal + (float) $sale->tax_total + (float) $sale->rounding_total);
    }

    public function test_unit_decimal_policy_and_duplicate_products_are_enforced(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $whole = $this->product($company, false, false);
        $decimal = $this->product($company, false, true);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $whole->id, 'quantity' => 1.5]], 5000)->assertUnprocessable();
        $this->checkout($user, $company, $branch, $cash, [['product_id' => $decimal->id, 'quantity' => '0.12345']], 5000)->assertUnprocessable();
        $this->checkout($user, $company, $branch, $cash, [
            ['product_id' => $decimal->id, 'quantity' => 0.1234],
            ['product_id' => $decimal->id, 'quantity' => 0.8766],
        ], 5000)->assertOk();
        $this->assertSame('1.0000', Sale::firstOrFail()->items()->first()->quantity);
    }

    public function test_inventory_is_local_atomic_and_uncontrolled_products_create_no_movement(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $otherBranch = $this->branch($company, 'Otra');
        $controlled = $this->product($company, true);
        $uncontrolled = $this->product($company, false);
        $this->stock($branch, $controlled, 1);
        $this->stock($otherBranch, $controlled, 50);

        $this->checkout($user, $company, $branch, $cash, [
            ['product_id' => $uncontrolled->id, 'quantity' => 1],
            ['product_id' => $controlled->id, 'quantity' => 2],
        ], 10000)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertEquals(1, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $controlled->id)->value('stock'));

        $this->checkout($user, $company, $branch, $cash, [
            ['product_id' => $uncontrolled->id, 'quantity' => 1],
            ['product_id' => $controlled->id, 'quantity' => 1],
        ], 10000)->assertOk();
        $this->assertDatabaseCount('inventory_movements', 1);
        $movement = InventoryMovement::firstOrFail();
        $this->assertSame('sale', $movement->type);
        $this->assertSame(Sale::class, $movement->reference_type);
    }

    public function test_idempotency_returns_same_sale_and_rejects_changed_payload_without_second_stock_debit(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, true);
        $this->stock($branch, $product, 5);
        $token = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $first = $this->checkout($user, $company, $branch, $cash, $items, 5000, [], null, $token)->assertOk();
        $second = $this->checkout($user, $company, $branch, $cash, $items, 5000, [], null, $token)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertEquals(4, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));

        $this->checkout($user, $company, $branch, $cash, $items, 6000, [], null, $token)->assertConflict();
    }

    public function test_payment_method_rules_and_both_permissions_are_required(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false);
        foreach ([
            $this->payment($company, ['is_active' => false]),
            $this->payment($company, ['type' => 'card']),
            $this->payment($company, ['allows_change' => false]),
            $this->payment($this->company('Ajena')),
        ] as $method) {
            $this->checkout($user, $company, $branch, $method, [['product_id' => $product->id, 'quantity' => 1]], 5000)->assertUnprocessable();
        }

        [$company2, $branch2, $user2, $cash2] = $this->context('Sin ventas', ['pos.acceder']);
        $product2 = $this->product($company2, false);
        $this->checkout($user2, $company2, $branch2, $cash2, [['product_id' => $product2->id, 'quantity' => 1]], 5000)->assertForbidden();
    }

    public function test_receipt_is_visible_to_creator_or_sales_viewer_and_cross_company_is_hidden(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, false);
        $saleId = $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 5000)->json('sale_id');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('pos.receipt', $saleId))
            ->assertOk()->assertSee('Comprobante interno — pendiente de integración con Hacienda')->assertSee('Sin sesión de caja');

        $viewer = $this->user($company, $branch, ['pos.acceder', 'ventas.ver']);
        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))->get(route('pos.receipt', $saleId))->assertOk();

        $unauthorized = $this->user($company, $branch, ['pos.acceder']);
        $this->actingAs($unauthorized)->withSession($this->activeSession($company, $branch))->get(route('pos.receipt', $saleId))->assertForbidden();

        [$otherCompany, $otherBranch, $otherUser] = $this->context('Ajena');
        $this->actingAs($otherUser)->withSession($this->activeSession($otherCompany, $otherBranch))->get(route('pos.receipt', $saleId))->assertNotFound();
    }

    public function test_sequence_is_independent_per_company(): void
    {
        [$company, $branch, $user, $cash] = $this->context('Uno');
        [$company2, $branch2, $user2, $cash2] = $this->context('Dos');
        $product = $this->product($company, false);
        $product2 = $this->product($company2, false);

        $this->checkout($user, $company, $branch, $cash, [['product_id' => $product->id, 'quantity' => 1]], 5000)->assertJsonPath('sale_number', 'POS-00000001');
        $this->checkout($user2, $company2, $branch2, $cash2, [['product_id' => $product2->id, 'quantity' => 1]], 5000)->assertJsonPath('sale_number', 'POS-00000001');
    }

    private function context(string $name = 'Empresa', array $permissions = ['pos.acceder', 'ventas.crear']): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, $permissions);
        return [$company, $branch, $user, $this->payment($company)];
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }

    private function payment(Company $company, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'allows_change' => true], $attributes));
    }

    private function product(Company $company, bool $tracked, bool $decimals = false, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => $decimals, 'is_active' => true]);
        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => 1000, 'stock' => 123, 'tax_rate' => 13, 'track_inventory' => $tracked, 'is_active' => true], $attributes));
    }

    private function customer(Company $company, array $attributes = []): Customer
    {
        return Customer::create(array_merge(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true], $attributes));
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function checkout(User $user, Company $company, Branch $branch, PaymentMethod $method, array $items, int $received, array $extra = [], ?int $customer = null, ?string $token = null)
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), array_merge([
            'checkout_token' => $token ?? (string) Str::uuid(), 'customer_id' => $customer, 'payment_method_id' => $method->id, 'received_amount' => $received, 'items' => $items,
        ], $extra));
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
