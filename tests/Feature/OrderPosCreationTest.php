<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderPosCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_button_and_cashier_product_payload_are_permission_safe(): void
    {
        [$company, $branch] = $this->context();
        $creator = $this->user($company, $branch, ['pos.acceder', 'pedidos.crear']);
        $cashier = $this->user($company, $branch, ['pos.acceder']);
        $product = $this->product($company, false, ['name' => 'Harina', 'cost' => 650, 'sale_price' => 1250]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->get(route('pos.index'))
            ->assertOk()
            ->assertSee('data-testid="create-internal-order"', false)
            ->assertSee('Solicitar reposición', false)
            ->assertSee('Productos solicitados:', false)
            ->assertSee('Enviar solicitud', false);
        $this->actingAs($cashier)->withSession($this->activeSession($company, $branch))->get(route('pos.index'))
            ->assertOk()->assertDontSee('data-testid="create-internal-order"', false);

        $response = $this->actingAs($creator)->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.products.search', ['q' => 'Harina']))->assertOk()
            ->assertJsonPath('0.name', 'Harina')->assertJsonPath('0.available_stock', 0)
            ->assertJsonPath('0.sale_price', 1250)->assertJsonPath('0.unit', 'U')
            ->assertJsonPath('0.allows_decimals', false);
        $this->assertArrayNotHasKey('cost', $response->json('0'));
        $this->assertArrayNotHasKey('last_cost', $response->json('0'));
        $this->assertArrayNotHasKey('supplier', $response->json('0'));
    }

    public function test_authorized_pos_request_creates_pending_order_from_server_snapshots_without_side_effects(): void
    {
        [$company, $branch] = $this->context();
        $creator = $this->user($company, $branch, ['pos.acceder', 'pedidos.crear']);
        $product = $this->product($company, false, ['name' => 'Producto servidor', 'cost' => 700, 'sale_price' => 1750]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 6, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), [
            'notes' => 'Reposición semanal',
            'items' => [[
                'product_id' => $product->id,
                'requested_quantity' => 3,
                'request_note' => 'Prioritario',
                'price' => 1,
                'stock' => 999,
                'cost' => 1,
            ]],
        ])->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pedido creado correctamente: PED-00000001')
            ->assertJsonPath('number', 'PED-00000001');

        $order = Order::with('items')->findOrFail($response->json('order_id'));
        $item = $order->items->sole();
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame($company->id, $order->company_id);
        $this->assertSame($branch->id, $order->branch_id);
        $this->assertSame($creator->id, $order->user_id);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('3.0000', $item->requested_quantity);
        $this->assertSame('Prioritario', $item->request_note);
        $this->assertSame('1750.0000', $item->sale_price_snapshot);
        $this->assertSame('6.0000', $item->stock_snapshot);
        $this->assertSame('700.0000', $item->cost_snapshot);
        $this->assertArrayNotHasKey('cost_snapshot', $response->json());
        $this->assertArrayNotHasKey('stock_snapshot', $response->json());
        $this->assertSame(6.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_payable', 0);
    }

    public function test_permission_and_unit_quantity_rules_are_enforced_by_backend(): void
    {
        [$company, $branch] = $this->context();
        $withoutPermission = $this->user($company, $branch, ['pos.acceder']);
        $creator = $this->user($company, $branch, ['pos.acceder', 'pedidos.crear']);
        $whole = $this->product($company, false);
        $fractional = $this->product($company, true);

        $payload = fn (Product $product, float $quantity) => ['items' => [['product_id' => $product->id, 'requested_quantity' => $quantity]]];
        $this->actingAs($withoutPermission)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), $payload($whole, 1))->assertForbidden();
        try {
            app(OrderService::class)->create($payload($whole, 1.5), $creator, $company->id, $branch->id);
            $this->fail('La unidad entera no debe aceptar cantidades decimales.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        try {
            app(OrderService::class)->create(['items' => [
                ['product_id' => $whole->id, 'requested_quantity' => 0.5],
                ['product_id' => $whole->id, 'requested_quantity' => 0.5],
            ]], $creator, $company->id, $branch->id);
            $this->fail('La consolidación no debe ocultar cantidades decimales inválidas.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $fractionalResponse = $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), $payload($fractional, 1.5))->assertCreated();
        $this->assertDatabaseHas('order_items', ['order_id' => $fractionalResponse->json('order_id'), 'product_id' => $fractional->id, 'requested_quantity' => 1.5]);
    }

    public function test_multiple_products_create_one_order_with_correct_lines_context_and_no_commercial_effects(): void
    {
        [$company, $branch] = $this->context();
        $creator = $this->user($company, $branch, ['pos.acceder', 'pedidos.crear']);
        $whole = $this->product($company, false, ['name' => 'Producto entero']);
        $fractional = $this->product($company, true, ['name' => 'Producto decimal']);
        DB::table('branch_product')->insert([
            ['branch_id' => $branch->id, 'product_id' => $whole->id, 'stock' => 8, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $branch->id, 'product_id' => $fractional->id, 'stock' => 2.5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), [
            'notes' => 'Solicitud múltiple',
            'items' => [
                ['product_id' => $whole->id, 'requested_quantity' => 4, 'request_note' => 'Primera línea'],
                ['product_id' => $fractional->id, 'requested_quantity' => 1.5, 'request_note' => 'Segunda línea'],
            ],
        ])->assertCreated();

        $order = Order::with('items')->findOrFail($response->json('order_id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(2, $order->items);
        $this->assertSame($company->id, $order->company_id);
        $this->assertSame($branch->id, $order->branch_id);
        $this->assertSame($creator->id, $order->user_id);
        $this->assertSame('4.0000', $order->items->firstWhere('product_id', $whole->id)->requested_quantity);
        $this->assertSame('1.5000', $order->items->firstWhere('product_id', $fractional->id)->requested_quantity);
        $this->assertSame('8.0000', $order->items->firstWhere('product_id', $whole->id)->stock_snapshot);
        $this->assertSame('2.5000', $order->items->firstWhere('product_id', $fractional->id)->stock_snapshot);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(8.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $whole->id)->value('stock'));
        $this->assertSame(2.5, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $fractional->id)->value('stock'));
    }

    public function test_repeated_product_is_consolidated_into_one_line_instead_of_creating_duplicate_orders(): void
    {
        [$company, $branch] = $this->context();
        $creator = $this->user($company, $branch, ['pos.acceder', 'pedidos.crear']);
        $product = $this->product($company, false);

        $response = $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), [
            'items' => [
                ['product_id' => $product->id, 'requested_quantity' => 2, 'request_note' => 'Primera'],
                ['product_id' => $product->id, 'requested_quantity' => 3, 'request_note' => 'Segunda'],
            ],
        ])->assertCreated();

        $order = Order::with('items')->findOrFail($response->json('order_id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, $order->items);
        $this->assertSame('5.0000', $order->items->sole()->requested_quantity);
        $this->assertSame("Primera\nSegunda", $order->items->sole()->request_note);
    }

    public function test_any_invalid_line_rejects_the_complete_multiple_request_atomically(): void
    {
        [$company, $branch] = $this->context();
        [$otherCompany] = $this->context();
        $creator = $this->user($company, $branch, ['pos.acceder', 'pedidos.crear']);
        $valid = $this->product($company, false);
        $foreign = $this->product($otherCompany, false);
        $inactive = $this->product($company, false, ['is_active' => false]);

        foreach ([$foreign, $inactive] as $invalid) {
            try {
                app(OrderService::class)->create(['items' => [
                    ['product_id' => $valid->id, 'requested_quantity' => 1],
                    ['product_id' => $invalid->id, 'requested_quantity' => 1],
                ]], $creator, $company->id, $branch->id);
                $this->fail('Una línea inválida debe rechazar la solicitud completa.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items', $exception->errors());
            }
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
        }

        $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->postJson(route('pedidos.store'), [
            'items' => [['product_id' => $valid->id, 'requested_quantity' => 0]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Pedidos', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function product(Company $company, bool $allowsDecimals, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => $allowsDecimals, 'is_active' => true]);

        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true], $attributes));
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
