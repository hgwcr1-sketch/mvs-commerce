<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PosAccessAndSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_and_active_context_can_open_pos(): void
    {
        [$company, $branch, $user] = $this->context(true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee($company->trade_name)
            ->assertSee($branch->name)
            ->assertSee($user->name);
    }

    public function test_user_without_permission_receives_forbidden_for_pos_and_search(): void
    {
        [$company, $branch, $user] = $this->context(false);
        $session = $this->activeSession($company, $branch);

        $this->actingAs($user)->withSession($session)->get(route('pos.index'))->assertForbidden();
        $this->actingAs($user)->withSession($session)->getJson(route('pos.products.search', ['q' => 'a']))->assertForbidden();
    }

    public function test_sidebar_shows_pos_with_permission_and_hides_it_without_permission(): void
    {
        [$company, $branch, $allowedUser] = $this->context(true);
        $this->actingAs($allowedUser)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertSee('href="'.route('pos.index').'"', false);

        [$otherCompany, $otherBranch, $deniedUser] = $this->context(false);
        $this->actingAs($deniedUser)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.route('pos.index').'"', false);
    }

    public function test_search_finds_by_name_and_returns_minimal_payload(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $product = $this->product($company, ['name' => 'Champú hidratante']);

        $response = $this->search($user, $company, $branch, 'hidratante')->assertOk();

        $response->assertJsonCount(1)->assertJsonPath('0.id', $product->id);
        $this->assertEqualsCanonicalizing([
            'id', 'name', 'internal_code', 'matched_barcode', 'sale_price', 'tax_rate',
            'controls_inventory', 'available_stock',
            'can_add_to_cart',
            'has_image', 'image_url',
        ], array_keys($response->json('0')));
    }

    public function test_search_finds_by_internal_code(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $product = $this->product($company, ['internal_code' => 'INT-900']);

        $this->search($user, $company, $branch, 'INT-900')
            ->assertJsonPath('0.id', $product->id);
    }

    public function test_search_finds_primary_and_additional_barcodes(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $primary = $this->product($company, ['barcode' => '744100000001']);
        $additional = $this->product($company);
        ProductBarcode::create([
            'product_id' => $additional->id,
            'barcode' => '744100000002',
            'barcode_type' => 'EAN13',
            'is_primary' => false,
            'is_active' => true,
        ]);

        $this->search($user, $company, $branch, '744100000001')
            ->assertJsonPath('0.id', $primary->id)
            ->assertJsonPath('0.matched_barcode', '744100000001');
        $this->search($user, $company, $branch, '744100000002')
            ->assertJsonPath('0.id', $additional->id)
            ->assertJsonPath('0.matched_barcode', '744100000002');
    }

    public function test_search_excludes_inactive_deleted_and_other_company_products(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);
        $visible = $this->product($company, ['name' => 'Coincidencia visible']);
        $this->product($company, ['name' => 'Coincidencia inactiva', 'is_active' => false]);
        $deleted = $this->product($company, ['name' => 'Coincidencia eliminada']);
        $deleted->delete();
        $this->product($otherCompany, ['name' => 'Coincidencia ajena']);

        $response = $this->search($user, $company, $branch, 'Coincidencia')->assertJsonCount(1);
        $response->assertJsonPath('0.id', $visible->id);
    }

    public function test_search_returns_stock_only_from_active_branch(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $otherBranch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Secundaria',
            'code' => 'S-'.$company->id,
            'is_active' => true,
        ]);
        $product = $this->product($company, ['name' => 'Producto stock']);
        $product->branches()->attach($branch->id, ['stock' => 4]);
        $product->branches()->attach($otherBranch->id, ['stock' => 99]);

        $this->search($user, $company, $branch, 'Producto stock')
            ->assertJsonPath('0.available_stock', 4);
    }

    public function test_pos_lists_only_active_payment_methods_from_active_company_including_paypal(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);
        $this->paymentMethod($company, 'PayPal', 'paypal', true);
        $this->paymentMethod($company, 'Método inactivo', 'inactive', false);
        $this->paymentMethod($otherCompany, 'Método ajeno', 'foreign', true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('PayPal')
            ->assertDontSee('Método inactivo')
            ->assertDontSee('Método ajeno');
    }

    public function test_exact_barcode_match_has_priority(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $partial = $this->product($company, ['name' => 'A primero', 'barcode' => 'XX-ABC-123-YY']);
        $exact = $this->product($company, ['name' => 'Z último', 'barcode' => 'ABC-123']);

        $response = $this->search($user, $company, $branch, 'ABC-123');

        $response->assertJsonPath('0.id', $exact->id);
        $this->assertSame($partial->id, $response->json('1.id'));
    }

    public function test_local_stock_and_inventory_control_determine_if_product_can_be_added(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $available = $this->product($company, ['name' => 'Disponibilidad disponible']);
        $available->branches()->attach($branch->id, ['stock' => 3]);
        $service = $this->product($company, [
            'name' => 'Disponibilidad servicio',
            'track_inventory' => false,
        ]);
        $empty = $this->product($company, ['name' => 'Disponibilidad agotado']);

        $response = $this->search($user, $company, $branch, 'Disponibilidad')->assertJsonCount(3);
        $products = collect($response->json())->keyBy('id');

        $this->assertTrue($products[$available->id]['can_add_to_cart']);
        $this->assertTrue($products[$service->id]['can_add_to_cart']);
        $this->assertFalse($products[$empty->id]['can_add_to_cart']);
        $this->assertSame($empty->id, $response->json('2.id'));
    }

    public function test_other_branch_stock_is_returned_only_with_permission_and_never_changes_local_availability(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->grantPermission($user, $company, 'inventario.ver_otras_sucursales');
        $liberia = Branch::create([
            'company_id' => $company->id,
            'name' => 'Liberia',
            'code' => 'LIB-'.$company->id,
            'is_active' => true,
        ]);
        $inactive = Branch::create([
            'company_id' => $company->id,
            'name' => 'Inactiva',
            'code' => 'INA-'.$company->id,
            'is_active' => false,
        ]);
        [$otherCompany, $otherCompanyBranch] = $this->context(false);
        $product = $this->product($company, ['name' => 'Producto externo']);
        $product->branches()->attach($branch->id, ['stock' => 0]);
        $product->branches()->attach($liberia->id, ['stock' => 5]);
        $product->branches()->attach($inactive->id, ['stock' => 8]);
        DB::table('branch_product')->insert([
            'branch_id' => $otherCompanyBranch->id,
            'product_id' => $product->id,
            'stock' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->search($user, $company, $branch, 'Producto externo');

        $response->assertJsonPath('0.can_add_to_cart', false)
            ->assertJsonCount(1, '0.other_branch_stock')
            ->assertJsonPath('0.other_branch_stock.0.branch_id', $liberia->id)
            ->assertJsonPath('0.other_branch_stock.0.branch_name', 'Liberia')
            ->assertJsonPath('0.other_branch_stock.0.available_stock', 5);
        $this->assertNotSame($branch->id, $response->json('0.other_branch_stock.0.branch_id'));
        $this->assertNotSame($otherCompany->id, $company->id);
    }

    public function test_other_branch_stock_is_not_returned_or_exposed_without_permission(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $otherBranch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Nicoya',
            'code' => 'NIC-'.$company->id,
            'is_active' => true,
        ]);
        $product = $this->product($company, ['name' => 'Producto privado']);
        $product->branches()->attach($otherBranch->id, ['stock' => 10]);

        $response = $this->search($user, $company, $branch, 'Producto privado');

        $this->assertArrayNotHasKey('other_branch_stock', $response->json('0'));
        $response->assertJsonPath('0.can_add_to_cart', false);
    }

    public function test_permission_seeder_assigns_other_branch_inventory_permission_only_to_administrator(): void
    {
        $company = Company::create(['trade_name' => 'Empresa permisos', 'is_active' => true]);
        $administrator = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);
        $otherRole = Role::create([
            'company_id' => $company->id,
            'name' => 'Supervisor',
            'is_active' => true,
        ]);

        $this->seed(PermissionSeeder::class);

        $this->assertTrue($administrator->permissions()->where('name', 'inventario.ver_otras_sucursales')->exists());
        $this->assertFalse($otherRole->permissions()->where('name', 'inventario.ver_otras_sucursales')->exists());
    }

    public function test_product_with_real_public_image_returns_safe_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/pos-test.jpg', 'image-content');
        [$company, $branch, $user] = $this->context(true);
        $product = $this->product($company, [
            'name' => 'Producto con imagen',
            'image' => 'products/pos-test.jpg',
        ]);

        $response = $this->search($user, $company, $branch, 'Producto con imagen');

        $response->assertJsonPath('0.id', $product->id)
            ->assertJsonPath('0.has_image', true)
            ->assertJsonPath('0.image_url', '/storage/products/pos-test.jpg');
        $this->assertStringNotContainsString(storage_path(), $response->json('0.image_url'));
        $this->assertStringNotContainsString('C:\\', $response->json('0.image_url'));
    }

    public function test_product_without_valid_image_returns_safe_empty_image_output(): void
    {
        Storage::fake('public');
        [$company, $branch, $user] = $this->context(true);
        $this->product($company, [
            'name' => 'Producto sin imagen',
            'image' => 'products/missing.jpg',
        ]);

        $this->search($user, $company, $branch, 'Producto sin imagen')
            ->assertJsonPath('0.has_image', false)
            ->assertJsonPath('0.image_url', null);
    }

    public function test_pos_view_contains_image_modal_and_all_close_controls(): void
    {
        [$company, $branch, $user] = $this->context(true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('@click.self="closeImage"', false)
            ->assertSee('@keydown.escape.window="closeImage"', false)
            ->assertSee('aria-label="Cerrar imagen"', false);
    }

    public function test_user_without_pos_permission_cannot_obtain_product_image_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/private.jpg', 'image-content');
        [$company, $branch, $user] = $this->context(false);
        $this->product($company, ['name' => 'Imagen privada', 'image' => 'products/private.jpg']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.products.search', ['q' => 'Imagen privada']))
            ->assertForbidden()
            ->assertDontSee('/storage/products/private.jpg');
    }

    private function context(bool $withPermission): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'is_active' => true]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal '.$company->id,
            'code' => 'P-'.$company->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);
        if ($withPermission) {
            $permission = Permission::firstOrCreate(
                ['name' => 'pos.acceder'],
                ['label' => 'Acceder al POS', 'module' => 'POS', 'is_active' => true],
            );
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function search(User $user, Company $company, Branch $branch, string $query)
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.products.search', ['q' => $query]));
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$suffix,
            'slug' => 'category-'.$suffix,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad '.$suffix,
            'abbreviation' => 'U',
            'slug' => 'unit-'.$suffix,
            'is_active' => true,
        ]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'CODE-'.$suffix,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function paymentMethod(Company $company, string $name, string $code, bool $active): PaymentMethod
    {
        return PaymentMethod::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => $code,
            'type' => PaymentMethod::TYPE_OTHER,
            'is_system' => false,
            'is_active' => $active,
            'affects_cash' => false,
            'requires_reference' => false,
            'allows_change' => false,
            'sort_order' => 10,
        ]);
    }

    private function grantPermission(User $user, Company $company, string $name): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => $name],
            ['label' => $name, 'module' => 'Inventario', 'is_active' => true],
        );
        $user->roleInCompany($company)->permissions()->syncWithoutDetaching($permission);
    }
}
