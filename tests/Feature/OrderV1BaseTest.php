<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderV1BaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_models_relations_scopes_and_states_match_internal_requisition(): void
    {
        $this->assertTrue(Schema::hasColumns('orders', ['company_id', 'branch_id', 'user_id', 'number', 'status', 'notes', 'reviewed_at', 'reviewed_by', 'rejected_at', 'rejected_by', 'rejection_reason', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_at', 'updated_at']));
        $this->assertFalse(Schema::hasColumn('orders', 'customer_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'completed_sale_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'total'));
        $this->assertTrue(Schema::hasColumns('order_items', ['order_id', 'product_id', 'description', 'internal_code', 'barcode', 'unit_code', 'allows_decimals_snapshot', 'requested_quantity', 'stock_snapshot', 'sale_price_snapshot', 'cost_snapshot', 'last_cost_snapshot', 'approved_quantity', 'supplier_id', 'item_status', 'request_note', 'review_note', 'created_at', 'updated_at']));

        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $order = $this->createOrder($company, $branch, $user, $product);

        $this->assertTrue($order->company->is($company));
        $this->assertTrue($order->branch->is($branch));
        $this->assertTrue($order->user->is($user));
        $this->assertTrue($order->requester->is($user));
        $this->assertTrue($order->items->sole()->order->is($order));
        $this->assertTrue($order->items->sole()->product->is($product));
        $this->assertSame([$order->id], Order::forCompany($company->id)->forBranch($branch->id)->pending()->pluck('id')->all());
        $this->assertSame([$order->id], Order::active()->pluck('id')->all());
        $this->assertSame('Pendiente', $order->status_label);
    }

    public function test_creation_uses_server_values_and_saves_inventory_price_and_cost_snapshots(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, false, ['name' => 'Producto snapshot', 'internal_code' => 'P-100', 'barcode' => '744100', 'cost' => 450, 'sale_price' => 1250]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $this->historicalCost($company, $branch, $user, $product, 400);

        $order = $this->service()->create(['notes' => 'Faltante semanal', 'items' => [['product_id' => $product->id, 'requested_quantity' => 3, 'request_note' => 'Urgente']]], $user, $company->id, $branch->id);
        $item = $order->items->sole();

        $this->assertSame('PED-00000001', $order->number);
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame('Faltante semanal', $order->notes);
        $this->assertSame('Producto snapshot', $item->description);
        $this->assertSame('P-100', $item->internal_code);
        $this->assertSame('744100', $item->barcode);
        $this->assertSame('U', $item->unit_code);
        $this->assertFalse($item->allows_decimals_snapshot);
        $this->assertSame('3.0000', $item->requested_quantity);
        $this->assertSame('7.0000', $item->stock_snapshot);
        $this->assertSame('1250.0000', $item->sale_price_snapshot);
        $this->assertSame('450.0000', $item->cost_snapshot);
        $this->assertSame('400.0000', $item->last_cost_snapshot);
        $this->assertSame('0.0000', $item->approved_quantity);
        $this->assertNull($item->supplier_id);
        $this->assertSame('pending', $item->item_status);
        $this->assertSame('Urgente', $item->request_note);
    }

    public function test_company_branch_user_and_product_isolation_are_enforced(): void
    {
        [$company, $branch, $user] = $this->context();
        [$otherCompany, $otherBranch, $otherUser] = $this->context('Otra');
        $product = $this->product($company);
        $otherProduct = $this->product($otherCompany);

        $this->assertValidationError(fn () => $this->createOrder($company, $branch, $user, $otherProduct), 'items');
        $this->assertValidationError(fn () => $this->createOrder($company, $otherBranch, $user, $product), 'branch');
        $this->assertValidationError(fn () => $this->createOrder($company, $branch, $otherUser, $product), 'company');
        $this->assertValidationError(fn () => $this->createOrder($otherCompany, $otherBranch, $user, $otherProduct), 'company');
    }

    public function test_active_products_and_whole_or_fractional_quantities_follow_the_unit(): void
    {
        [$company, $branch, $user] = $this->context();
        $inactive = $this->product($company, false, ['is_active' => false]);
        $whole = $this->product($company);
        $fractional = $this->product($company, true);

        $this->assertValidationError(fn () => $this->createOrder($company, $branch, $user, $inactive), 'items');
        $this->assertValidationError(fn () => $this->createOrder($company, $branch, $user, $whole, 1.5), 'items');
        $this->assertValidationError(fn () => $this->createOrder($company, $branch, $user, $whole, 0), 'items');
        $order = $this->createOrder($company, $branch, $user, $fractional, 1.5);

        $this->assertSame('1.5000', $order->items->sole()->requested_quantity);
        $this->assertTrue($order->items->sole()->allows_decimals_snapshot);
    }

    public function test_company_sequence_is_used_independently(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $first = $this->createOrder($company, $branch, $user, $product);
        $second = $this->createOrder($company, $branch, $user, $product);

        $this->assertSame('PED-00000001', $first->number);
        $this->assertSame('PED-00000002', $second->number);
        $this->assertDatabaseHas('company_sequences', ['company_id' => $company->id, 'name' => CompanySequence::ORDER, 'current_value' => 2]);
    }

    public function test_creation_has_no_sale_payment_cash_receivable_payable_or_inventory_effect(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 9, 'created_at' => now(), 'updated_at' => now()]);

        $this->createOrder($company, $branch, $user, $product, 4);

        $this->assertSame(9.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_payable', 0);
        $this->assertFalse(Schema::hasTable('order_payments'));
    }

    public function test_http_creation_requires_permission_and_does_not_expose_or_trust_costs(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, false, ['cost' => 700, 'sale_price' => 1500]);
        $creator = $this->user($company, $branch, ['pedidos.crear']);

        $response = $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), [
            'items' => [['product_id' => $product->id, 'requested_quantity' => 2]],
        ])->assertCreated();
        $this->assertArrayNotHasKey('cost_snapshot', $response->json());
        $this->assertArrayNotHasKey('last_cost_snapshot', $response->json());
        $response->assertJsonPath('number', 'PED-00000001');
        $this->assertDatabaseHas('order_items', ['order_id' => $response->json('order_id'), 'cost_snapshot' => 700]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), ['items' => [['product_id' => $product->id, 'requested_quantity' => 1]]])->assertForbidden();
    }

    public function test_http_creation_rejects_frontend_snapshots(): void
    {
        [$company, $branch] = $this->context();
        $product = $this->product($company);
        $creator = $this->user($company, $branch, ['pedidos.crear']);

        $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), [
            'items' => [[
                'product_id' => $product->id,
                'requested_quantity' => 1,
                'stock_snapshot' => 999,
                'sale_price_snapshot' => 1,
                'cost_snapshot' => 1,
                'last_cost_snapshot' => 1,
            ]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    private function service(): OrderService { return app(OrderService::class); }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $user = $this->user($company, $branch, []);

        return [$company, $branch, $user];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $user->update(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Pedidos '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Pedidos', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function product(Company $company, bool $allowsDecimals = false, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => $allowsDecimals, 'is_active' => true]);

        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true], $attributes));
    }

    private function createOrder(Company $company, Branch $branch, User $user, Product $product, float $quantity = 1): Order
    {
        return $this->service()->create(['items' => [['product_id' => $product->id, 'requested_quantity' => $quantity]]], $user, $company->id, $branch->id);
    }

    private function historicalCost(Company $company, Branch $branch, User $user, Product $product, float $cost): void
    {
        $supplier = Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor', 'is_active' => true]);
        $purchase = Purchase::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'user_id' => $user->id, 'number' => 'CP-HIST', 'purchase_date' => today()->subDay(), 'payment_type' => 'cash', 'status' => 'posted']);
        PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_cost' => $cost, 'subtotal' => $cost, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0, 'total' => $cost]);
    }

    private function assertValidationError(callable $callback, string $key): void
    {
        try { $callback(); $this->fail("Se esperaba un error de validación en {$key}."); }
        catch (ValidationException $exception) { $this->assertArrayHasKey($key, $exception->errors()); }
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
