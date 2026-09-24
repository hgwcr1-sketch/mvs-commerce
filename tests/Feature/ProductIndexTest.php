<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Color;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplier;
use App\Models\Role;
use App\Models\Size;
use App\Models\Style;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_style_size_color_columns(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General', 'slug' => 'general', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u', 'is_active' => true]);
        $style = Style::create(['company_id' => $company->id, 'name' => 'Casual', 'slug' => 'casual', 'is_active' => true]);
        $size = Size::create(['company_id' => $company->id, 'name' => 'Mediana', 'abbreviation' => 'M', 'slug' => 'm', 'is_active' => true]);
        $color = Color::create(['company_id' => $company->id, 'name' => 'Azul', 'hex_code' => '#0000FF', 'slug' => 'azul', 'is_active' => true]);

        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'style_id' => $style->id,
            'size_id' => $size->id,
            'color_id' => $color->id,
            'name' => 'Polo Básico',
            'internal_code' => 'POLO-001',
            'product_type' => 'product',
            'cost' => 1000,
            'sale_price' => 2000,
            'tax_rate' => 13,
            'is_active' => true,
        ]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.index'))
            ->assertOk()
            ->assertSee('Casual')
            ->assertSee('Mediana')
            ->assertSee('Azul');
    }

    public function test_category_filter_excludes_other_categories(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $categoryA = ProductCategory::create(['company_id' => $company->id, 'name' => 'A', 'slug' => 'a', 'is_active' => true]);
        $categoryB = ProductCategory::create(['company_id' => $company->id, 'name' => 'B', 'slug' => 'b', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u', 'is_active' => true]);

        $inA = Product::create(['company_id' => $company->id, 'category_id' => $categoryA->id, 'unit_id' => $unit->id, 'name' => 'Producto A', 'internal_code' => 'PA', 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true]);
        $inB = Product::create(['company_id' => $company->id, 'category_id' => $categoryB->id, 'unit_id' => $unit->id, 'name' => 'Producto B', 'internal_code' => 'PB', 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true]);

        foreach ([$inA, $inB] as $product) {
            DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.index', ['category' => $categoryA->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Producto A', $html);
        $this->assertStringNotContainsString('Producto B', $html);
    }

    public function test_invalid_category_values_do_not_filter(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $categoryA = ProductCategory::create(['company_id' => $company->id, 'name' => 'A', 'slug' => 'a', 'is_active' => true]);
        $categoryB = ProductCategory::create(['company_id' => $company->id, 'name' => 'B', 'slug' => 'b', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u', 'is_active' => true]);

        $inA = Product::create(['company_id' => $company->id, 'category_id' => $categoryA->id, 'unit_id' => $unit->id, 'name' => 'Producto A', 'internal_code' => 'PA', 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true]);
        $inB = Product::create(['company_id' => $company->id, 'category_id' => $categoryB->id, 'unit_id' => $unit->id, 'name' => 'Producto B', 'internal_code' => 'PB', 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true]);

        foreach ([$inA, $inB] as $product) {
            DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (['', '0', '-1', 'invalid'] as $value) {
            $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('productos.index', ['category' => $value]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Producto A', $html, "category={$value} should not filter");
            $this->assertStringContainsString('Producto B', $html, "category={$value} should not filter");
        }
    }

    public function test_all_categories_option_preserves_every_product(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $categoryA = ProductCategory::create(['company_id' => $company->id, 'name' => 'A', 'slug' => 'a', 'is_active' => true]);
        $categoryB = ProductCategory::create(['company_id' => $company->id, 'name' => 'B', 'slug' => 'b', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u', 'is_active' => true]);

        $inA = Product::create(['company_id' => $company->id, 'category_id' => $categoryA->id, 'unit_id' => $unit->id, 'name' => 'Categorizado A', 'internal_code' => 'CAT-A', 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true]);
        $inB = Product::create(['company_id' => $company->id, 'category_id' => $categoryB->id, 'unit_id' => $unit->id, 'name' => 'Categorizado B', 'internal_code' => 'CAT-B', 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true]);

        foreach ([$inA, $inB] as $product) {
            DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.index', ['category' => '']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Categorizado A', $html);
        $this->assertStringContainsString('Categorizado B', $html);
    }

    public function test_index_shows_primary_active_supplier(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company);
        $supplier = Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor Oficial', 'commercial_name' => 'Comercial S.A.', 'is_active' => true]);
        ProductSupplier::create(['company_id' => $company->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'is_primary' => true, 'is_active' => true]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.index'))
            ->assertOk()
            ->assertSee('Comercial S.A.');
    }

    public function test_cost_renders_without_trailing_zeros(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company, ['cost' => '1000.0000']);

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('₡ 1000', $html);
        $this->assertStringNotContainsString('1000.0000', $html);
    }

    public function test_cost_preserves_real_decimals(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company, ['cost' => '1000.1250']);

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1000.125', $html);
        $this->assertStringNotContainsString('1000.1250', $html);
    }

    public function test_edit_form_accepts_four_decimals_and_trims_zeros(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver', 'productos.editar']);
        $product = $this->product($company, ['cost' => '1000.5000']);

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.edit', $product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('step="0.0001"', $html);
        $this->assertStringContainsString('value="1000.5"', $html);
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

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$id,
            'internal_code' => 'P-'.$id,
            'cost' => 100,
            'sale_price' => 200,
            'tax_rate' => 13,
            'is_active' => true,
        ], $attributes));
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
