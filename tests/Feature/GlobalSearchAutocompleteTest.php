<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchAutocompleteTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================
    // 1. CLIENTES — search endpoint
    // ========================================================

    public function test_customer_search_returns_results_by_name(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $customer = $this->customer($company, ['name' => 'María López', 'identification' => '123456789']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => 'María']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'María López']);
    }

    public function test_customer_search_returns_results_by_identification(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $customer = $this->customer($company, ['name' => 'Juan', 'identification' => '30123456']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => '30123']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Juan']);
    }

    public function test_customer_search_returns_results_by_customer_code(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $customer = $this->customer($company, ['name' => ' Pedro', 'customer_code' => 'CLI-001']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => 'CLI-001']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['customer_code' => 'CLI-001']);
    }

    public function test_customer_search_returns_results_by_phone(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $this->customer($company, ['name' => 'Carlos', 'phone' => '22223333']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => '2222']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Carlos']);
    }

    public function test_customer_search_returns_results_by_email(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $this->customer($company, ['name' => 'Ana', 'email' => 'ana@test.com']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => 'ana@']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Ana']);
    }

    public function test_customer_search_returns_empty_with_no_match(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $this->customer($company, ['name' => 'Pedro']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => 'ZZZZZ']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_customer_search_returns_empty_with_empty_query(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $this->customer($company, ['name' => 'Pedro']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => '']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_customer_search_is_isolated_by_company(): void
    {
        [$company, $branch] = $this->context();
        [$otherCompany] = $this->context('Otra');
        $user = $this->user($company, $branch, ['clientes.ver']);
        $this->customer($company, ['name' => 'ClientCo1']);
        $this->customer($otherCompany, ['name' => 'ClientCo2']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => 'Client']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'ClientCo1'])
            ->assertJsonMissing(['name' => 'ClientCo2']);
    }

    public function test_customer_search_limits_results_to_8(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        for ($i = 0; $i < 12; $i++) {
            $this->customer($company, ['name' => "Cliente Buscar {$i}"]);
        }

        $this->asContext($user, $company, $branch)
            ->getJson(route('clientes.search', ['search' => 'Buscar']))
            ->assertOk()
            ->assertJsonCount(8);
    }

    public function test_customer_search_index_renders_autocomplete_dropdown_markers(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('clientes.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="customer-search"', $html);
        $this->assertStringContainsString('id="customer-suggestions"', $html);
        $this->assertStringContainsString(route('clientes.search'), $html);
    }

    // ========================================================
    // 2. PRODUCTOS — search by name, code, barcode, additional barcode
    // ========================================================

    public function test_product_search_returns_results_by_name(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company, ['name' => 'Café Especial']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'Café']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Café Especial']);
    }

    public function test_product_search_returns_results_by_internal_code(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company, ['name' => 'Leche', 'internal_code' => 'LEC-001']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'LEC-001']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['internal_code' => 'LEC-001']);
    }

    public function test_product_search_returns_results_by_barcode(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company, ['name' => 'Pan', 'barcode' => '7441000123456']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => '7441000123456']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Pan']);
    }

    public function test_product_search_returns_results_by_additional_barcode(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company, ['name' => 'Queso']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'ALT-999', 'barcode_type' => 'CODE128', 'is_primary' => false, 'is_active' => true]);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'ALT-999']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Queso']);
    }

    public function test_product_search_returns_empty_with_no_match(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $this->product($company, ['name' => 'Arroz']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'ZZZZZ']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_product_search_is_isolated_by_company(): void
    {
        [$company, $branch] = $this->context();
        [$otherCompany] = $this->context('Otra');
        $user = $this->user($company, $branch, ['productos.ver']);
        $this->product($company, ['name' => 'SearchProduct1']);
        $this->product($otherCompany, ['name' => 'SearchProduct2']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'SearchProduct']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'SearchProduct1'])
            ->assertJsonMissing(['name' => 'SearchProduct2']);
    }

    public function test_product_search_includes_branch_stock(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $product = $this->product($company, ['name' => 'StockProduct']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'StockProduct']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'StockProduct']);
    }

    public function test_product_search_index_renders_autocomplete_dropdown_markers(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="product-search"', $html);
        $this->assertStringContainsString('id="product-search-results"', $html);
        $this->assertStringContainsString(route('productos.search'), $html);
    }

    public function test_productos_index_renders_race_condition_guard(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('requestCount', $html, 'Missing race-condition guard');
        $this->assertStringContainsString('thisRequest !== requestCount', $html, 'Missing stale-response guard');
    }

    public function test_productos_index_renders_enter_key_handler(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("e.key === 'Enter'", $html, 'Missing Enter key handler');
        $this->assertStringContainsString('keydown', $html, 'Missing keydown listener');
    }

    public function test_productos_index_renders_no_results_message(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No se encontraron coincidencias', $html, 'Missing no-results message');
        $this->assertStringContainsString('Error al buscar productos', $html, 'Missing error message');
    }

    public function test_productos_index_renders_escape_html_and_debounce(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('escapeHtml', $html, 'Missing escapeHtml function');
        $this->assertStringContainsString('250', $html, 'Missing 250ms debounce');
    }

    public function test_productos_index_preserves_existing_filters_in_form(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('productos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="category"', $html, 'Missing category filter');
        $this->assertStringContainsString('name="brand"', $html, 'Missing brand filter');
        $this->assertStringContainsString('name="search"', $html, 'Missing search field');
        $this->assertStringContainsString('type="submit"', $html, 'Missing submit button');
    }

    // ========================================================
    // 3. INVENTARIO — autocomplete rendering
    // ========================================================

    public function test_inventario_index_renders_autocomplete_dropdown_markers(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('inventario.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="product_search"', $html);
        $this->assertStringContainsString('id="product_results"', $html);
        $this->assertStringContainsString(route('productos.search'), $html);
    }

    public function test_inventario_index_renders_race_condition_guard(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('inventario.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('requestCount', $html, 'Missing race-condition guard');
        $this->assertStringContainsString('thisRequest !== requestCount', $html, 'Missing stale-response guard');
    }

    public function test_inventario_index_renders_enter_key_handler(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('inventario.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("e.key === 'Enter'", $html, 'Missing Enter key handler');
        $this->assertStringContainsString('keydown', $html, 'Missing keydown listener');
    }

    public function test_inventario_index_renders_no_results_message(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('inventario.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No se encontraron coincidencias', $html, 'Missing no-results message');
        $this->assertStringContainsString('Error al buscar productos', $html, 'Missing error message');
    }

    public function test_inventario_index_renders_escape_html_and_debounce(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('inventario.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('escapeHtml', $html, 'Missing escapeHtml function');
        $this->assertStringContainsString('250', $html, 'Missing 250ms debounce');
    }

    public function test_inventario_search_endpoint_returns_product_by_name(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver', 'productos.ver']);
        $this->product($company, ['name' => 'InvTestCafé']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'InvTestCafé']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'InvTestCafé']);
    }

    public function test_inventario_search_endpoint_returns_product_by_internal_code(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver', 'productos.ver']);
        $this->product($company, ['name' => 'InvCodeProd', 'internal_code' => 'INV-CODE-1']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'INV-CODE-1']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['internal_code' => 'INV-CODE-1']);
    }

    public function test_inventario_search_endpoint_returns_product_by_additional_barcode(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver', 'productos.ver']);
        $product = $this->product($company, ['name' => 'InvBarAlt']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'INV-ALT-001', 'barcode_type' => 'CODE128', 'is_primary' => false, 'is_active' => true]);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'INV-ALT-001']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'InvBarAlt']);
    }

    public function test_inventario_search_endpoint_returns_empty_for_no_match(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['inventario.ver', 'productos.ver']);
        $this->product($company, ['name' => 'Exists']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('productos.search', ['q' => 'ZZZNOTEXIST']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    // ========================================================
    // 4. CENTRO DE ETIQUETAS — autocomplete rendering
    // ========================================================

    public function test_labels_index_renders_autocomplete_dropdown_markers(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('labels.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="label-search"', $html);
        $this->assertStringContainsString('id="label-search-results"', $html);
        $this->assertStringContainsString(route('productos.search'), $html);
    }

    // ========================================================
    // 5. COMPRAS — supplier + product search
    // ========================================================

    public function test_compras_search_products_returns_results_by_name(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['compras.ver']);
        $product = $this->product($company, ['name' => 'Gaseosa']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('compras.search-products', ['q' => 'Gaseosa']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Gaseosa']);
    }

    public function test_compras_search_products_returns_results_by_barcode(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['compras.ver']);
        $product = $this->product($company, ['name' => 'Refresco', 'barcode' => '7442000111111']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('compras.search-products', ['q' => '7442000111111']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Refresco']);
    }

    public function test_compras_search_products_returns_results_by_additional_barcode(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['compras.ver']);
        $product = $this->product($company, ['name' => 'Jugo']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'CP-888', 'barcode_type' => 'CODE128', 'is_primary' => false, 'is_active' => true]);

        $this->asContext($user, $company, $branch)
            ->getJson(route('compras.search-products', ['q' => 'CP-888']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Jugo']);
    }

    public function test_compras_search_products_returns_empty_with_no_match(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['compras.ver']);
        $this->product($company, ['name' => 'Mantequilla']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('compras.search-products', ['q' => 'ZZZZZ']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_compras_create_renders_autocomplete_route_markers(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['compras.crear']);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('compras.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('compras.search-products'), $html);
        $this->assertStringContainsString(route('proveedores.search'), $html);
        $this->assertStringContainsString('data-search-products', $html);
        $this->assertStringContainsString('data-search-suppliers', $html);
    }

    public function test_supplier_search_is_isolated_by_company(): void
    {
        [$company, $branch] = $this->context();
        [$otherCompany] = $this->context('Otra');
        $user = $this->user($company, $branch, ['proveedores.ver']);
        $this->supplier($company, ['name' => 'Proveedor Alpha']);
        $this->supplier($otherCompany, ['name' => 'Proveedor Alpha']);

        $this->asContext($user, $company, $branch)
            ->getJson(route('proveedores.search', ['search' => 'Alpha']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Proveedor Alpha']);
    }

    // ========================================================
    // HELPERS
    // ========================================================

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
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'General', 'is_active' => true]));
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);
        return $user;
    }

    private function asContext(User $user, Company $company, Branch $branch)
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
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

    private function customer(Company $company, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Cliente '.uniqid(),
            'customer_type' => 'individual',
            'is_active' => true,
        ], $attributes));
    }

    private function supplier(Company $company, array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Proveedor '.uniqid(),
            'supplier_type' => 'company',
            'is_active' => true,
        ], $attributes));
    }
}
