<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PurchaseOrderPreparationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_supplier_and_product_are_consolidated_with_complete_traceability(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['pedidos.preparar_compra']);
        $product = $this->product($company, ['name' => 'Pantaloneta']);
        $supplier = $this->supplier($company, ['name' => 'MAYCA']);
        $this->associate($product, $supplier, ['supplier_product_code' => 'MY-77', 'current_cost' => 1250.4321]);
        $first = $this->approvedLine($company, $branch, $user, $product, $supplier, 10);
        $second = $this->approvedLine($company, $branch, $user, $product, $supplier, 5);

        $orders = $this->prepare($user, $company, $branch, [[$first, 10], [$second, 5]]);

        $purchaseOrder = $orders->sole();
        $item = $purchaseOrder->items->sole();
        $this->assertSame(PurchaseOrder::STATUS_PREPARED, $purchaseOrder->status);
        $this->assertSame($supplier->id, $purchaseOrder->supplier_id);
        $this->assertSame('15.0000', $item->ordered_quantity);
        $this->assertSame('15.0000', $item->requested_quantity);
        $this->assertSame('MY-77', $item->supplier_product_code);
        $this->assertSame('1250.4321', $item->unit_cost_snapshot);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $item->sources->pluck('order_item_id')->all());
        $this->assertSame(15.0, (float) $item->sources->sum('allocated_quantity'));
        $this->assertSame(Order::STATUS_IN_PURCHASE, $first->order->fresh()->status);
        $this->assertTrue($supplier->purchaseOrders->sole()->is($purchaseOrder));
        $this->assertTrue($product->purchaseOrderItems->sole()->is($item));
    }

    public function test_different_suppliers_create_separate_purchase_orders_and_lines_keep_correct_supplier(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['pedidos.preparar_compra']);
        $shorts = $this->product($company, ['name' => 'Pantaloneta']);
        $shirts = $this->product($company, ['name' => 'Camisa']);
        $mayca = $this->supplier($company, ['name' => 'MAYCA']);
        $sirie = $this->supplier($company, ['name' => 'SIRIE']);
        $this->associate($shorts, $mayca);
        $this->associate($shirts, $sirie);
        $first = $this->approvedLine($company, $branch, $user, $shorts, $mayca, 10);
        $second = $this->approvedLine($company, $branch, $user, $shirts, $sirie, 5);

        $orders = $this->prepare($user, $company, $branch, [[$first, 10], [$second, 5]]);

        $this->assertCount(2, $orders);
        $this->assertEqualsCanonicalizing([$mayca->id, $sirie->id], $orders->pluck('supplier_id')->all());
        $this->assertSame(1, $orders->each->load('items')->firstWhere('supplier_id', $mayca->id)->items->count());
        $this->assertSame($shorts->id, $orders->firstWhere('supplier_id', $mayca->id)->items->sole()->product_id);
        $this->assertSame($shirts->id, $orders->firstWhere('supplier_id', $sirie->id)->items->sole()->product_id);
    }

    public function test_partial_allocations_preserve_remaining_quantity_and_cannot_exceed_or_duplicate(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['pedidos.preparar_compra']);
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier);
        $line = $this->approvedLine($company, $branch, $user, $product, $supplier, 10);

        $first = $this->prepare($user, $company, $branch, [[$line, 6]])->sole();
        $this->assertSame('6.0000', $first->items->sole()->ordered_quantity);
        $this->assertSame(Order::STATUS_APPROVED, $line->order->fresh()->status);
        $this->assertServiceError(fn () => $this->prepare($user, $company, $branch, [[$line, 5]]), 'lines');
        $second = $this->prepare($user, $company, $branch, [[$line, 4]])->sole();
        $this->assertSame('4.0000', $second->items->sole()->ordered_quantity);
        $this->assertSame(10.0, (float) $line->purchaseOrderSources()->sum('allocated_quantity'));
        $this->assertSame(Order::STATUS_IN_PURCHASE, $line->order->fresh()->status);
        $this->assertServiceError(fn () => $this->prepare($user, $company, $branch, [[$line, 1]]), 'lines');
        $this->assertDatabaseCount('purchase_order_item_sources', 2);
    }

    public function test_rejected_or_supplierless_lines_are_never_preparable(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['pedidos.preparar_compra']);
        $product = $this->product($company);
        $rejectedOrder = $this->rawOrder($company, $branch, $user, $product, 2);
        $rejected = $rejectedOrder->items->sole();
        app(OrderService::class)->reviewItem($rejectedOrder, $rejected, ['approved_quantity' => 0], $user, $company->id, $branch->id);
        $this->assertServiceError(fn () => $this->prepare($user, $company, $branch, [[$rejected->fresh(), 1]]), 'lines');

        $supplierlessOrder = $this->rawOrder($company, $branch, $user, $product, 2);
        $supplierless = $supplierlessOrder->items->sole();
        $supplierless->update(['approved_quantity' => 2, 'item_status' => OrderItem::STATUS_APPROVED]);
        $supplierlessOrder->update(['status' => Order::STATUS_APPROVED]);
        $this->assertServiceError(fn () => $this->prepare($user, $company, $branch, [[$supplierless, 1]]), 'lines');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_company_and_branch_isolation_are_enforced(): void
    {
        [$company, $branch] = $this->context('Primera');
        [$otherCompany, $otherBranch] = $this->context('Otra empresa');
        $sameCompanyBranch = Branch::create(['company_id' => $company->id, 'name' => 'Secundaria', 'code' => 'S'.uniqid(), 'is_active' => true]);
        $user = $this->user($company, $branch, ['pedidos.preparar_compra']);
        $user->branches()->attach($sameCompanyBranch->id);
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier);
        $foreignBranchLine = $this->approvedLine($company, $sameCompanyBranch, $user, $product, $supplier, 2);

        $this->assertServiceError(fn () => $this->prepare($user, $company, $branch, [[$foreignBranchLine, 1]]), 'lines');
        $otherUser = $this->user($otherCompany, $otherBranch, ['pedidos.preparar_compra']);
        $otherProduct = $this->product($otherCompany);
        $otherSupplier = $this->supplier($otherCompany);
        $this->associate($otherProduct, $otherSupplier);
        $foreignCompanyLine = $this->approvedLine($otherCompany, $otherBranch, $otherUser, $otherProduct, $otherSupplier, 2);
        $this->assertServiceError(fn () => $this->prepare($user, $company, $branch, [[$foreignCompanyLine, 1]]), 'lines');
        $this->assertNotSame($branch->id, $otherBranch->id);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_backend_ignores_no_cost_and_rejects_frontend_cost_manipulation(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['pedidos.preparar_compra']);
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => 321.9876]);
        $line = $this->approvedLine($company, $branch, $user, $product, $supplier, 2);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('pedidos.preparar-compra.store'), [
            'lines' => [['order_item_id' => $line->id, 'allocated_quantity' => 2]],
            'unit_cost_snapshot' => 0.01,
        ])->assertSessionHasErrors('unit_cost_snapshot');
        $this->assertDatabaseCount('purchase_orders', 0);

        $order = $this->prepare($user, $company, $branch, [[$line, 2]])->sole();
        $this->assertSame('321.9876', $order->items->sole()->unit_cost_snapshot);
    }

    public function test_permissions_listing_detail_filters_states_and_cost_visibility(): void
    {
        [$company, $branch] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.preparar_compra', 'compras.ordenes'], 'Administrador');
        $cashier = $this->user($company, $branch, ['pedidos.ver']);
        $preparer = $this->user($company, $branch, ['pedidos.preparar_compra']);
        $product = $this->product($company, ['name' => 'Producto visible']);
        $supplier = $this->supplier($company, ['name' => 'Proveedor visible']);
        $this->associate($product, $supplier, ['current_cost' => 4567.8912]);
        $line = $this->approvedLine($company, $branch, $administrator, $product, $supplier, 3);
        $order = $this->prepare($administrator, $company, $branch, [[$line, 3]])->sole();

        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))->get(route('ordenes-compra.index', ['status' => 'prepared', 'search' => $order->number]))->assertOk()->assertSee($order->number)->assertSee('Proveedor visible')->assertSee('Total estimado');
        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))->get(route('ordenes-compra.show', $order))->assertOk()->assertSee('4.567,8912')->assertSee($line->order->number);
        $this->actingAs($cashier)->withSession($this->activeSession($company, $branch))->get(route('ordenes-compra.index'))->assertForbidden();
        $this->actingAs($cashier)->withSession($this->activeSession($company, $branch))->get(route('pedidos.preparar-compra'))->assertForbidden();

        $newLine = $this->approvedLine($company, $branch, $preparer, $product, $supplier, 1);
        $this->actingAs($preparer)->withSession($this->activeSession($company, $branch))->get(route('pedidos.preparar-compra'))->assertOk()->assertSee('Producto visible')->assertDontSee('Costo actual')->assertDontSee('4.567,8912');
        $this->assertEqualsCanonicalizing(['draft', 'prepared', 'sent', 'received', 'cancelled'], [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_PREPARED, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED]);
        $this->assertNotNull($newLine);
    }

    public function test_permission_seeder_assigns_preparation_permission_to_administrator(): void
    {
        [$company] = $this->context();
        $administrator = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $this->seed(PermissionSeeder::class);
        $this->assertTrue($administrator->permissions()->where('name', 'pedidos.preparar_compra')->exists());
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        return [$company, $branch];
    }

    private function user(Company $company, Branch $branch, array $permissions, ?string $roleName = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => $roleName ?? 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) { $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Pedidos', 'is_active' => true]); $role->permissions()->attach($permission); }
        $user->companies()->attach($company->id, ['role_id' => $role->id]); $user->branches()->attach($branch->id);
        return $user;
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $id = uniqid(); $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]); $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);
        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true], $attributes));
    }

    private function supplier(Company $company, array $attributes = []): Supplier { return Supplier::create(array_merge(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor '.uniqid(), 'is_active' => true], $attributes)); }
    private function associate(Product $product, Supplier $supplier, array $attributes = []): ProductSupplier { return ProductSupplier::create(array_merge(['company_id' => $product->company_id, 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'is_active' => true], $attributes)); }
    private function rawOrder(Company $company, Branch $branch, User $user, Product $product, float $quantity): Order { return app(OrderService::class)->create(['items' => [['product_id' => $product->id, 'requested_quantity' => $quantity]]], $user, $company->id, $branch->id); }
    private function approvedLine(Company $company, Branch $branch, User $user, Product $product, Supplier $supplier, float $quantity): OrderItem { $order = $this->rawOrder($company, $branch, $user, $product, $quantity); app(OrderService::class)->reviewItem($order, $order->items->sole(), ['approved_quantity' => $quantity, 'supplier_id' => $supplier->id], $user, $company->id, $branch->id); return $order->items->sole()->fresh(); }
    private function prepare(User $user, Company $company, Branch $branch, array $lines) { return app(PurchaseOrderPreparationService::class)->prepare(['lines' => collect($lines)->map(fn ($line) => ['order_item_id' => $line[0]->id, 'allocated_quantity' => $line[1]])->all()], $user, $company->id, $branch->id); }
    private function assertServiceError(callable $callback, string $key): void { try { $callback(); $this->fail('Se esperaba un error de validación.'); } catch (ValidationException $exception) { $this->assertArrayHasKey($key, $exception->errors()); } }
    private function activeSession(Company $company, Branch $branch): array { return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id]; }
}
