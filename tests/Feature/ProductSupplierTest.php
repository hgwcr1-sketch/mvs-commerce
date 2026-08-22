<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplier;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductSupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_relation_with_supplier_code_and_cost(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'compras.ordenes']);
        $product = $this->product($company);
        $supplier = $this->supplier($company);

        $this->postRelation($user, $company, $branch, $product, [
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'PROV-001',
            'current_cost' => '1234.5678',
            'is_primary' => '1',
            'is_active' => '1',
            'notes' => 'Entrega semanal',
        ])->assertRedirect();

        $this->assertDatabaseHas('product_suppliers', [
            'company_id' => $company->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'PROV-001',
            'current_cost' => 1234.5678,
            'is_primary' => true,
            'is_active' => true,
            'notes' => 'Entrega semanal',
        ]);
    }

    public function test_company_isolation_rejects_foreign_supplier_product_and_relation(): void
    {
        [$company, $branch] = $this->context('Primera');
        [$otherCompany, $otherBranch] = $this->context('Segunda');
        $user = $this->user($company, $branch, ['productos.ver', 'productos.editar']);
        $product = $this->product($company);
        $otherProduct = $this->product($otherCompany);
        $otherSupplier = $this->supplier($otherCompany);

        $this->postRelation($user, $company, $branch, $product, ['supplier_id' => $otherSupplier->id])
            ->assertSessionHasErrors('supplier_id');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.proveedores.index', $otherProduct))
            ->assertNotFound();

        try {
            ProductSupplier::create([
                'company_id' => $company->id,
                'product_id' => $product->id,
                'supplier_id' => $otherSupplier->id,
            ]);
            $this->fail('Se esperaba validación de aislamiento empresarial.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_id', $exception->errors());
        }

        $this->assertDatabaseCount('product_suppliers', 0);
        $this->assertNotSame($branch->id, $otherBranch->id);
    }

    public function test_only_one_active_primary_exists_and_selecting_another_changes_it(): void
    {
        [$company] = $this->context();
        $product = $this->product($company);
        $first = $this->relation($product, $this->supplier($company), ['is_primary' => true]);
        $second = $this->relation($product, $this->supplier($company), ['is_primary' => true]);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, $product->productSuppliers()->where('is_primary', true)->where('is_active', true)->count());
    }

    public function test_inactive_supplier_cannot_be_selected_for_new_relation(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'compras.ordenes']);
        $product = $this->product($company);
        $supplier = $this->supplier($company, ['is_active' => false]);

        $this->postRelation($user, $company, $branch, $product, ['supplier_id' => $supplier->id])
            ->assertSessionHasErrors('supplier_id');
        $this->assertDatabaseCount('product_suppliers', 0);
    }

    public function test_relation_can_be_edited_activated_and_deactivated_without_changing_product_cost(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'compras.ordenes']);
        $product = $this->product($company, ['cost' => 500]);
        $relation = $this->relation($product, $this->supplier($company));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.proveedores.update', [$product, $relation]), [
                'supplier_product_code' => 'EDIT-9',
                'current_cost' => '789.1234',
                'is_primary' => '0',
                'is_active' => '0',
                'notes' => 'Temporalmente suspendido',
            ])->assertRedirect();

        $relation->refresh();
        $this->assertSame('EDIT-9', $relation->supplier_product_code);
        $this->assertSame('789.1234', $relation->current_cost);
        $this->assertFalse($relation->is_active);
        $this->assertSame('Temporalmente suspendido', $relation->notes);
        $this->assertSame('500.00', $product->fresh()->cost);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.proveedores.update', [$product, $relation]), [
                'is_primary' => '1',
                'is_active' => '1',
            ])->assertRedirect();
        $this->assertTrue($relation->fresh()->is_active);
        $this->assertTrue($relation->fresh()->is_primary);
    }

    public function test_eloquent_relations_expose_products_suppliers_and_pivot_model(): void
    {
        [$company] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $relation = $this->relation($product, $supplier, ['current_cost' => 25.5]);

        $this->assertTrue($relation->product->is($product));
        $this->assertTrue($relation->supplier->is($supplier));
        $this->assertTrue($relation->company->is($company));
        $this->assertTrue($product->productSuppliers->sole()->is($relation));
        $this->assertTrue($supplier->productSuppliers->sole()->is($relation));
        $this->assertTrue($product->suppliers->sole()->is($supplier));
        $this->assertTrue($supplier->products->sole()->is($product));
        $this->assertSame('25.5000', $relation->current_cost);
    }

    public function test_permissions_separate_read_access_from_management(): void
    {
        [$company, $branch] = $this->context();
        $product = $this->product($company);
        $supplier = $this->supplier($company);
        $viewer = $this->user($company, $branch, ['productos.ver']);
        $editor = $this->user($company, $branch, ['productos.ver', 'productos.editar']);

        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.proveedores.index', $product))
            ->assertOk()->assertSee('Proveedores asociados')->assertDontSee('Agregar proveedor');
        $this->postRelation($viewer, $company, $branch, $product, ['supplier_id' => $supplier->id])
            ->assertForbidden();
        $this->actingAs($editor)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.proveedores.index', $product))
            ->assertOk()->assertSee('Agregar proveedor')->assertDontSee('Costo actual');
        $this->postRelation($editor, $company, $branch, $product, ['supplier_id' => $supplier->id, 'current_cost' => 99])
            ->assertSessionHasErrors('current_cost');
        $this->assertDatabaseCount('product_suppliers', 0);
    }

    public function test_relation_can_be_deleted_when_it_has_no_historical_dependency(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar']);
        $product = $this->product($company);
        $relation = $this->relation($product, $this->supplier($company));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->delete(route('productos.proveedores.destroy', [$product, $relation]))
            ->assertRedirect();
        $this->assertDatabaseMissing('product_suppliers', ['id' => $relation->id]);
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Productos', 'is_active' => true]);
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
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'is_active' => true]);

        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true], $attributes));
    }

    private function supplier(Company $company, array $attributes = []): Supplier
    {
        return Supplier::create(array_merge(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor '.uniqid(), 'is_active' => true], $attributes));
    }

    private function relation(Product $product, Supplier $supplier, array $attributes = []): ProductSupplier
    {
        return ProductSupplier::create(array_merge(['company_id' => $product->company_id, 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'is_active' => true, 'is_primary' => false], $attributes));
    }

    private function postRelation(User $user, Company $company, Branch $branch, Product $product, array $data)
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.proveedores.store', $product), $data + ['is_primary' => '0', 'is_active' => '1']);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
