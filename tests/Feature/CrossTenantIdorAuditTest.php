<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrossTenantIdorAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_company_still_reads_its_own_product(): void
    {
        [$company, $branch, , $user, $product] = $this->tenant('A');

        $this->actingAs($user)
            ->withSession($this->ctx($company, $branch))
            ->get(route('productos.edit', $product))
            ->assertOk();
    }

    public function test_product_edit_of_another_company_returns_404(): void
    {
        [$company, $branch, , $user] = $this->tenant('A');
        [, , , , $foreignProduct] = $this->tenant('B');

        $this->actingAs($user)
            ->withSession($this->ctx($company, $branch))
            ->get(route('productos.edit', $foreignProduct))
            ->assertNotFound();
    }

    public function test_product_update_of_another_company_returns_404_and_keeps_owner(): void
    {
        [$company, $branch, , $user] = $this->tenant('A');
        [$foreignCompany, , , , $foreignProduct] = $this->tenant('B');

        $category = ProductCategory::create([
            'company_id' => $company->id, 'name' => 'Cat '.Str::random(6),
            'slug' => 'cat-'.Str::lower(Str::random(8)), 'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id, 'name' => 'U '.Str::random(6),
            'abbreviation' => 'U', 'slug' => 'u-'.Str::lower(Str::random(8)), 'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->ctx($company, $branch))
            ->put(route('productos.update', $foreignProduct), [
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'name' => 'Producto robado',
                'internal_code' => $foreignProduct->internal_code,
                'product_type' => 'product',
                'cost' => 1,
                'sale_price' => 2,
                'tax_rate' => 13,
                'is_active' => 1,
            ]);

        $this->assertSame($foreignCompany->id, $foreignProduct->fresh()->company_id);
        $this->assertNotSame('Producto robado', $foreignProduct->fresh()->name);
        $response->assertNotFound();
    }

    public function test_product_destroy_of_another_company_returns_404_and_keeps_record(): void
    {
        [$company, $branch, , $user] = $this->tenant('A');
        [, , , , $foreignProduct] = $this->tenant('B');

        $response = $this->actingAs($user)
            ->withSession($this->ctx($company, $branch))
            ->delete(route('productos.destroy', $foreignProduct));

        $this->assertDatabaseHas('products', ['id' => $foreignProduct->id]);
        $response->assertNotFound();
    }

    public function test_transfer_dispatch_of_another_company_returns_404_and_keeps_stock(): void
    {
        [$company, $branch, , $user] = $this->tenant('A');
        [$foreignCompany, $foreignFrom, $foreignTo, , $foreignProduct] = $this->tenant('B');

        DB::table('branch_product')->insert([
            'branch_id' => $foreignFrom->id, 'product_id' => $foreignProduct->id, 'stock' => '5.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $transfer = InventoryTransfer::create([
            'company_id' => $foreignCompany->id,
            'from_branch_id' => $foreignFrom->id,
            'to_branch_id' => $foreignTo->id,
            'user_id' => User::factory()->create()->id,
            'transfer_number' => 'TR-AUD-'.Str::upper(Str::random(6)),
            'status' => InventoryTransfer::STATUS_PREPARED,
            'prepared_at' => now(),
            'transferred_at' => now(),
        ]);
        InventoryTransferItem::create([
            'inventory_transfer_id' => $transfer->id,
            'product_id' => $foreignProduct->id,
            'quantity' => '2.0000',
            'from_previous_stock' => '5.0000',
            'from_new_stock' => '3.0000',
            'to_previous_stock' => '0.0000',
            'to_new_stock' => '2.0000',
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->ctx($company, $branch))
            ->post(route('transferencias.dispatch', $transfer));

        $this->assertSame(InventoryTransfer::STATUS_PREPARED, $transfer->fresh()->status);
        $this->assertSame('5.0000', bcadd((string) DB::table('branch_product')
            ->where('branch_id', $foreignFrom->id)
            ->where('product_id', $foreignProduct->id)
            ->value('stock'), '0', 4));
        $this->assertDatabaseCount('inventory_movements', 0);
        $response->assertNotFound();
    }

    public function test_transfer_cancel_of_another_company_returns_404(): void
    {
        [$company, $branch, , $user] = $this->tenant('A');
        [$foreignCompany, $foreignFrom, $foreignTo] = $this->tenant('B');

        $transfer = InventoryTransfer::create([
            'company_id' => $foreignCompany->id,
            'from_branch_id' => $foreignFrom->id,
            'to_branch_id' => $foreignTo->id,
            'user_id' => User::factory()->create()->id,
            'transfer_number' => 'TR-AUD-'.Str::upper(Str::random(6)),
            'status' => InventoryTransfer::STATUS_PENDING,
            'transferred_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->ctx($company, $branch))
            ->post(route('transferencias.cancel', $transfer));

        $this->assertSame(InventoryTransfer::STATUS_PENDING, $transfer->fresh()->status);
        $response->assertNotFound();
    }

    public function test_transfer_store_rejects_foreign_destination_branch(): void
    {
        [$company, $from, $to, $user, $product] = $this->tenant('A');
        [, , $foreignTo] = $this->tenant('B');
        $this->seedStock($from, $product, '10.0000');

        $this->actingAs($user)
            ->withSession($this->ctx($company, $from))
            ->from(route('transferencias.create'))
            ->post(route('transferencias.store'), [
                'from_branch_id' => $from->id,
                'to_branch_id' => $foreignTo->id,
                'products' => [['product_id' => $product->id, 'quantity' => '1.0000']],
            ])
            ->assertSessionHasErrors('to_branch_id');

        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_transfer_store_rejects_foreign_source_branch(): void
    {
        [$company, $from, $to, $user, $product] = $this->tenant('A');
        [, $foreignFrom] = $this->tenant('B');
        $this->seedStock($from, $product, '10.0000');

        $this->actingAs($user)
            ->withSession($this->ctx($company, $from))
            ->from(route('transferencias.create'))
            ->post(route('transferencias.store'), [
                'from_branch_id' => $foreignFrom->id,
                'to_branch_id' => $to->id,
                'products' => [['product_id' => $product->id, 'quantity' => '1.0000']],
            ])
            ->assertSessionHasErrors('from_branch_id');

        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_transfer_store_rejects_foreign_product(): void
    {
        [$company, $from, $to, $user, $ownProduct] = $this->tenant('A');
        [, , , , $foreignProduct] = $this->tenant('B');
        $this->seedStock($from, $ownProduct, '10.0000');

        $this->actingAs($user)
            ->withSession($this->ctx($company, $from))
            ->from(route('transferencias.create'))
            ->post(route('transferencias.store'), [
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'products' => [['product_id' => $foreignProduct->id, 'quantity' => '1.0000']],
            ])
            ->assertSessionHasErrors('products.0.product_id');

        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_transfer_store_still_creates_own_company_transfer(): void
    {
        [$company, $from, $to, $user, $product] = $this->tenant('A');
        $this->seedStock($from, $product, '10.0000');

        $this->actingAs($user)
            ->withSession($this->ctx($company, $from))
            ->from(route('transferencias.create'))
            ->post(route('transferencias.store'), [
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'products' => [['product_id' => $product->id, 'quantity' => '2.0000']],
            ])
            ->assertRedirect(route('transferencias.index'));

        $this->assertDatabaseHas('inventory_transfers', ['company_id' => $company->id, 'status' => 'pending']);
        $this->assertDatabaseCount('inventory_transfers', 1);
    }

    private function tenant(string $prefix): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create([
            'trade_name' => $prefix.' '.$suffix, 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);
        $from = Branch::create(['company_id' => $company->id, 'name' => 'Origen '.$suffix, 'code' => 'O'.$suffix, 'is_active' => true]);
        $to = Branch::create(['company_id' => $company->id, 'name' => 'Destino '.$suffix, 'code' => 'D'.$suffix, 'is_active' => true]);

        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.$suffix, 'is_active' => true]);
        foreach (['productos.ver', 'productos.editar', 'productos.eliminar', 'inventario.transferir'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Inventario', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($from->id);

        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U '.$suffix, 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix, 'internal_code' => 'SKU-'.$suffix, 'cost' => 10,
            'sale_price' => 20, 'tax_rate' => 13, 'is_active' => true,
        ]);

        return [$company, $from, $to, $user, $product, $category, $unit];
    }

    private function seedStock(Branch $branch, Product $product, string $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ctx(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
