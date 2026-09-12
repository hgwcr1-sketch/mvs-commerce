<?php

namespace Tests\Feature;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Purchases\PurchaseProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasePostingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_post_increases_stock(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $initialStock = 10.0;
        $this->branchProduct($branch, $product, $initialStock);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 'cash', 5);
        $item = $purchase->items->sole();

        $stock = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock');

        $this->assertSame($initialStock + 5.0, $stock);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_type' => PurchaseItem::class,
            'reference_id' => $item->id,
            'type' => 'purchase',
            'quantity' => '5.0000',
        ]);
    }

    public function test_second_post_same_item_does_not_change_stock(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $initialStock = 10.0;
        $this->branchProduct($branch, $product, $initialStock);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 'cash', 5);
        $item = $purchase->items->sole();

        $stockAfterFirst = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock');

        $service = app(InventoryPostingService::class);
        $second = $service->postPurchase($purchase, $item, $product, new PurchaseLineData(
            product_id: $product->id,
            quantity: 5,
            unit_cost: 500,
        ));

        $stockAfterSecond = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock');

        $this->assertSame($stockAfterFirst, $stockAfterSecond);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_type' => PurchaseItem::class,
            'reference_id' => $item->id,
            'type' => 'purchase',
            'quantity' => '5.0000',
        ]);
    }

    public function test_second_post_same_item_does_not_create_another_movement(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $this->branchProduct($branch, $product, 10.0);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 'cash', 3);
        $item = $purchase->items->sole();

        $countAfterFirst = InventoryMovement::where('reference_type', PurchaseItem::class)
            ->where('reference_id', $item->id)
            ->count();

        $service = app(InventoryPostingService::class);
        $service->postPurchase($purchase, $item, $product, new PurchaseLineData(
            product_id: $product->id,
            quantity: 3,
            unit_cost: 500,
        ));

        $countAfterSecond = InventoryMovement::where('reference_type', PurchaseItem::class)
            ->where('reference_id', $item->id)
            ->count();

        $this->assertSame(1, $countAfterFirst);
        $this->assertSame(1, $countAfterSecond);
    }

    public function test_second_post_same_item_does_not_create_another_lot(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $this->branchProduct($branch, $product, 0.0);

        $purchase = $this->processWithLot($company, $branch, $user, $supplier, $product, 'cash', 4, 'LOT-001');
        $item = $purchase->items->sole();

        $lotCountAfterFirst = InventoryLot::where('purchase_item_id', $item->id)->count();

        $service = app(InventoryPostingService::class);
        $service->postPurchase($purchase, $item, $product, new PurchaseLineData(
            product_id: $product->id,
            quantity: 4,
            unit_cost: 500,
            lot_number: 'LOT-001',
        ));

        $lotCountAfterSecond = InventoryLot::where('purchase_item_id', $item->id)->count();

        $this->assertSame(1, $lotCountAfterFirst);
        $this->assertSame(1, $lotCountAfterSecond);
    }

    public function test_two_different_purchase_items_post_independently(): void
    {
        [$company, $branch, $user, $supplier] = $this->context();
        $productA = $this->product($company, 'A');
        $productB = $this->product($company, 'B');
        $this->branchProduct($branch, $productA, 0.0);
        $this->branchProduct($branch, $productB, 0.0);

        $purchase = app(PurchaseProcessor::class)->process(new PurchaseData(
            company_id: $company->id,
            branch_id: $branch->id,
            supplier_id: $supplier->id,
            user_id: $user->id,
            purchase_date: today()->toDateString(),
            payment_type: 'cash',
            lines: [
                new PurchaseLineData(product_id: $productA->id, quantity: 3, unit_cost: 100, tax_rate: 0),
                new PurchaseLineData(product_id: $productB->id, quantity: 7, unit_cost: 120, tax_rate: 0),
            ],
        ));

        $this->assertSame(2, $purchase->items->count());

        $stockA = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $productA->id)
            ->value('stock');
        $stockB = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $productB->id)
            ->value('stock');

        $this->assertSame(3.0, $stockA);
        $this->assertSame(7.0, $stockB);

        foreach ($purchase->items as $item) {
            $this->assertDatabaseHas('inventory_movements', [
                'reference_type' => PurchaseItem::class,
                'reference_id' => $item->id,
                'type' => 'purchase',
            ]);
        }
    }

    public function test_multiple_products_work_independently(): void
    {
        [$company, $branch, $user, $supplier] = $this->context();

        $productA = $this->product($company, 'A');
        $productB = $this->product($company, 'B');
        $this->branchProduct($branch, $productA, 0.0);
        $this->branchProduct($branch, $productB, 0.0);

        $purchase = app(PurchaseProcessor::class)->process(new PurchaseData(
            company_id: $company->id,
            branch_id: $branch->id,
            supplier_id: $supplier->id,
            user_id: $user->id,
            purchase_date: today()->toDateString(),
            payment_type: 'cash',
            lines: [
                new PurchaseLineData(product_id: $productA->id, quantity: 4, unit_cost: 200, tax_rate: 0),
                new PurchaseLineData(product_id: $productB->id, quantity: 2, unit_cost: 300, tax_rate: 0),
            ],
        ));

        $stockA = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $productA->id)
            ->value('stock');
        $stockB = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $productB->id)
            ->value('stock');

        $this->assertSame(4.0, $stockA);
        $this->assertSame(2.0, $stockB);
    }

    public function test_other_company_intact(): void
    {
        [$companyA, $branchA, $userA, $supplierA, $productA] = $this->context('EmpresaA');
        [$companyB, $branchB, $userB, $supplierB, $productB] = $this->context('EmpresaB');
        $this->branchProduct($branchA, $productA, 10.0);
        $this->branchProduct($branchB, $productB, 20.0);

        $this->process($companyA, $branchA, $userA, $supplierA, $productA, 'cash', 5);

        $stockA = (float) DB::table('branch_product')
            ->where('branch_id', $branchA->id)
            ->where('product_id', $productA->id)
            ->value('stock');
        $stockB = (float) DB::table('branch_product')
            ->where('branch_id', $branchB->id)
            ->where('product_id', $productB->id)
            ->value('stock');

        $this->assertSame(15.0, $stockA);
        $this->assertSame(20.0, $stockB);
    }

    public function test_stock_integrity_after_rollback(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $this->branchProduct($branch, $product, 10.0);

        try {
            DB::transaction(function () use ($company, $branch, $user, $supplier, $product) {
                $purchase = $this->process($company, $branch, $user, $supplier, $product, 'cash', 5);

                $stockInside = (float) DB::table('branch_product')
                    ->where('branch_id', $branch->id)
                    ->where('product_id', $product->id)
                    ->value('stock');
                $this->assertSame(15.0, $stockInside);

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $stockAfter = (float) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock');

        $this->assertSame(10.0, $stockAfter);
        $this->assertSame(0, InventoryMovement::where('type', 'purchase')->count());
    }

    public function test_idempotency_returns_same_movement_instance(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $this->branchProduct($branch, $product, 0.0);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 'cash', 3);
        $item = $purchase->items->sole();

        $service = app(InventoryPostingService::class);
        $first = $service->postPurchase($purchase, $item, $product, new PurchaseLineData(
            product_id: $product->id,
            quantity: 3,
            unit_cost: 500,
        ));
        $second = $service->postPurchase($purchase, $item, $product, new PurchaseLineData(
            product_id: $product->id,
            quantity: 3,
            unit_cost: 500,
        ));

        $this->assertTrue($first->is($second));
        $this->assertSame($first->id, $second->id);
    }

    public function test_unique_constraint_prevents_duplicate_lot_per_purchase_item(): void
    {
        [$company, $branch, $user, $supplier] = $this->context();
        $product = $this->product($company);
        $this->branchProduct($branch, $product, 0.0);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 'cash', 2);
        $item = $purchase->items->sole();

        InventoryLot::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'purchase_item_id' => $item->id,
            'lot_number' => 'LOT-A',
            'initial_quantity' => 2,
            'current_quantity' => 2,
        ]);

        $duplicated = false;
        try {
            InventoryLot::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'purchase_item_id' => $item->id,
                'lot_number' => 'LOT-B',
                'initial_quantity' => 3,
                'current_quantity' => 3,
            ]);
        } catch (\Exception $e) {
            $duplicated = true;
        }

        $this->assertTrue($duplicated, 'Expected unique constraint violation on duplicate purchase_item_id');
        $this->assertSame(1, InventoryLot::where('purchase_item_id', $item->id)->count());
    }

    public function test_different_purchase_items_allow_separate_lots(): void
    {
        [$company, $branch, $user, $supplier] = $this->context();
        $product = $this->product($company);
        $this->branchProduct($branch, $product, 0.0);

        $purchase = $this->process($company, $branch, $user, $supplier, $product, 'cash', 2);
        $item = $purchase->items->sole();

        $purchase2 = $this->process($company, $branch, $user, $supplier, $product, 'cash', 3);
        $item2 = $purchase2->items->sole();

        InventoryLot::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'purchase_item_id' => $item->id,
            'lot_number' => 'LOT-A',
            'initial_quantity' => 2,
            'current_quantity' => 2,
        ]);

        InventoryLot::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'purchase_item_id' => $item2->id,
            'lot_number' => 'LOT-B',
            'initial_quantity' => 3,
            'current_quantity' => 3,
        ]);

        $this->assertSame(2, InventoryLot::count());
    }

    public function test_null_purchase_item_id_still_allowed(): void
    {
        [$company, $branch] = $this->context();
        $product = $this->product($company);

        InventoryLot::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'purchase_item_id' => null,
            'lot_number' => 'LOT-NULL-1',
            'initial_quantity' => 1,
            'current_quantity' => 1,
        ]);

        InventoryLot::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'purchase_item_id' => null,
            'lot_number' => 'LOT-NULL-2',
            'initial_quantity' => 2,
            'current_quantity' => 2,
        ]);

        $this->assertSame(2, InventoryLot::whereNull('purchase_item_id')->count());
    }

    private function process(
        Company $company,
        Branch $branch,
        User $user,
        Supplier $supplier,
        Product $product,
        string $paymentType,
        float $quantity,
    ): Purchase {
        return app(PurchaseProcessor::class)->process(new PurchaseData(
            company_id: $company->id,
            branch_id: $branch->id,
            supplier_id: $supplier->id,
            user_id: $user->id,
            purchase_date: today()->toDateString(),
            payment_type: $paymentType,
            due_date: $paymentType === 'credit' ? today()->addDays(30)->toDateString() : null,
            lines: [new PurchaseLineData(product_id: $product->id, quantity: $quantity, unit_cost: 500, tax_rate: 0)],
        ));
    }

    private function processWithLot(
        Company $company,
        Branch $branch,
        User $user,
        Supplier $supplier,
        Product $product,
        string $paymentType,
        float $quantity,
        string $lotNumber,
    ): Purchase {
        return app(PurchaseProcessor::class)->process(new PurchaseData(
            company_id: $company->id,
            branch_id: $branch->id,
            supplier_id: $supplier->id,
            user_id: $user->id,
            purchase_date: today()->toDateString(),
            payment_type: $paymentType,
            lines: [new PurchaseLineData(product_id: $product->id, quantity: $quantity, unit_cost: 500, tax_rate: 0, lot_number: $lotNumber)],
        ));
    }

    private function branchProduct(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'compras.crear'], ['label' => 'Crear compras', 'module' => 'Compras', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $supplier = Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor '.uniqid(), 'is_active' => true]);
        $product = $this->product($company);

        return [$company, $branch, $user, $supplier, $product];
    }

    private function product(Company $company, string $suffix = ''): Product
    {
        $id = uniqid().$suffix;
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);
        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Prod '.$id,
            'internal_code' => 'P-'.$id,
            'cost' => 100,
            'sale_price' => 200,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);
    }
}
