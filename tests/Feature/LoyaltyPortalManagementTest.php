<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalPost;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPortalManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_has_one_portal_entry_for_each_granular_permission(): void
    {
        foreach (['fidelidad.portal.ver', 'fidelidad.portal.configurar', 'fidelidad.portal.contenido', 'fidelidad.portal.enlaces'] as $permission) {
            [$company, $branch, $user] = $this->context([$permission]);
            $this->actingAs($user)->withSession($this->activeSession($company, $branch));
            $html = view('components.navigation.sidebar')->render();
            $this->assertSame(1, substr_count($html, 'Portal de Clientes'));
            $this->assertStringNotContainsString('Accesos al portal', $html);
            $this->assertStringNotContainsString('Promociones del portal', $html);
        }
    }

    public function test_content_editor_can_publish_catalog_product_but_not_change_configuration(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.portal.contenido']);
        $product = $this->product($company, 'Producto real', 'REAL');
        $payload = ['type' => 'offer', 'product_id' => $product->id, 'title' => 'Oferta real', 'message' => 'Vigente', 'starts_at' => now()->subHour()->format('Y-m-d H:i:s'), 'ends_at' => now()->addHour()->format('Y-m-d H:i:s'), 'is_active' => 1, 'is_featured' => 1, 'sort_order' => 1];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('loyalty.portal-management.posts.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('loyalty_portal_posts', ['company_id' => $company->id, 'product_id' => $product->id, 'is_featured' => true]);
        $this->put(route('loyalty.portal-management.settings.update'), ['is_active' => 1])->assertForbidden();
    }

    public function test_content_and_links_are_isolated_by_company_and_validate_foreign_catalog(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.portal.contenido', 'fidelidad.portal.enlaces']);
        [$other] = $this->context(['fidelidad.portal.contenido']);
        $foreignProduct = $this->product($other, 'Ajeno', 'AJENO');
        $foreignPost = LoyaltyPortalPost::create(['company_id' => $other->id, 'type' => 'notice', 'title' => 'Ajeno', 'is_active' => true]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('loyalty.portal-management.posts.store'), ['type' => 'offer', 'product_id' => $foreignProduct->id, 'title' => 'Inválida', 'is_active' => 1])->assertSessionHasErrors('product_id');
        $this->delete(route('loyalty.portal-management.posts.destroy', $foreignPost))->assertNotFound();
        $this->post(route('loyalty.portal-management.links.store'), ['type' => 'store', 'label' => 'Tienda', 'url' => 'https://empresa.example', 'is_active' => 1])->assertRedirect();
        $this->assertDatabaseHas('loyalty_portal_links', ['company_id' => $company->id, 'url' => 'https://empresa.example']);
        $this->assertDatabaseMissing('loyalty_portal_links', ['company_id' => $other->id, 'url' => 'https://empresa.example']);
    }

    public function test_configuration_and_preview_are_independent_permissions(): void
    {
        [$company, $branch, $configurator] = $this->context(['fidelidad.portal.configurar']);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente', 'is_active' => true]);
        $session = $this->activeSession($company, $branch);
        $this->actingAs($configurator)->withSession($session)->put(route('loyalty.portal-management.settings.update'), ['welcome_message' => 'Bienvenido', 'is_active' => 1])->assertRedirect();
        $this->get(route('loyalty.portal-management.preview', $customer))->assertForbidden();
        [, , $viewer] = $this->context(['fidelidad.portal.ver'], $company, $branch);
        $this->actingAs($viewer)->withSession($session)->get(route('loyalty.portal-management.preview', $customer))->assertOk()->assertSee('Cliente');
    }

    public function test_user_without_portal_permissions_cannot_open_management(): void
    {
        [$company, $branch, $user] = $this->context([]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('loyalty.portal-management.index'))->assertForbidden();
    }

    private function context(array $permissions, ?Company $company = null, ?Branch $branch = null): array
    {
        $company ??= Company::create(['trade_name' => 'Empresa '.uniqid(), 'legal_name' => 'Empresa', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch ??= Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function product(Company $company, string $name, string $code): Product
    {
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$code, 'slug' => strtolower($code), 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$code, 'abbreviation' => 'ud', 'slug' => 'ud-'.strtolower($code), 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => $name, 'internal_code' => $code, 'product_type' => 'physical', 'cost' => 10, 'sale_price' => 20, 'special_price' => 15, 'is_active' => true]);
    }
}
