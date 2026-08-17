<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $suffix = ''): Company
    {
        return Company::create([
            'trade_name' => 'Empresa '.$suffix.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 4)).'-'.$company->id.'-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function userWithPermission(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol Devoluciones '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                [
                    'label' => $name,
                    'module' => 'Ventas',
                    'is_active' => true,
                ],
            );

            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user->companies()->attach(
            $company->id,
            ['role_id' => $role->id],
        );

        $user->branches()->attach($branch->id);

        return $user;
    }

    private function product(Company $company, bool $trackInventory = true, int $stock = 10): Product
    {
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.uniqid(),
            'slug' => 'categoria-'.uniqid(),
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'u-'.uniqid(),
            'allows_decimals' => true,
            'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.uniqid(),
            'internal_code' => 'P-'.uniqid(),
            'cost' => 500,
            'sale_price' => 1000,
            'stock' => $stock,
            'tax_rate' => 13,
            'track_inventory' => $trackInventory,
            'is_active' => true,
        ]);
    }

    private function seedStock(Branch $branch, Product $product, int $qty): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
/**
     * @param  array<int, int>  $quantities  Producto => cantidad vendida
     */
    private function completedSale(
        Company $company,
        Branch $branch,
        User $user,
        array $quantities,
        string $status = Sale::STATUS_COMPLETED,
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('sale', true)),
            'sale_number' => 'POS-RET-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => $status,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 130,
            'rounding_total' => 0,
            'total' => 1130,
            'paid_total' => 1130,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        foreach ($quantities as $productId => $qty) {
            $sale->items()->create([
                'product_id' => $productId,
                'product_code' => 'P-CODE',
                'barcode' => null,
                'cabys_code' => null,
                'description' => 'Producto test',
                'unit_code' => 'U',
                'quantity' => $qty,
                'unit_price' => 1000,
                'gross_total' => 1000 * $qty,
                'discount_total' => 0,
                'subtotal' => 1000 * $qty,
                'tax_rate' => 13,
                'tax_total' => 130 * $qty,
                'total' => 1130 * $qty,
                'unit_cost' => 600,
            ]);
        }

        if ($status === Sale::STATUS_COMPLETED) {
            $sale->payments()->create([
                'payment_method_id' => $this->paymentMethodId($company),
                'affects_cash_snapshot' => true,
                'created_by' => $user->id,
                'amount' => 1130 * array_sum($quantities),
                'received_amount' => 1130 * array_sum($quantities),
                'change_amount' => 0,
                'cash_effect_amount' => 1130 * array_sum($quantities),
                'reference' => null,
                'status' => SalePayment::STATUS_COMPLETED,
            ]);
        }

        return $sale;
    }

    private function paymentMethodId(Company $company): int
    {
        return \App\Models\PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'EFECTIVO-'.$company->id,
            'name' => 'Efectivo',
            'type' => 'cash',
            'allows_change' => true,
            'affects_cash' => true,
            'is_active' => true,
        ])->id;
    }

    public function test_sale_from_other_company_cannot_be_returned(): void
    {
        $companyA = $this->company('A');
        $branchA = $this->branch($companyA, 'Sucursal A');

        $companyB = $this->company('B');
        $branchB = $this->branch($companyB, 'Sucursal B');

        $seller = $this->userWithPermission($companyA, $branchA, []);
        $productA = $this->product($companyA);
        $sale = $this->completedSale($companyA, $branchA, $seller, [$productA->id => 2]);

        $returner = $this->userWithPermission($companyB, $branchB, ['devoluciones.crear']);

        $this->postReturn($returner, $companyB, $branchB, $sale, [
            'reason' => 'Intento de otra empresa',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_sale_from_other_branch_cannot_be_returned(): void
    {
        $company = $this->company();
        $branchSale = $this->branch($company, 'Sucursal venta');
        $branchActive = $this->branch($company, 'Sucursal activa');

        $seller = $this->userWithPermission($company, $branchSale, []);
        $productForSale = $this->product($company);
        $sale = $this->completedSale($company, $branchSale, $seller, [$productForSale->id => 2]);

        $returner = $this->userWithPermission($company, $branchActive, ['devoluciones.crear']);

        $this->postReturn($returner, $company, $branchActive, $sale, [
            'reason' => 'Intento de otra sucursal',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        [$company, $branch] = $this->contextWithoutSale();

        $user = $this->userWithPermission($company, $branch, []);
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolver todo',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_voided_sale_returns_validation_error(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3], Sale::STATUS_VOIDED);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Intento sobre anulada',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('sale');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame(Sale::STATUS_VOIDED, $sale->fresh()->status);
    }

    public function test_returned_sale_cannot_be_returned_again(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3], Sale::STATUS_RETURNED);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Nueva devolución',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('sale');

        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_partial_return_marks_sale_as_partially_returned(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 5]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución parcial',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Sale::STATUS_PARTIALLY_RETURNED, $sale->fresh()->status);
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_full_return_marks_sale_as_returned(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 5);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución total',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);
    }

    public function test_cannot_return_more_than_sold(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);
        $product = $this->product($company);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Exceso de devolución',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 5]],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
    }

    private function contextWithoutSale(): array
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');

        return [$company, $branch];
    }
private function discountedSale(
        Company $company,
        Branch $branch,
        User $user,
        int $productId,
        int $qty,
        array $figures,
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('sale', true)),
            'sale_number' => 'POS-RETD-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => $figures['subtotal'],
            'discount_total' => $figures['discount'],
            'tax_total' => $figures['tax'],
            'rounding_total' => 0,
            'total' => $figures['total'],
            'paid_total' => $figures['total'],
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        $sale->items()->create([
            'product_id' => $productId,
            'product_code' => 'P-CODE',
            'barcode' => null,
            'cabys_code' => null,
            'description' => 'Producto con descuento',
            'unit_code' => 'U',
            'quantity' => $qty,
            'unit_price' => $figures['unit_price'],
            'gross_total' => $figures['gross'],
            'discount_total' => $figures['discount'],
            'subtotal' => $figures['subtotal'],
            'tax_rate' => $figures['tax_rate'],
            'tax_total' => $figures['tax'],
            'total' => $figures['total'],
            'unit_cost' => 600,
        ]);

        return $sale;
    }

    private function postReturn(User $user, Company $company, Branch $branch, Sale $sale, array $payload)
    {
        return $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->post(route('ventas.return.store', $sale), $payload);
    }

