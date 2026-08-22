<?php

namespace Tests\Feature;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\AccountPayable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplier;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PurchaseOrderConversionService;
use App\Services\Orders\PurchaseOrderPreparationService;
use App\Services\Purchases\PurchaseProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_conversion_uses_purchase_processor_for_purchase_items_inventory_and_cost(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, ['cost' => 100]);
        $supplier = $this->supplier($company);
        $relation = $this->associate($product, $supplier, ['current_cost' => 275.4321]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $purchaseOrder = $this->preparedOrder($company, $branch, $user, $product, $supplier, 6);

        $purchase = $this->convert($purchaseOrder, $user, $company, $branch, 6, 'cash');

        $this->assertInstanceOf(Purchase::class, $purchase);
        $this->assertSame($company->id, $purchase->company_id);
        $this->assertSame($branch->id, $purchase->branch_id);
        $this->assertSame($supplier->id, $purchase->supplier_id);
        $this->assertSame('cash', $purchase->payment_type);
        $this->assertSame('6.0000', $purchase->items->sole()->quantity);
        $this->assertSame('275.4321', $purchase->items->sole()->unit_cost);
        $this->assertSame('275.43', $product->fresh()->cost);
        $this->assertSame(8.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => Purchase::class, 'reference_id' => $purchase->id, 'type' => 'purchase', 'quantity' => 6]);
        $this->assertNull($purchase->accountPayable);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $purchaseOrder->fresh()->status);
        $this->assertSame('275.4321', $relation->fresh()->current_cost);
    }

    public function test_credit_conversion_creates_single_account_payable_through_existing_flow(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => 500]);
        $purchaseOrder = $this->preparedOrder($company, $branch, $user, $product, $supplier, 2);

        $purchase = $this->convert($purchaseOrder, $user, $company, $branch, 2, 'credit', today()->addDays(30)->toDateString());

        $account = $purchase->accountPayable;
        $this->assertInstanceOf(AccountPayable::class, $account);
        $this->assertSame($purchase->id, $account->purchase_id);
        $this->assertSame($supplier->id, $account->supplier_id);
        $this->assertEquals((float) $purchase->total, (float) $account->original_amount);
        $this->assertDatabaseCount('accounts_payable', 1);
    }

    public function test_partial_conversions_keep_balance_and_quantity_trace_to_internal_order(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => 100]);
        $purchaseOrder = $this->preparedOrder($company, $branch, $user, $product, $supplier, 10);
        $purchaseOrderItem = $purchaseOrder->items->sole();
        $internalOrder = $purchaseOrderItem->sources->sole()->orderItem->order;

        $first = $this->convert($purchaseOrder, $user, $company, $branch, 6, 'cash');
        $purchaseOrderItem->load('sources.conversions');
        $this->assertSame(6.0, $purchaseOrderItem->converted_quantity);
        $this->assertSame(4.0, $purchaseOrderItem->pending_quantity);
        $this->assertSame(PurchaseOrder::STATUS_PREPARED, $purchaseOrder->fresh()->status);
        $this->assertSame($internalOrder->number, $first->items->sole()->purchaseOrderSourceConversions->sole()->source->orderItem->order->number);

        $this->assertServiceError(fn () => $this->convert($purchaseOrder, $user, $company, $branch, 5, 'cash'), 'lines');
        $second = $this->convert($purchaseOrder, $user, $company, $branch, 4, 'cash');
        $purchaseOrderItem->load('sources.conversions');
        $this->assertSame(10.0, $purchaseOrderItem->converted_quantity);
        $this->assertSame(0.0, $purchaseOrderItem->pending_quantity);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $purchaseOrder->fresh()->status);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('purchase_order_source_conversions', 2);
        $this->assertServiceError(fn () => $this->convert($purchaseOrder, $user, $company, $branch, 1, 'cash'), 'purchase_order');
    }

    public function test_multiple_internal_sources_are_distributed_without_losing_traceability(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => 50]);
        $firstLine = $this->approvedLine($company, $branch, $user, $product, $supplier, 10);
        $secondLine = $this->approvedLine($company, $branch, $user, $product, $supplier, 5);
        $purchaseOrder = app(PurchaseOrderPreparationService::class)->prepare(['lines' => [['order_item_id' => $firstLine->id, 'allocated_quantity' => 10], ['order_item_id' => $secondLine->id, 'allocated_quantity' => 5]]], $user, $company->id, $branch->id)->sole();

        $purchase = $this->convert($purchaseOrder, $user, $company, $branch, 12, 'cash');
        $conversions = $purchase->items->sole()->purchaseOrderSourceConversions;
        $this->assertEqualsCanonicalizing([10.0, 2.0], $conversions->pluck('converted_quantity')->map(fn ($value) => (float) $value)->all());
        $this->assertEqualsCanonicalizing([$firstLine->order->number, $secondLine->order->number], $conversions->map(fn ($conversion) => $conversion->source->orderItem->order->number)->all());
        $this->assertSame(3.0, $purchaseOrder->items->sole()->fresh()->load('sources.conversions')->pending_quantity);
    }

    public function test_cancelled_foreign_or_costless_purchase_order_cannot_convert(): void
    {
        [$company, $branch, $user] = $this->context();
        [$otherCompany, $otherBranch] = $this->companyBranch('Otra');
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $relation = $this->associate($product, $supplier, ['current_cost' => 100]);
        $cancelled = $this->preparedOrder($company, $branch, $user, $product, $supplier, 2);
        $cancelled->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
        $this->assertServiceError(fn () => $this->convert($cancelled, $user, $company, $branch, 1, 'cash'), 'purchase_order');

        $foreign = $this->preparedOrder($company, $branch, $user, $product, $supplier, 2);
        $this->assertServiceError(fn () => app(PurchaseOrderConversionService::class)->convert($foreign, $this->payload($foreign, 1, 'cash'), $user, $otherCompany->id, $otherBranch->id), 'permission');

        $relation->update(['current_cost' => null]);
        $this->assertServiceError(fn () => $this->convert($foreign, $user, $company, $branch, 1, 'cash'), 'lines');
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_permissions_and_frontend_cost_manipulation_are_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $withoutCreate = $this->user($company, $branch, ['compras.ordenes']);
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => 700]);
        $purchaseOrder = $this->preparedOrder($company, $branch, $user, $product, $supplier, 2);

        $this->actingAs($withoutCreate)->withSession($this->activeSession($company, $branch))->get(route('ordenes-compra.convertir', $purchaseOrder))->assertForbidden();
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('ordenes-compra.convertir.store', $purchaseOrder), $this->payload($purchaseOrder, 2, 'cash') + ['unit_cost' => 0.01])->assertSessionHasErrors('unit_cost');
        $this->assertDatabaseCount('purchases', 0);

        $purchase = $this->convert($purchaseOrder, $user, $company, $branch, 2, 'cash');
        $this->assertSame('700.0000', $purchase->items->sole()->unit_cost);
    }

    public function test_credit_without_due_date_returns_to_form_and_displays_error(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => 500]);
        $purchaseOrder = $this->preparedOrder($company, $branch, $user, $product, $supplier, 2);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->followingRedirects()
            ->from(route('ordenes-compra.convertir', $purchaseOrder))
            ->post(route('ordenes-compra.convertir.store', $purchaseOrder), $this->payload($purchaseOrder, 2, 'credit'));

        $response->assertOk()
            ->assertViewIs('purchase-orders.convert')
            ->assertSee('No fue posible convertir el pedido:')
            ->assertSee('due date', false)
            ->assertSee('value="credit" selected', false);
    }

    public function test_business_error_returns_to_form_with_all_entered_values(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => null]);
        $purchaseOrder = $this->preparedOrder($company, $branch, $user, $product, $supplier, 5);
        $dueDate = today()->addDays(30)->toDateString();
        $payload = $this->payload($purchaseOrder, 3, 'credit', $dueDate) + [
            'supplier_invoice_number' => 'FAC-123',
            'notes' => 'Entrega parcial urgente',
        ];

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->followingRedirects()
            ->from(route('ordenes-compra.convertir', $purchaseOrder))
            ->post(route('ordenes-compra.convertir.store', $purchaseOrder), $payload);

        $response->assertOk()
            ->assertViewIs('purchase-orders.convert')
            ->assertSee('No existe un costo autorizado activo para el producto y proveedor.')
            ->assertSee('value="credit" selected', false)
            ->assertSee('value="'.$dueDate.'"', false)
            ->assertSee('value="3"', false)
            ->assertSee('value="FAC-123"', false)
            ->assertSee('Entrega parcial urgente');
    }

    public function test_conversion_form_has_back_link_to_current_purchase_order(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $this->associate($product, $supplier, ['current_cost' => 500]);
        $purchaseOrder = $this->preparedOrder($company, $branch, $user, $product, $supplier, 2);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('ordenes-compra.convertir', $purchaseOrder))
            ->assertOk()
            ->assertSee('← Volver')
            ->assertSee(route('ordenes-compra.show', $purchaseOrder), false);
    }

    public function test_existing_manual_purchase_processor_remains_compatible(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);

        $purchase = app(PurchaseProcessor::class)->process(new PurchaseData(
            company_id: $company->id, branch_id: $branch->id, supplier_id: $supplier->id, user_id: $user->id,
            purchase_date: today()->toDateString(), payment_type: 'cash',
            lines: [new PurchaseLineData(product_id: $product->id, quantity: 3, unit_cost: 225)]
        ));

        $this->assertSame('posted', $purchase->status);
        $this->assertSame('3.0000', $purchase->items->sole()->quantity);
        $this->assertSame('225.0000', $purchase->items->sole()->unit_cost);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $purchase->id, 'reference_type' => Purchase::class]);
    }

    private function context(): array { [$company, $branch] = $this->companyBranch(); return [$company, $branch, $this->user($company, $branch, ['pedidos.preparar_compra', 'compras.ordenes', 'compras.crear'])]; }
    private function companyBranch(string $name = 'Empresa'): array { $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]); $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]); return [$company, $branch]; }
    private function user(Company $company, Branch $branch, array $permissions): User { $user=User::factory()->create(['is_active'=>true]); $role=Role::create(['company_id'=>$company->id,'name'=>'Rol '.uniqid(),'is_active'=>true]); foreach($permissions as $name){$permission=Permission::firstOrCreate(['name'=>$name],['label'=>$name,'module'=>'Compras','is_active'=>true]);$role->permissions()->attach($permission);} $user->companies()->attach($company->id,['role_id'=>$role->id]);$user->branches()->attach($branch->id);return$user; }
    private function product(Company $company, array $attributes=[]): Product { $id=uniqid();$category=ProductCategory::create(['company_id'=>$company->id,'name'=>'Categoría '.$id,'slug'=>'cat-'.$id,'is_active'=>true]);$unit=Unit::create(['company_id'=>$company->id,'name'=>'Unidad '.$id,'abbreviation'=>'U','slug'=>'u-'.$id,'allows_decimals'=>false,'is_active'=>true]);return Product::create(array_merge(['company_id'=>$company->id,'category_id'=>$category->id,'unit_id'=>$unit->id,'name'=>'Producto '.$id,'internal_code'=>'P-'.$id,'cost'=>100,'sale_price'=>200,'tax_rate'=>13,'is_active'=>true],$attributes)); }
    private function supplier(Company $company): Supplier { return Supplier::create(['company_id'=>$company->id,'supplier_type'=>'company','name'=>'Proveedor '.uniqid(),'is_active'=>true]); }
    private function associate(Product $product, Supplier $supplier, array $attributes=[]): ProductSupplier { return ProductSupplier::create(array_merge(['company_id'=>$product->company_id,'product_id'=>$product->id,'supplier_id'=>$supplier->id,'is_active'=>true],$attributes)); }
    private function approvedLine(Company $company, Branch $branch, User $user, Product $product, Supplier $supplier, float $quantity) { $order=app(OrderService::class)->create(['items'=>[['product_id'=>$product->id,'requested_quantity'=>$quantity]]],$user,$company->id,$branch->id);app(OrderService::class)->reviewItem($order,$order->items->sole(),['approved_quantity'=>$quantity,'supplier_id'=>$supplier->id],$user,$company->id,$branch->id);return$order->items->sole()->fresh(); }
    private function preparedOrder(Company $company, Branch $branch, User $user, Product $product, Supplier $supplier, float $quantity): PurchaseOrder { $line=$this->approvedLine($company,$branch,$user,$product,$supplier,$quantity);return app(PurchaseOrderPreparationService::class)->prepare(['lines'=>[['order_item_id'=>$line->id,'allocated_quantity'=>$quantity]]],$user,$company->id,$branch->id)->sole(); }
    private function payload(PurchaseOrder $order, float $quantity, string $paymentType, ?string $dueDate=null): array { return ['payment_type'=>$paymentType,'due_date'=>$dueDate,'lines'=>[['purchase_order_item_id'=>$order->items->sole()->id,'quantity'=>$quantity]]]; }
    private function convert(PurchaseOrder $order, User $user, Company $company, Branch $branch, float $quantity, string $paymentType, ?string $dueDate=null): Purchase { return app(PurchaseOrderConversionService::class)->convert($order,$this->payload($order,$quantity,$paymentType,$dueDate),$user,$company->id,$branch->id); }
    private function assertServiceError(callable $callback,string $key):void{try{$callback();$this->fail('Se esperaba error de validación.');}catch(ValidationException $exception){$this->assertArrayHasKey($key,$exception->errors());}}
    private function activeSession(Company $company,Branch $branch):array{return['active_company_id'=>$company->id,'active_branch_id'=>$branch->id];}
}
