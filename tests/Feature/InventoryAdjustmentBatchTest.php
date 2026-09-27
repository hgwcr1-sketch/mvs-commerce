<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryAdjustmentBatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $user;

    private Role $role;

    private Product $integerProduct;

    private Product $decimalProduct;

    private Product $lowStockProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'trade_name' => 'Ajustes Lote',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Principal',
            'code' => 'ADJB',
            'is_active' => true,
        ]);

        $this->role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Ajustador',
            'is_active' => true,
        ]);

        foreach (['inventario.ajustar', 'inventario.ver', 'productos.ver'] as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Inventario', 'is_active' => true],
            );
            $this->role->permissions()->attach($permission);
        }

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->companies()->attach($this->company->id, ['role_id' => $this->role->id]);
        $this->user->branches()->attach($this->branch);

        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'slug' => 'general-ajuste',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'company_id' => $this->company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'unidad-ajuste',
            'allows_decimals' => false,
            'is_active' => true,
        ]);

        $decimalUnit = Unit::create([
            'company_id' => $this->company->id,
            'name' => 'Kilo',
            'abbreviation' => 'kg',
            'slug' => 'kilo-ajuste',
            'allows_decimals' => true,
            'is_active' => true,
        ]);

        $this->integerProduct = $this->makeProduct($category, $unit, 'Arroz', 'SKU-ADJ-1', '10.0000');
        $this->decimalProduct = $this->makeProduct($category, $decimalUnit, 'Azúcar', 'SKU-ADJ-2', '5.5000');
        $this->lowStockProduct = $this->makeProduct($category, $unit, 'Sal', 'SKU-ADJ-3', '3.0000');

        $this->actingAs($this->user)->withSession([
            'active_company_id' => $this->company->id,
            'active_branch_id' => $this->branch->id,
        ]);
    }

    private function makeProduct(ProductCategory $category, Unit $unit, string $name, string $code, string $stock): Product
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => $name,
            'internal_code' => $code,
            'barcode' => null,
            'cost' => '10',
            'sale_price' => '20',
            'tax_rate' => '0',
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $this->branch->products()->attach($product, ['stock' => $stock]);

        return $product;
    }

    private function stock(Product $product): string
    {
        return bcadd(
            (string) DB::table('branch_product')
                ->where('branch_id', $this->branch->id)
                ->where('product_id', $product->id)
                ->value('stock'),
            '0',
            4,
        );
    }

    private function row(Product $product, string $type, string $quantity, string $reason, ?string $notes = null): array
    {
        return [
            'product_id' => $product->id,
            'adjustment_type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'notes' => $notes,
        ];
    }

    public function test_batch_adjustment_applies_every_row_and_preserves_context(): void
    {
        $response = $this->post(route('ajustes-inventario.store'), [
            'rows' => [
                $this->row($this->integerProduct, 'entry', '5', 'Conteo inicial'),
                $this->row($this->decimalProduct, 'exit', '1.5', 'Merma', 'Revisión de bodega'),
                $this->row($this->lowStockProduct, 'entry', '2', 'Reposición'),
            ],
        ]);

        $response->assertRedirect(route('inventario.index'));

        $this->assertSame('15.0000', $this->stock($this->integerProduct));
        $this->assertSame('4.0000', $this->stock($this->decimalProduct));
        $this->assertSame('5.0000', $this->stock($this->lowStockProduct));

        $this->assertSame(3, InventoryMovement::count());

        $movement = InventoryMovement::query()
            ->where('product_id', $this->decimalProduct->id)
            ->firstOrFail();

        $this->assertSame('exit', $movement->type);
        $this->assertSame('1.5000', $movement->quantity);
        $this->assertSame('5.5000', $movement->previous_stock);
        $this->assertSame('4.0000', $movement->new_stock);
        $this->assertSame('Merma', $movement->reason);
        $this->assertSame('Revisión de bodega', $movement->notes);
        $this->assertSame($this->company->id, $movement->company_id);
        $this->assertSame($this->branch->id, $movement->branch_id);
        $this->assertSame($this->user->id, $movement->user_id);
    }

    public function test_batch_is_atomic_when_one_row_fails(): void
    {
        $response = $this->post(route('ajustes-inventario.store'), [
            'rows' => [
                $this->row($this->integerProduct, 'entry', '5', 'Conteo inicial'),
                $this->row($this->lowStockProduct, 'exit', '999', 'Salida sin stock'),
                $this->row($this->decimalProduct, 'entry', '1', 'Reposición'),
            ],
        ]);

        $response->assertSessionHasErrors(['rows.1.quantity']);

        $this->assertSame('10.0000', $this->stock($this->integerProduct));
        $this->assertSame('3.0000', $this->stock($this->lowStockProduct));
        $this->assertSame('5.5000', $this->stock($this->decimalProduct));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_duplicate_products_are_rejected_without_changes(): void
    {
        $response = $this->post(route('ajustes-inventario.store'), [
            'rows' => [
                $this->row($this->integerProduct, 'entry', '5', 'Primera fila'),
                $this->row($this->integerProduct, 'exit', '2', 'Segunda fila'),
            ],
        ]);

        $response->assertSessionHasErrors(['rows.1.product_id']);

        $this->assertSame('10.0000', $this->stock($this->integerProduct));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_single_product_payload_still_works(): void
    {
        $response = $this->post(route('ajustes-inventario.store'), [
            'product_id' => $this->integerProduct->id,
            'adjustment_type' => 'exit',
            'quantity' => '4',
            'reason' => 'Corrección de conteo',
            'notes' => 'Ajuste individual',
        ]);

        $response->assertRedirect(route('inventario.index'));
        $response->assertSessionHas('success', 'Ajuste de inventario realizado correctamente.');

        $this->assertSame('6.0000', $this->stock($this->integerProduct));

        $movement = InventoryMovement::sole();
        $this->assertSame('exit', $movement->type);
        $this->assertSame('4.0000', $movement->quantity);
        $this->assertSame('10.0000', $movement->previous_stock);
        $this->assertSame('6.0000', $movement->new_stock);
        $this->assertSame('Corrección de conteo', $movement->reason);
        $this->assertSame('Ajuste individual', $movement->notes);
    }

    public function test_product_of_another_company_is_rejected(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Otra empresa',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $foreignProduct = Product::create([
            'company_id' => $otherCompany->id,
            'category_id' => ProductCategory::create([
                'company_id' => $otherCompany->id,
                'name' => 'Ajena',
                'slug' => 'ajena-ajuste',
                'is_active' => true,
            ])->id,
            'unit_id' => Unit::create([
                'company_id' => $otherCompany->id,
                'name' => 'Unidad ajena',
                'abbreviation' => 'Ua',
                'slug' => 'unidad-ajena-ajuste',
                'allows_decimals' => false,
                'is_active' => true,
            ])->id,
            'name' => 'Producto ajeno',
            'internal_code' => 'SKU-FOR-1',
            'cost' => '10',
            'sale_price' => '20',
            'tax_rate' => '0',
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $response = $this->post(route('ajustes-inventario.store'), [
            'rows' => [$this->row($foreignProduct, 'entry', '1', 'No permitido')],
        ]);

        $response->assertSessionHasErrors(['rows.0.product_id']);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_decimal_quantity_is_rejected_for_integer_units(): void
    {
        $response = $this->post(route('ajustes-inventario.store'), [
            'rows' => [$this->row($this->integerProduct, 'entry', '1.5', 'Fracción no permitida')],
        ]);

        $response->assertSessionHasErrors(['rows.0.quantity']);
        $this->assertSame('10.0000', $this->stock($this->integerProduct));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_quantity_with_more_than_four_decimals_is_rejected(): void
    {
        $response = $this->post(route('ajustes-inventario.store'), [
            'rows' => [$this->row($this->decimalProduct, 'entry', '1.12345', 'Precisión')],
        ]);

        $response->assertSessionHasErrors(['rows.0.quantity']);
        $this->assertSame('5.5000', $this->stock($this->decimalProduct));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_permission_is_required(): void
    {
        $this->role->permissions()->detach(
            Permission::where('name', 'inventario.ajustar')->value('id')
        );

        $this->actingAs($this->user->fresh())
            ->post(route('ajustes-inventario.store'), [
                'rows' => [$this->row($this->integerProduct, 'entry', '1', 'Sin permiso')],
            ])
            ->assertForbidden();

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_create_screen_renders_multiple_row_form(): void
    {
        $this->get(route('ajustes-inventario.create'))
            ->assertOk()
            ->assertSee('Agregar producto')
            ->assertSee('MVS_ADJUSTMENT_INITIAL', false)
            ->assertSee('product_id', false);
    }

    public function test_batch_requires_at_least_one_row(): void
    {
        $this->post(route('ajustes-inventario.store'), ['rows' => []])
            ->assertSessionHasErrors(['rows']);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_mixed_entry_and_exit_rows_are_allowed_in_the_same_batch(): void
    {
        $this->post(route('ajustes-inventario.store'), [
            'rows' => [
                $this->row($this->integerProduct, 'entry', '4', 'Entrada del lote'),
                $this->row($this->decimalProduct, 'exit', '2.5', 'Salida del lote'),
            ],
        ])->assertRedirect(route('inventario.index'));

        $this->assertSame('14.0000', $this->stock($this->integerProduct));
        $this->assertSame('3.0000', $this->stock($this->decimalProduct));

        $this->assertSame('entry', InventoryMovement::query()->where('product_id', $this->integerProduct->id)->sole()->type);
        $this->assertSame('exit', InventoryMovement::query()->where('product_id', $this->decimalProduct->id)->sole()->type);
    }

    public function test_zero_stock_exit_blocks_the_whole_mixed_batch(): void
    {
        DB::table('branch_product')
            ->where('branch_id', $this->branch->id)
            ->where('product_id', $this->lowStockProduct->id)
            ->update(['stock' => '0.0000']);

        $response = $this->post(route('ajustes-inventario.store'), [
            'rows' => [
                $this->row($this->integerProduct, 'entry', '5', 'Entrada que no debe aplicarse'),
                $this->row($this->decimalProduct, 'entry', '1', 'Otra entrada que no debe aplicarse'),
                $this->row($this->lowStockProduct, 'exit', '1', 'Salida sin stock'),
            ],
        ]);

        $response->assertSessionHasErrors(['rows.2.quantity']);

        $message = session('errors')->first('rows.2.quantity');
        $this->assertSame('Stock insuficiente para Sal. Disponible: 0.0000. Solicitado: 1.0000.', $message);

        $this->assertSame('10.0000', $this->stock($this->integerProduct));
        $this->assertSame('5.5000', $this->stock($this->decimalProduct));
        $this->assertSame('0.0000', $this->stock($this->lowStockProduct));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_negative_stock_is_never_created_by_an_exit(): void
    {
        $this->post(route('ajustes-inventario.store'), [
            'rows' => [$this->row($this->lowStockProduct, 'exit', '3', 'Salida total')],
        ])->assertRedirect(route('inventario.index'));

        $this->assertSame('0.0000', $this->stock($this->lowStockProduct));

        $this->post(route('ajustes-inventario.store'), [
            'rows' => [$this->row($this->lowStockProduct, 'exit', '0.0001', 'Salida residual')],
        ])->assertSessionHasErrors(['rows.0.quantity']);

        $this->assertSame('0.0000', $this->stock($this->lowStockProduct));
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_missing_active_branch_is_reported_instead_of_404(): void
    {
        $this->grantDashboardAdmin();

        $response = $this
            ->withSession([
                'active_company_id' => $this->company->id,
                'active_branch_id' => null,
            ])
            ->post(route('ajustes-inventario.store'), [
                'rows' => [$this->row($this->integerProduct, 'entry', '5', 'Sin sucursal')],
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['branch']);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_no_active_branch_shows_selector_without_clearing_the_form(): void
    {
        $this->grantDashboardAdmin();

        $response = $this
            ->withSession([
                'active_company_id' => $this->company->id,
                'active_branch_id' => null,
            ])
            ->get(route('ajustes-inventario.create'));

        $response->assertOk();
        $response->assertSee('No hay una sucursal activa.');
        $response->assertSee('Seleccione una sucursal para aplicar el ajuste.');
        $response->assertSee('ajuste-branch');
        $response->assertSee('mvs-ajuste-inventario-borrador');
        $this->assertNull(session('active_branch_id'));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_branch_change_keeps_rows_intact_and_revalidates_them(): void
    {
        $target = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Sucursal Norte',
            'code' => 'ADJN',
            'is_active' => true,
        ]);
        $this->user->branches()->attach($target);
        $target->products()->attach($this->integerProduct, ['stock' => '10.0000']);

        // El selector de sucursal NO navega ni envía el formulario de ajustes.
        $this->get(route('ajustes-inventario.create'))
            ->assertOk()
            ->assertSee('mvsOnBranchChange')
            ->assertSee('mvs-ajuste-inventario-borrador')
            ->assertSee('onsubmit="return false"', false);

        $this->post(route('branch.active.update'), ['branch_id' => $target->id])
            ->assertRedirect();
        $this->assertSame($target->id, (int) session('active_branch_id'));

        $response = $this->postJson(route('ajustes-inventario.revalidate'), [
            'rows' => [
                ['product_id' => $this->integerProduct->id, 'product_label' => 'SKU-ADJ-1 - Arroz', 'adjustment_type' => 'entry', 'quantity' => '5', 'reason' => 'Entrada inicial', 'notes' => 'Nota de la fila 1'],
                ['product_id' => $this->lowStockProduct->id, 'product_label' => 'SKU-ADJ-3 - Sal', 'adjustment_type' => 'exit', 'quantity' => '1', 'reason' => 'Merma', 'notes' => ''],
                ['product_id' => $this->decimalProduct->id, 'product_label' => 'SKU-ADJ-2 - Azúcar', 'adjustment_type' => 'entry', 'quantity' => '2', 'reason' => 'Reposición', 'notes' => null],
            ],
        ]);

        $response->assertOk();

        $rows = $response->json('rows');
        $errors = $response->json('errors');
        $warnings = $response->json('warnings');

        $this->assertCount(3, $rows);
        $this->assertSame(
            [$this->integerProduct->id, $this->lowStockProduct->id, $this->decimalProduct->id],
            array_column($rows, 'product_id'),
        );
        $this->assertSame(['entry', 'exit', 'entry'], array_column($rows, 'adjustment_type'));
        $this->assertSame(['5', '1', '2'], array_column($rows, 'quantity'));
        $this->assertSame(['Entrada inicial', 'Merma', 'Reposición'], array_column($rows, 'reason'));
        $this->assertSame(
            ['SKU-ADJ-1 - Arroz', 'SKU-ADJ-3 - Sal', 'SKU-ADJ-2 - Azúcar'],
            array_column($rows, 'product_label'),
        );
        $this->assertSame('Nota de la fila 1', $rows[0]['notes']);

        $this->assertArrayNotHasKey('rows.0.product_id', $errors);
        $this->assertArrayNotHasKey('rows.0.quantity', $errors);
        $this->assertArrayHasKey('rows.1.product_id', $errors);
        $this->assertStringContainsString('no está asignado a la sucursal', $errors['rows.1.product_id']);
        $this->assertArrayHasKey('rows.2.product_id', $warnings);
        $this->assertStringContainsString('no está asignado a la sucursal', $warnings['rows.2.product_id']);
    }

    private function grantDashboardAdmin(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'dashboard.admin'],
            ['label' => 'dashboard.admin', 'module' => 'Dashboard', 'is_active' => true],
        );

        $this->role->permissions()->attach($permission);
    }

    public function test_service_transaction_rolls_back_entries_when_a_later_exit_fails(): void
    {
        $posting = app(InventoryPostingService::class);

        $thrown = false;

        try {
            DB::transaction(function () use ($posting) {
                $posting->postAdjustment($this->branch, $this->integerProduct, $this->user->id, 'entry', '5', 'Entrada válida');
                $posting->postAdjustment($this->branch, $this->lowStockProduct, $this->user->id, 'exit', '999', 'Salida sin stock');
            });
        } catch (ValidationException $exception) {
            $thrown = true;
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertTrue($thrown, 'La salida sin stock debe abortar la transacción.');
        $this->assertSame('10.0000', $this->stock($this->integerProduct));
        $this->assertSame('3.0000', $this->stock($this->lowStockProduct));
        $this->assertSame(0, InventoryMovement::count());
    }
}
