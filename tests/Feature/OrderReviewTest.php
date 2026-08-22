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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_and_reject_permissions_are_enforced_by_decision(): void
    {
        [$company, $branch, $requester] = $this->context();
        $product = $this->product($company);
        $approver = $this->user($company, $branch, ['pedidos.aprobar']);
        $rejecter = $this->user($company, $branch, ['pedidos.rechazar']);
        $without = $this->user($company, $branch, []);

        $approveOrder = $this->order($company, $branch, $requester, [[$product, 3]]);
        $this->review($without, $company, $branch, $approveOrder, $approveOrder->items->sole(), 3)->assertForbidden();
        $this->review($approver, $company, $branch, $approveOrder, $approveOrder->items->sole(), 3)->assertRedirect();
        $this->assertSame(OrderItem::STATUS_APPROVED, $approveOrder->items->sole()->fresh()->item_status);

        $rejectOrder = $this->order($company, $branch, $requester, [[$product, 2]]);
        $this->review($without, $company, $branch, $rejectOrder, $rejectOrder->items->sole(), 0)->assertForbidden();
        $this->review($rejecter, $company, $branch, $rejectOrder, $rejectOrder->items->sole(), 0, 'No requerido')->assertRedirect();
        $this->assertSame(OrderItem::STATUS_REJECTED, $rejectOrder->items->sole()->fresh()->item_status);
        $this->assertSame('No requerido', $rejectOrder->items->sole()->fresh()->review_note);
    }

    public function test_full_partial_and_zero_quantities_derive_line_states_and_audit(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar', 'pedidos.rechazar']);
        $product = $this->product($company);

        foreach ([[10, OrderItem::STATUS_APPROVED], [6, OrderItem::STATUS_PARTIAL], [0, OrderItem::STATUS_REJECTED]] as [$quantity, $status]) {
            $order = $this->order($company, $branch, $requester, [[$product, 10]]);
            $this->review($reviewer, $company, $branch, $order, $order->items->sole(), $quantity)->assertRedirect();
            $fresh = $order->fresh();
            $this->assertSame($status, $fresh->items->sole()->item_status);
            $this->assertSame(number_format($quantity, 4, '.', ''), $fresh->items->sole()->approved_quantity);
            $this->assertNotNull($fresh->reviewed_at);
            $this->assertSame($reviewer->id, $fresh->reviewed_by);
        }
    }

    public function test_header_stays_pending_until_all_lines_and_then_is_derived_consistently(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar', 'pedidos.rechazar']);
        $first = $this->product($company);
        $second = $this->product($company);

        $approved = $this->order($company, $branch, $requester, [[$first, 2], [$second, 4]]);
        $this->review($reviewer, $company, $branch, $approved, $approved->items[0], 2);
        $this->assertSame(Order::STATUS_PENDING, $approved->fresh()->status);
        $this->review($reviewer, $company, $branch, $approved, $approved->items[1], 4);
        $this->assertSame(Order::STATUS_APPROVED, $approved->fresh()->status);

        $partial = $this->order($company, $branch, $requester, [[$first, 2], [$second, 4]]);
        $this->review($reviewer, $company, $branch, $partial, $partial->items[0], 1);
        $this->review($reviewer, $company, $branch, $partial, $partial->items[1], 0);
        $this->assertSame(Order::STATUS_PARTIAL, $partial->fresh()->status);

        $rejected = $this->order($company, $branch, $requester, [[$first, 2], [$second, 4]]);
        $this->review($reviewer, $company, $branch, $rejected, $rejected->items[0], 0);
        $this->review($reviewer, $company, $branch, $rejected, $rejected->items[1], 0);
        $this->assertSame(Order::STATUS_REJECTED, $rejected->fresh()->status);
    }

    public function test_approved_quantity_can_be_less_than_equal_to_or_greater_than_requested_quantity(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar']);
        $product = $this->product($company);

        foreach ([6, 10, 30] as $approvedQuantity) {
            $order = $this->order($company, $branch, $requester, [[$product, 10]]);
            $this->review($reviewer, $company, $branch, $order, $order->items->sole(), $approvedQuantity)->assertRedirect();
            $this->assertSame(number_format($approvedQuantity, 4, '.', ''), $order->items->sole()->fresh()->approved_quantity);
        }
    }

    public function test_zero_approved_quantity_rejects_line_and_clears_supplier(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.rechazar']);
        $order = $this->order($company, $branch, $requester, [[$this->product($company), 10]]);

        $this->review($reviewer, $company, $branch, $order, $order->items->sole(), 0)->assertRedirect();

        $item = $order->items->sole()->fresh();
        $this->assertSame('0.0000', $item->approved_quantity);
        $this->assertNull($item->supplier_id);
        $this->assertSame(OrderItem::STATUS_REJECTED, $item->item_status);
    }

    public function test_decimal_approved_quantity_is_allowed_by_snapshot(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar']);
        $product = $this->product($company);
        $product->unit()->update(['allows_decimals' => true]);
        $order = $this->order($company, $branch, $requester, [[$product, 2.5]]);

        $this->review($reviewer, $company, $branch, $order, $order->items->sole(), 3.75)->assertRedirect();
        $this->assertSame('3.7500', $order->items->sole()->fresh()->approved_quantity);
    }

    public function test_decimal_approved_quantity_is_rejected_by_integer_snapshot(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar']);
        $order = $this->order($company, $branch, $requester, [[$this->product($company), 2]]);

        $this->review($reviewer, $company, $branch, $order, $order->items->sole(), 1.5)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approved_quantity');
    }

    public function test_negative_approved_quantity_is_rejected(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar']);
        $order = $this->order($company, $branch, $requester, [[$this->product($company), 2]]);

        $this->review($reviewer, $company, $branch, $order, $order->items->sole(), -1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approved_quantity');
    }

    public function test_supplier_is_required_for_positive_approved_quantity(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar']);
        $order = $this->order($company, $branch, $requester, [[$this->product($company), 2]]);

        $this->actingAs($reviewer)
            ->withSession($this->activeSession($company, $branch))
            ->patchJson(route('pedidos.items.review', [$order, $order->items->sole()]), ['approved_quantity' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_invalid_quantities_and_repeated_decisions_are_rejected(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar', 'pedidos.rechazar']);
        $product = $this->product($company);
        $order = $this->order($company, $branch, $requester, [[$product, 3]]);

        $this->review($reviewer, $company, $branch, $order, $order->items->sole(), -1)->assertUnprocessable();
        $this->assertServiceValidation($order, $order->items->sole(), -1, $reviewer, $company, $branch, 'approved_quantity');
        $this->review($reviewer, $company, $branch, $order, $order->items->sole(), 3)->assertRedirect();
        $this->assertServiceValidation($order->fresh(), $order->items->sole()->fresh(), 2, $reviewer, $company, $branch, 'order');
    }

    public function test_review_preserves_request_snapshots_and_has_no_operational_or_financial_effects(): void
    {
        [$company, $branch, $requester] = $this->context();
        $reviewer = $this->user($company, $branch, ['pedidos.aprobar']);
        $product = $this->product($company, ['sale_price' => 2500, 'cost' => 900]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 8, 'created_at' => now(), 'updated_at' => now()]);
        $order = $this->order($company, $branch, $requester, [[$product, 5]]);
        $before = $order->items->sole()->only(['requested_quantity', 'stock_snapshot', 'sale_price_snapshot', 'cost_snapshot', 'last_cost_snapshot']);

        $this->review($reviewer, $company, $branch, $order, $order->items->sole(), 3)->assertRedirect();

        $this->assertSame($before, $order->items->sole()->fresh()->only(array_keys($before)));
        $this->assertSame(8.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_payable', 0);
    }

    public function test_detail_is_read_only_without_review_permissions(): void
    {
        [$company, $branch, $requester] = $this->context();
        $viewer = $this->user($company, $branch, ['pedidos.ver']);
        $administrator = $this->user($company, $branch, ['pedidos.ver', 'pedidos.aprobar'], 'Administrador');
        $order = $this->order($company, $branch, $requester, [[$this->product($company), 2]]);

        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))->get(route('pedidos.show', $order))->assertOk()->assertSee('Solo lectura')->assertDontSee('Guardar aprobación');
        $this->actingAs($administrator)->withSession($this->activeSession($company, $branch))->get(route('pedidos.show', $order))->assertOk()->assertSee('Guardar aprobación')->assertSee('Cantidad aprobada')->assertDontSee('max=&quot;2.0000&quot;', false)->assertDontSee('Solo lectura');
    }

    private function review(User $user, Company $company, Branch $branch, Order $order, OrderItem $item, float $quantity, ?string $note = null, ?Supplier $supplier = null)
    {
        if ($quantity > 0) {
            $supplier ??= $this->supplier($company);
            ProductSupplier::firstOrCreate(
                ['product_id' => $item->product_id, 'supplier_id' => $supplier->id],
                ['company_id' => $company->id, 'is_active' => true]
            );
        }

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->patchJson(route('pedidos.items.review', [$order, $item]), ['approved_quantity' => $quantity, 'supplier_id' => $supplier?->id, 'review_note' => $note]);
    }

    private function order(Company $company, Branch $branch, User $user, array $lines): Order
    {
        return app(OrderService::class)->create(['items' => collect($lines)->map(fn ($line) => ['product_id' => $line[0]->id, 'requested_quantity' => $line[1]])->all()], $user, $company->id, $branch->id);
    }

    private function assertServiceValidation(Order $order, OrderItem $item, float $quantity, User $user, Company $company, Branch $branch, string $key): void
    {
        $supplier = null;
        if ($quantity > 0) {
            $supplier = $this->supplier($company);
            ProductSupplier::create(['company_id' => $company->id, 'product_id' => $item->product_id, 'supplier_id' => $supplier->id, 'is_active' => true]);
        }
        try { app(OrderService::class)->reviewItem($order, $item, ['approved_quantity' => $quantity, 'supplier_id' => $supplier?->id], $user, $company->id, $branch->id); $this->fail('Se esperaba error de validación.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey($key, $exception->errors()); }
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        return [$company, $branch, $this->user($company, $branch, [])];
    }

    private function user(Company $company, Branch $branch, array $permissions, ?string $roleName = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => $roleName ?? 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) { $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Pedidos', 'is_active' => true]); $role->permissions()->attach($permission->id); }
        $user->companies()->attach($company->id, ['role_id' => $role->id]); $user->branches()->attach($branch->id);
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

    private function activeSession(Company $company, Branch $branch): array { return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id]; }
}
