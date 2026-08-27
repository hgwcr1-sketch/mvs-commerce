<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryTransferP24Test extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_is_completed_atomically_with_four_decimal_stock_and_kardex(): void
    {
        [$company, $from, $to, $user, $product] = $this->context(['inventario.transferir']);
        $this->stock($from, $product, '10.5000');
        $this->assertSame('10.5000', $this->stockValue($from, $product));

        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->post(route('transferencias.store'), [
                'to_branch_id' => $to->id,
                'product_id' => $product->id,
                'quantity' => '2.1255',
                'notes' => 'Traslado P24',
            ])->assertRedirect(route('transferencias.index'));

        $transferId = DB::table('inventory_transfers')->value('id');
        $this->assertDatabaseHas('inventory_transfers', [
            'id' => $transferId,
            'company_id' => $company->id,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'status' => 'completed',
        ]);
        $this->assertSame('2.1255', bcadd((string) DB::table('inventory_transfer_items')->value('quantity'), '0', 4));
        $this->assertSame('10.5000', bcadd((string) DB::table('inventory_transfer_items')->value('from_previous_stock'), '0', 4));
        $this->assertSame('8.3745', $this->stockValue($from, $product));
        $this->assertSame('2.1255', $this->stockValue($to, $product));
        $this->assertDatabaseHas('inventory_transfer_items', [
            'inventory_transfer_id' => $transferId,
            'quantity' => 2.1255,
            'from_previous_stock' => 10.5,
            'from_new_stock' => 8.3745,
            'to_previous_stock' => 0,
            'to_new_stock' => 2.1255,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'company_id' => $company->id, 'branch_id' => $from->id, 'type' => 'transfer_out',
            'reference_type' => 'inventory_transfer', 'reference_id' => $transferId, 'new_stock' => 8.3745,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'company_id' => $company->id, 'branch_id' => $to->id, 'type' => 'transfer_in',
            'reference_type' => 'inventory_transfer', 'reference_id' => $transferId, 'new_stock' => 2.1255,
        ]);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_insufficient_stock_rolls_back_destination_transfer_detail_and_kardex(): void
    {
        [$company, $from, $to, $user, $product] = $this->context(['inventario.transferir']);
        $this->stock($from, $product, '1.0000');

        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->from(route('transferencias.create'))
            ->post(route('transferencias.store'), [
                'to_branch_id' => $to->id,
                'product_id' => $product->id,
                'quantity' => '1.0001',
            ])->assertRedirect(route('transferencias.create'))->assertSessionHasErrors('quantity');

        $this->assertSame('1.0000', $this->stockValue($from, $product));
        $this->assertDatabaseMissing('branch_product', ['branch_id' => $to->id, 'product_id' => $product->id]);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_company_and_branch_scope_block_unavailable_destinations_and_foreign_products(): void
    {
        [$company, $from, $to, $user, $product] = $this->context(['inventario.transferir'], false);
        [$otherCompany, $otherFrom, $otherTo, $otherUser, $otherProduct] = $this->context(['inventario.transferir']);
        $this->stock($from, $product, '5.0000');
        $hiddenFrom = Branch::create(['company_id' => $company->id, 'name' => 'Oculta A', 'code' => 'HA'.Str::random(5), 'is_active' => true]);
        $hiddenTo = Branch::create(['company_id' => $company->id, 'name' => 'Oculta B', 'code' => 'HB'.Str::random(5), 'is_active' => true]);
        DB::table('inventory_transfers')->insert([
            'company_id' => $company->id, 'from_branch_id' => $hiddenFrom->id, 'to_branch_id' => $hiddenTo->id,
            'user_id' => $user->id, 'transfer_number' => 'TR-OCULTA-P24', 'status' => 'completed',
            'transferred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->get(route('transferencias.create'))->assertOk()->assertDontSee($to->name);
        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->get(route('transferencias.index'))->assertOk()->assertDontSee('TR-OCULTA-P24');

        foreach ([$to, $otherTo] as $forbiddenDestination) {
            $response = $this->actingAs($user)->withSession($this->activeSession($company, $from))
                ->post(route('transferencias.store'), [
                    'to_branch_id' => $forbiddenDestination->id,
                    'product_id' => $product->id,
                    'quantity' => '1.0000',
                ]);
            $this->assertSame(404, $response->getStatusCode());
        }

        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->from(route('transferencias.create'))
            ->post(route('transferencias.store'), [
                'to_branch_id' => $to->id,
                'product_id' => $otherProduct->id,
                'quantity' => '1.0000',
            ])->assertSessionHasErrors('product_id');

        $this->assertSame('5.0000', $this->stockValue($from, $product));
        $this->assertDatabaseCount('inventory_transfers', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_other_branch_permission_allows_company_destination(): void
    {
        [$company, $from, $to, $user, $product] = $this->context([
            'inventario.transferir', 'inventario.ver_otras_sucursales',
        ], false);
        $this->stock($from, $product, '3.0000');

        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->get(route('transferencias.create'))->assertOk()->assertSee($to->name);
        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->post(route('transferencias.store'), [
                'to_branch_id' => $to->id, 'product_id' => $product->id, 'quantity' => '1.0000',
            ])->assertRedirect(route('transferencias.index'));

        $this->assertSame('2.0000', $this->stockValue($from, $product));
        $this->assertSame('1.0000', $this->stockValue($to, $product));
    }

    public function test_transfer_search_uses_transfer_permission_and_only_returns_tracked_company_products(): void
    {
        [$company, $from, $to, $user, $product] = $this->context(['inventario.transferir']);
        $untracked = $product->replicate()->fill([
            'name' => 'Sin inventario', 'internal_code' => 'NO-STOCK-'.Str::random(5), 'track_inventory' => false,
        ]);
        $untracked->save();
        [$otherCompany, $otherFrom, $otherTo, $otherUser, $otherProduct] = $this->context([]);
        $otherProduct->update(['name' => $product->name]);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->getJson(route('transferencias.products.search', ['q' => $product->name]))
            ->assertOk();

        $this->assertSame([$product->id], $response->json('*.id'));
        $this->assertContains('active.branch', Route::getRoutes()->getByName('transferencias.store')->gatherMiddleware());
        $this->assertContains('permission:inventario.transferir', Route::getRoutes()->getByName('transferencias.products.search')->gatherMiddleware());
    }

    public function test_untracked_integer_decimal_and_lot_products_are_rejected_without_mutation(): void
    {
        [$company, $from, $to, $user, $product] = $this->context(['inventario.transferir']);
        $this->stock($from, $product, '5.0000');

        $product->update(['track_inventory' => false]);
        $this->postTransfer($user, $company, $from, $to, $product, '1.0000')->assertSessionHasErrors('product_id');
        $product->update(['track_inventory' => true]);
        $product->unit()->update(['allows_decimals' => false]);
        $this->postTransfer($user, $company, $from, $to, $product, '1.5000')->assertSessionHasErrors('quantity');

        InventoryLot::create([
            'company_id' => $company->id, 'branch_id' => $from->id, 'product_id' => $product->id,
            'lot_number' => 'LOTE-P24', 'initial_quantity' => '5.0000', 'current_quantity' => '5.0000',
        ]);
        $this->postTransfer($user, $company, $from, $to, $product, '1.0000')->assertSessionHasErrors('product_id');

        $this->assertSame('5.0000', $this->stockValue($from, $product));
        $this->assertDatabaseMissing('branch_product', ['branch_id' => $to->id, 'product_id' => $product->id]);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_permission_and_active_branch_are_enforced(): void
    {
        [$company, $from, $to, $user] = $this->context([]);

        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->get(route('transferencias.index'))->assertForbidden();

        $permission = Permission::firstOrCreate(['name' => 'inventario.transferir'], [
            'label' => 'Transferir', 'module' => 'Inventario', 'is_active' => true,
        ]);
        Role::query()->where('company_id', $company->id)->firstOrFail()->permissions()->attach($permission);
        $user->branches()->detach();

        $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->get(route('transferencias.index'))->assertForbidden();
    }

    private function context(array $permissions, bool $assignDestination = true): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'Transfer '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $from = Branch::create(['company_id' => $company->id, 'name' => 'Origen '.$suffix, 'code' => 'O'.$suffix, 'is_active' => true]);
        $to = Branch::create(['company_id' => $company->id, 'name' => 'Destino '.$suffix, 'code' => 'D'.$suffix, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Transfer '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Inventario', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($from->id);
        if ($assignDestination) {
            $user->branches()->attach($to->id);
        }
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix, 'internal_code' => 'SKU-'.$suffix, 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true,
        ]);

        return [$company, $from, $to, $user, $product];
    }

    private function stock(Branch $branch, Product $product, string $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock,
            'minimum_stock' => null, 'maximum_stock' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stockValue(Branch $branch, Product $product): string
    {
        return bcadd((string) DB::table('branch_product')->where('branch_id', $branch->id)
            ->where('product_id', $product->id)->value('stock'), '0', 4);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function postTransfer(User $user, Company $company, Branch $from, Branch $to, Product $product, string $quantity)
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $from))
            ->from(route('transferencias.create'))->post(route('transferencias.store'), [
                'to_branch_id' => $to->id, 'product_id' => $product->id, 'quantity' => $quantity,
            ]);
    }
}
