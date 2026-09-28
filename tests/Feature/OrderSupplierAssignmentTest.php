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
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSupplierAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_select_and_save_only_valid_product_supplier(): void
    {
        [$company, $branch, $requester] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.ver', 'pedidos.aprobar'], 'Administrador');
        $product = $this->product($company);
        $valid = $this->supplier($company, ['name' => 'MAYCA']);
        $unassociated = $this->supplier($company, ['name' => 'No asociado']);
        $this->associate($product, $valid, ['current_cost' => 9876.5432]);
        $order = $this->order($company, $branch, $requester, [[$product, 10]]);
        $item = $order->items->sole();

        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))
            ->get(route('pedidos.show', $order))->assertOk()
            ->assertSee('name="supplier_id"', false)
            ->assertSee('MAYCA')
            ->assertDontSee('No asociado')
            ->assertDontSee('9876.5432');

        $this->review($administrator, $company, $branch, $order, $item, 8, $valid)->assertRedirect();
        $item->refresh();
        $this->assertSame($valid->id, $item->supplier_id);
        $this->assertSame('8.0000', $item->approved_quantity);
        $this->assertSame(OrderItem::STATUS_PARTIAL, $item->item_status);
        $this->assertTrue($item->supplier->is($valid));
    }

    public function test_foreign_company_supplier_is_rejected_and_order_context_remains_isolated(): void
    {
        [$company, $branch, $requester] = $this->context('Empresa A');
        [$otherCompany, $otherBranch, $otherRequester] = $this->context('Empresa B');
        $administrator = $this->user($company, $branch, ['pedidos.ver', 'pedidos.aprobar']);
        $product = $this->product($company);
        $foreignSupplier = $this->supplier($otherCompany);
        $order = $this->order($company, $branch, $requester, [[$product, 2]]);

        $this->review($administrator, $company, $branch, $order, $order->items->sole(), 2, $foreignSupplier)
            ->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'El proveedor seleccionado no está activo y asociado al producto.');

        $foreignOrder = $this->order($otherCompany, $otherBranch, $otherRequester, [[$this->product($otherCompany), 1]]);
        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))
            ->patchJson(route('pedidos.items.review', [$foreignOrder, $foreignOrder->items->sole()]), ['approved_quantity' => 0])
            ->assertNotFound();
        $this->assertNull($order->items->sole()->fresh()->supplier_id);
    }

    public function test_inactive_or_unassociated_supplier_is_rejected(): void
    {
        [$company, $branch, $requester] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.aprobar']);
        $product = $this->product($company);
        $inactiveSupplier = $this->supplier($company);
        $this->associate($product, $inactiveSupplier);
        $inactiveSupplier->update(['is_active' => false]);
        $unassociated = $this->supplier($company);

        $inactiveOrder = $this->order($company, $branch, $requester, [[$product, 3]]);
        $this->review($administrator, $company, $branch, $inactiveOrder, $inactiveOrder->items->sole(), 3, $inactiveSupplier)
            ->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'El proveedor seleccionado no está activo y asociado al producto.');

        $unassociatedOrder = $this->order($company, $branch, $requester, [[$product, 3]]);
        $this->review($administrator, $company, $branch, $unassociatedOrder, $unassociatedOrder->items->sole(), 3, $unassociated)
            ->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'El proveedor seleccionado no está activo y asociado al producto.');
    }

    public function test_inactive_product_supplier_relation_is_rejected(): void
    {
        [$company, $branch, $requester] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.aprobar']);
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['is_active' => false]);
        $order = $this->order($company, $branch, $requester, [[$product, 2]]);

        $this->review($administrator, $company, $branch, $order, $order->items->sole(), 1, $supplier)
            ->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'El proveedor seleccionado no está activo y asociado al producto.');
    }

    public function test_positive_quantity_requires_supplier_and_zero_quantity_forbids_it(): void
    {
        [$company, $branch, $requester] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.aprobar', 'pedidos.rechazar']);
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier);

        $positive = $this->order($company, $branch, $requester, [[$product, 2]]);
        $this->review($administrator, $company, $branch, $positive, $positive->items->sole(), 1)
            ->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'Debe seleccionar un proveedor para una cantidad aprobada.');

        $zero = $this->order($company, $branch, $requester, [[$product, 2]]);
        $this->review($administrator, $company, $branch, $zero, $zero->items->sole(), 0, $supplier)
            ->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'Una línea rechazada no puede tener proveedor.');
    }

    public function test_rejected_line_clears_existing_supplier_assignment(): void
    {
        [$company, $branch, $requester] = $this->context();
        $rejecter = $this->user($company, $branch, ['pedidos.rechazar']);
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier);
        $order = $this->order($company, $branch, $requester, [[$product, 2]]);
        $item = $order->items->sole();
        $item->update(['supplier_id' => $supplier->id]);

        $this->review($rejecter, $company, $branch, $order, $item, 0)->assertRedirect();
        $item->refresh();
        $this->assertNull($item->supplier_id);
        $this->assertSame('0.0000', $item->approved_quantity);
        $this->assertSame(OrderItem::STATUS_REJECTED, $item->item_status);
    }

    public function test_two_lines_keep_different_supplier_assignments(): void
    {
        [$company, $branch, $requester] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.aprobar']);
        $shorts = $this->product($company, ['name' => 'Pantalonetas']);
        $shirts = $this->product($company, ['name' => 'Camisas']);
        $mayca = $this->supplier($company, ['name' => 'MAYCA']);
        $sirie = $this->supplier($company, ['name' => 'SIRIE']);
        $this->associate($shorts, $mayca);
        $this->associate($shirts, $sirie);
        $order = $this->order($company, $branch, $requester, [[$shorts, 10], [$shirts, 5]]);

        $this->review($administrator, $company, $branch, $order, $order->items[0], 10, $mayca)->assertRedirect();
        $this->review($administrator, $company, $branch, $order, $order->items[1], 5, $sirie)->assertRedirect();

        $items = $order->fresh()->items->keyBy('product_id');
        $this->assertSame($mayca->id, $items[$shorts->id]->supplier_id);
        $this->assertSame($sirie->id, $items[$shirts->id]->supplier_id);
        $this->assertSame(Order::STATUS_APPROVED, $order->fresh()->status);
    }

    public function test_product_without_supplier_shows_clear_state_and_authorized_user_can_associate_select_and_approve(): void
    {
        [$company, $branch, $requester] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.ver', 'pedidos.aprobar', 'productos.editar', 'compras.ordenes']);
        $product = $this->product($company);
        $supplier = $this->supplier($company, ['name' => 'Proveedor nuevo']);
        $order = $this->order($company, $branch, $requester, [[$product, 3]]);
        $item = $order->items->sole();

        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))
            ->get(route('pedidos.show', $order))->assertOk()
            ->assertSee('Sin proveedor asociado')
            ->assertSee('+ Asociar proveedor')
            ->assertSee('name="current_cost"', false);

        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pedidos.items.suppliers.store', [$order, $item]), [
                'supplier_id' => $supplier->id,
                'supplier_product_code' => 'NUEVO-9',
                'current_cost' => '125.4321',
                'is_active' => true,
                'notes' => 'Asociado desde pedido',
            ])->assertCreated()
            ->assertJsonPath('supplier.id', $supplier->id)
            ->assertJsonPath('supplier.name', 'Proveedor nuevo');

        $relation = ProductSupplier::query()->where('product_id', $product->id)->where('supplier_id', $supplier->id)->sole();
        $this->assertTrue($relation->is_primary);
        $this->assertTrue($relation->is_active);
        $this->assertSame('NUEVO-9', $relation->supplier_product_code);
        $this->assertSame('125.4321', $relation->current_cost);
        $this->assertSame('Asociado desde pedido', $relation->notes);

        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))
            ->get(route('pedidos.show', $order))->assertOk()
            ->assertSee('Proveedor nuevo')
            ->assertSee('value="'.$supplier->id.'"', false);

        $this->review($administrator, $company, $branch, $order, $item, 3, $supplier)->assertRedirect();
        $this->assertSame($supplier->id, $item->fresh()->supplier_id);
    }

    public function test_associating_second_supplier_does_not_replace_existing_primary(): void
    {
        [$company, $branch, $requester] = $this->context();
        $administrator = $this->user($company, $branch, ['pedidos.ver', 'pedidos.aprobar', 'productos.editar']);
        $product = $this->product($company);
        $primary = $this->associate($product, $this->supplier($company), ['is_primary' => true]);
        $secondSupplier = $this->supplier($company);
        $order = $this->order($company, $branch, $requester, [[$product, 1]]);

        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pedidos.items.suppliers.store', [$order, $order->items->sole()]), [
                'supplier_id' => $secondSupplier->id,
                'is_active' => true,
            ])->assertCreated();

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertFalse(ProductSupplier::query()->where('supplier_id', $secondSupplier->id)->sole()->is_primary);
        $this->assertSame(1, $product->productSuppliers()->where('is_primary', true)->where('is_active', true)->count());
    }

    public function test_user_without_product_edit_permission_cannot_associate_from_order(): void
    {
        [$company, $branch, $requester] = $this->context();
        $product = $this->product($company);
        $order = $this->order($company, $branch, $requester, [[$product, 1]]);
        $item = $order->items->sole();
        $supplier = $this->supplier($company);
        $viewer = $this->user($company, $branch, ['pedidos.ver', 'pedidos.aprobar']);

        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))
            ->get(route('pedidos.show', $order))->assertOk()
            ->assertSee('Sin proveedor asociado')
            ->assertDontSee('+ Asociar proveedor');
        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pedidos.items.suppliers.store', [$order, $item]), ['supplier_id' => $supplier->id, 'is_active' => true])
            ->assertForbidden();
        $this->assertDatabaseCount('product_suppliers', 0);
    }

    public function test_inactive_supplier_is_rejected_when_associating_from_order(): void
    {
        [$company, $branch, $requester] = $this->context();
        $product = $this->product($company);
        $order = $this->order($company, $branch, $requester, [[$product, 1]]);
        $inactive = $this->supplier($company, ['is_active' => false]);
        $editor = $this->user($company, $branch, ['productos.editar']);

        $this->actingAs($editor)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pedidos.items.suppliers.store', [$order, $order->items->sole()]), ['supplier_id' => $inactive->id, 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
        $this->assertDatabaseCount('product_suppliers', 0);
    }

    public function test_foreign_supplier_is_rejected_when_associating_from_order(): void
    {
        [$company, $branch, $requester] = $this->context('Empresa A');
        [$otherCompany] = $this->context('Empresa B');
        $product = $this->product($company);
        $order = $this->order($company, $branch, $requester, [[$product, 1]]);
        $foreign = $this->supplier($otherCompany);
        $editor = $this->user($company, $branch, ['productos.editar']);

        $this->actingAs($editor)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pedidos.items.suppliers.store', [$order, $order->items->sole()]), ['supplier_id' => $foreign->id, 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
        $this->assertDatabaseCount('product_suppliers', 0);
    }

    public function test_cost_and_foreign_order_are_protected_when_associating_from_order(): void
    {
        [$company, $branch, $requester] = $this->context('Empresa A');
        $product = $this->product($company);
        $order = $this->order($company, $branch, $requester, [[$product, 1]]);
        $supplier = $this->supplier($company);
        $editor = $this->user($company, $branch, ['productos.editar']);

        $this->actingAs($editor)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pedidos.items.suppliers.store', [$order, $order->items->sole()]), [
                'supplier_id' => $supplier->id,
                'current_cost' => 1,
                'is_active' => true,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_cost');
        $this->assertDatabaseCount('product_suppliers', 0);
    }

    public function test_foreign_order_product_cannot_be_manipulated_from_active_company(): void
    {
        [$company, $branch] = $this->context('Empresa A');
        [$otherCompany, $otherBranch, $otherRequester] = $this->context('Empresa B');
        $supplier = $this->supplier($company);
        $editor = $this->user($company, $branch, ['productos.editar']);
        $foreignProduct = $this->product($otherCompany);
        $foreignOrder = $this->order($otherCompany, $otherBranch, $otherRequester, [[$foreignProduct, 1]]);
        $this->actingAs($editor)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pedidos.items.suppliers.store', [$foreignOrder, $foreignOrder->items->sole()]), ['supplier_id' => $supplier->id, 'is_active' => true])
            ->assertNotFound();
    }

    public function test_cashier_order_view_never_exposes_costs_or_supplier_financial_data(): void
    {
        [$company, $branch, $requester] = $this->context();
        $cashier = $this->user($company, $branch, ['pedidos.ver']);
        $product = $this->product($company, ['cost' => 6543.21]);
        $supplier = $this->supplier($company, ['credit_limit' => 765432.10]);
        $this->associate($product, $supplier, ['current_cost' => 9876.5432]);
        $order = $this->order($company, $branch, $requester, [[$product, 1]]);

        $this->actingAs($cashier)->withSession($this->activeSession($company, $branch))
            ->get(route('pedidos.show', $order))->assertOk()
            ->assertSee('Solo lectura')
            ->assertDontSee('Costo actual')
            ->assertDontSee('9876.5432')
            ->assertDontSee('6543.2100')
            ->assertDontSee('765432.10');
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        return [$company, $branch, $this->user($company, $branch, [])];
    }

    private function user(Company $company, Branch $branch, array $permissions, ?string $roleName = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => $roleName ?? 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Pedidos', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true], $attributes));
    }

    private function supplier(Company $company, array $attributes = []): Supplier
    {
        return Supplier::create(array_merge(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor '.uniqid(), 'is_active' => true], $attributes));
    }

    private function associate(Product $product, Supplier $supplier, array $attributes = []): ProductSupplier
    {
        return ProductSupplier::create(array_merge(['company_id' => $product->company_id, 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'is_active' => true], $attributes));
    }

    private function order(Company $company, Branch $branch, User $requester, array $lines): Order
    {
        return app(OrderService::class)->create(['items' => collect($lines)->map(fn ($line) => ['product_id' => $line[0]->id, 'requested_quantity' => $line[1]])->all()], $requester, $company->id, $branch->id);
    }

    private function review(User $user, Company $company, Branch $branch, Order $order, OrderItem $item, float $quantity, ?Supplier $supplier = null)
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->patchJson(route('pedidos.items.review', [$order, $item]), ['approved_quantity' => $quantity, 'supplier_id' => $supplier?->id]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