public function test_cannot_return_more_than_pending_after_previous_return(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company);
        $this->seedStock($branch, $product, 5);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 5]);
        $itemId = $sale->items->first()->id;

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Primera devolución de 3',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Sale::STATUS_PARTIALLY_RETURNED, $sale->fresh()->status);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Intento de devolver 3 más',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 3]],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame(Sale::STATUS_PARTIALLY_RETURNED, $sale->fresh()->status);
    }

    public function test_track_inventory_restores_stock_exactly_in_original_branch(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $otherBranch = $this->branch($company, 'Otra');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 5);
        $this->seedStock($branch, $product, 5);
        $otherBranch->products()->attach($product->id, [
            'stock' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución de mercancía',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $stock = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock');

        $otherStock = DB::table('branch_product')
            ->where('branch_id', $otherBranch->id)
            ->where('product_id', $product->id)
            ->value('stock');

        $this->assertEquals(8, $stock);
        $this->assertEquals(999, $otherStock);
    }

    public function test_untracked_product_creates_no_inventory_movement(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, false);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Sin seguimiento',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);
        $this->assertSame(0, InventoryMovement::query()
            ->where('product_id', $product->id)
            ->count());
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_movement_is_referenced_to_the_specific_sale_return(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 5);
        $this->seedStock($branch, $product, 5);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 4]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Trazabilidad',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $saleReturn = SaleReturn::firstOrFail();
        $movement = InventoryMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertSame('sale_return', $movement->type);
        $this->assertSame(SaleReturn::class, $movement->reference_type);
        $this->assertSame((int) $saleReturn->id, (int) $movement->reference_id);
        $this->assertSame((int) $user->id, (int) $movement->user_id);
        $this->assertEquals(2.0, (float) $movement->quantity);
        $this->assertStringContainsString((string) $sale->sale_number, (string) $movement->notes);
    }

    public function test_multiple_partial_returns_keep_independent_traceability(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 5);
        $this->seedStock($branch, $product, 5);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 5]);
        $itemId = $sale->items->first()->id;

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Primera parcial',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Segunda parcial',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $returns = SaleReturn::orderBy('id')->get();
        $this->assertCount(2, $returns);
        $this->assertNotSame($returns[0]->return_number, $returns[1]->return_number);
        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);

        $movements = InventoryMovement::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame((int) $returns[0]->id, (int) $movements[0]->reference_id);
        $this->assertSame((int) $returns[1]->id, (int) $movements[1]->reference_id);
    }

    public function test_return_is_audited(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 5);
        $this->seedStock($branch, $product, 5);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3]);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Motivo de auditoría',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $saleReturn = SaleReturn::firstOrFail();
        $this->assertSame((int) $user->id, (int) $saleReturn->user_id);
        $this->assertSame('Motivo de auditoría', $saleReturn->reason);
        $this->assertNotNull($saleReturn->returned_at);
        $this->assertSame((int) $sale->id, (int) $saleReturn->sale_id);
    }

    public function test_transaction_rolls_back_entirely_if_a_line_is_invalid(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $productA = $this->product($company, true, 5);
        $this->seedStock($branch, $productA, 5);
        $sale = $this->completedSale($company, $branch, $user, [$productA->id => 3]);

        $invalidSaleItemId = 99999999;

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Línea inválida',
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'quantity' => 1],
                ['sale_item_id' => $invalidSaleItemId, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);

        $stock = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $productA->id)
            ->value('stock');

        $this->assertEquals(5, $stock);
    }

    public function test_original_sale_prices_totals_and_payments_are_preserved(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 5);
        $this->seedStock($branch, $product, 5);
        $sale = $this->completedSale($company, $branch, $user, [$product->id => 3]);

        $originalTotal = $sale->total;
        $originalPaid = $sale->paid_total;
        $originalItemPrice = $sale->items->first()->unit_price;
        $originalPaymentStatus = $sale->payments->first()->status;

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devuelve uno',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $sale->refresh();

        $this->assertSame($originalTotal, $sale->total);
        $this->assertSame($originalPaid, $sale->paid_total);
        $this->assertSame($originalItemPrice, $sale->items->first()->unit_price);
        $this->assertSame($originalPaymentStatus, $sale->payments->first()->status);
        $this->assertSame(Sale::STATUS_PARTIALLY_RETURNED, $sale->status);
    }

    public function test_partial_return_proportions_financial_snapshots_of_discounted_line(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 5);
        $this->seedStock($branch, $product, 5);

        $figures = [
            'unit_price' => 1000,
            'gross' => 5000,
            'discount' => 620,
            'subtotal' => 4380,
            'tax_rate' => 13,
            'tax' => 569.40,
            'total' => 4949.40,
        ];

        $sale = $this->discountedSale($company, $branch, $user, $product->id, 5, $figures);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución parcial con descuento',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $returnItem = SaleReturnItem::firstOrFail();

        $this->assertEquals(2000.0, (float) $returnItem->gross_total);
        $this->assertEquals(248.0, (float) $returnItem->discount_total);
        $this->assertEquals(1752.0, (float) $returnItem->subtotal);
        $this->assertEquals(227.76, (float) $returnItem->tax_total);
        $this->assertEquals(1979.76, (float) $returnItem->total);
        $this->assertEquals(1000, (float) $returnItem->unit_price);
        $this->assertEquals(13, (float) $returnItem->tax_rate);
        $this->assertEquals(2, (float) $returnItem->quantity);
    }

    public function test_partial_return_does_not_reconstruct_gross_from_unit_price(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 2);
        $this->seedStock($branch, $product, 2);

        // Descuento general ya distribuido sobre la línea.
        $figures = [
            'unit_price' => 1000,
            'gross' => 2000,
            'discount' => 400,
            'subtotal' => 1600,
            'tax_rate' => 13,
            'tax' => 208.0,
            'total' => 1808.0,
        ];

        $sale = $this->discountedSale($company, $branch, $user, $product->id, 2, $figures);

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Devolución resto',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $returnItem = SaleReturnItem::firstOrFail();

        // Si reconstruyera el bruto usaría unit_price*quantity = 1000 -> igual a subtotal, lo cual es incorrecto.
        $this->assertNotEquals(1 * 1000, (float) $returnItem->subtotal);
        $this->assertEquals(800.0, (float) $returnItem->subtotal);
        $this->assertEquals(1000.0, (float) $returnItem->gross_total);
        $this->assertEquals(200.0, (float) $returnItem->discount_total);
        $this->assertEquals(104.0, (float) $returnItem->tax_total);
        $this->assertEquals(904.0, (float) $returnItem->total);
    }

    public function test_full_return_accumulates_exact_original_financials_across_partials(): void
    {
        $company = $this->company();
        $branch = $this->branch($company, 'Principal');
        $user = $this->userWithPermission($company, $branch, ['devoluciones.crear']);

        $product = $this->product($company, true, 10);
        $this->seedStock($branch, $product, 10);

        $figures = [
            'unit_price' => 1000,
            'gross' => 5000,
            'discount' => 620,
            'subtotal' => 4380,
            'tax_rate' => 13,
            'tax' => 569.40,
            'total' => 4949.40,
        ];

        $sale = $this->discountedSale($company, $branch, $user, $product->id, 5, $figures);
        $itemId = $sale->items->first()->id;

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Primera parcial',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->postReturn($user, $company, $branch, $sale, [
            'reason' => 'Segunda parcial completa',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $original = $sale->items->first();

        $sumGross = (float) SaleReturnItem::where('sale_item_id', $itemId)->sum('gross_total');
        $sumDiscount = (float) SaleReturnItem::where('sale_item_id', $itemId)->sum('discount_total');
        $sumSubtotal = (float) SaleReturnItem::where('sale_item_id', $itemId)->sum('subtotal');
        $sumTax = (float) SaleReturnItem::where('sale_item_id', $itemId)->sum('tax_total');
        $sumTotal = (float) SaleReturnItem::where('sale_item_id', $itemId)->sum('total');

        $this->assertSame(2, SaleReturnItem::where('sale_item_id', $itemId)->count());
        $this->assertEqualsWithDelta((float) $original->gross_total, $sumGross, 0.0001);
        $this->assertEqualsWithDelta((float) $original->discount_total, $sumDiscount, 0.0001);
        $this->assertEqualsWithDelta((float) $original->subtotal, $sumSubtotal, 0.0001);
        $this->assertEqualsWithDelta((float) $original->tax_total, $sumTax, 0.0001);
        $this->assertEqualsWithDelta((float) $original->total, $sumTotal, 0.0001);

        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);
    }
}