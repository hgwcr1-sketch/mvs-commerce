<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB as DBFacade;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PurchaseBranchPreserveTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_without_active_branch_fails_cleanly_and_does_not_create_purchase(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();

        // Admin global sin sucursal activa: el middleware no auto-selecciona
        // y el guardado no puede inventar una sucursal destino.
        $user->branches()->detach($branch);
        $role = $user->companies()->first()->pivot->role_id;
        \App\Models\Role::findOrFail($role)->permissions()->attach(
            Permission::firstOrCreate(['name' => 'dashboard.admin'], ['label' => 'dashboard.admin', 'module' => 'Dashboard', 'is_active' => true]),
        );

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->postJson(route('compras.store'), $this->payload($supplier, $product));

        $response->assertUnprocessable();

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_items', 0);
    }

    public function test_store_registers_inventory_in_the_selected_branch_only(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'San Ramon', 'code' => 'SR'.uniqid(), 'is_active' => true]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('compras.store'), $this->payload($supplier, $product, [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1250.5, 'new_sale_price' => 1800],
            ]));

        $response->assertOk();
        $purchaseId = $response->json('purchase_id');

        $purchase = \App\Models\Purchase::findOrFail($purchaseId);
        $this->assertSame($branch->id, (int) $purchase->branch_id);
        $this->assertNotSame($otherBranch->id, (int) $purchase->branch_id);

        $this->assertSame(5.0, (float) DBFacade::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock'));
        $this->assertNull(DBFacade::table('branch_product')
            ->where('branch_id', $otherBranch->id)
            ->where('product_id', $product->id)
            ->first());

        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => '5.0000',
        ]);
    }

    public function test_store_with_explicit_branch_id_registers_in_that_branch(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        $target = Branch::create(['company_id' => $company->id, 'name' => 'Liberia', 'code' => 'LI'.uniqid(), 'is_active' => true]);
        $user->branches()->attach($target);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('compras.store'), $this->payload($supplier, $product) + ['branch_id' => $target->id]);

        $response->assertOk();
        $purchase = \App\Models\Purchase::findOrFail($response->json('purchase_id'));
        $this->assertSame($target->id, (int) $purchase->branch_id);
        $this->assertSame(2.0, (float) DBFacade::table('branch_product')
            ->where('branch_id', $target->id)
            ->where('product_id', $product->id)
            ->value('stock'));
    }

    public function test_store_rejects_branch_from_another_company(): void
    {
        [$company, $branch, $user, $supplier, $product] = $this->context();
        [$otherCompany, $otherBranch] = $this->context('Otra Empresa');

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('compras.store'), $this->payload($supplier, $product) + ['branch_id' => $otherBranch->id]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_purchase_form_uses_branch_guard_only_in_compras(): void
    {
        [$company, $branch, $user, $supplier] = $this->context();

        // En Nueva Compra el header NO debe usar el submit tradicional.
        $createPage = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('compras.create'));
        $createPage->assertOk();
        $this->assertStringNotContainsString('this.form.submit()', $createPage->getContent());

        // Fuera de Nueva Compra el selector sigue usando el submit: se usa
        // una vista con header sin la marca de compras (dashboard home).
        $user->companies()->first()->forceFill(['is_active' => true])->save();
        $rootPage = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('dashboard'));
        $rootPage->assertOk();
        $this->assertStringContainsString('this.form.submit()', $rootPage->getContent());
    }

    public function test_branch_selector_switch_without_losing_form_real_flow(): void
    {
        [$company, $branch, $user, $supplier] = $this->context();
        $liberia = Branch::create(['company_id' => $company->id, 'name' => 'Liberia', 'code' => 'LI'.uniqid(), 'is_active' => true]);
        $user->branches()->attach($liberia);

        // Página real de Nueva Compra con San Ramón activa (branch = $branch).
        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('compras.create'));

        $response->assertOk();

        // Ejecuta los scripts inline reales de la página: guarda del selector
        // del header + listener de la vista, simulando el navegador.
        $process = new Process(['node', base_path('tests/js/compras-branch-switch-real.cjs')]);
        $process->setInput($response->getContent())->mustRun();
        $this->assertStringContainsString('San Ramón -> Liberia -> San Ramón conserva TODO', $process->getOutput());
    }

    private function payload(Supplier $supplier, Product $product, ?array $items = null): array
    {
        return [
            'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'FAC-9',
            'purchase_date' => today()->toDateString(),
            'payment_type' => 'cash',
            'notes' => 'Compra con observaciones',
            'items' => $items ?? [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 1000],
            ],
        ];
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'compras.crear'], ['label' => 'compras.crear', 'module' => 'Compras', 'is_active' => true]));
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);
        $supplier = Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor '.uniqid(), 'is_active' => true]);
        $product = $this->product($company);

        return [$company, $branch, $user, $supplier, $product];
    }

    private function product(Company $company): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 10, 'sale_price' => 20, 'tax_rate' => 13, 'is_active' => true]);
    }
}
