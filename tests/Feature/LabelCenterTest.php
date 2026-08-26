<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchLabelSetting;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_permission_is_independent_and_navigation_is_integrated_in_products(): void
    {
        [$company, $branch] = $this->context();
        $viewer = $this->user($company, $branch, ['productos.ver']);
        $printer = $this->user($company, $branch, ['productos.etiquetas.imprimir']);

        $this->asContext($viewer, $company, $branch)->get(route('labels.index'))->assertForbidden();
        $this->asContext($printer, $company, $branch)->get(route('labels.index'))
            ->assertOk()->assertSee('Centro de Etiquetas')->assertSee('data-responsive="360 768 1280"', false);
    }

    public function test_filters_name_internal_code_primary_and_additional_barcode_category_and_label_flag(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $wanted = $this->product($company, ['name' => 'Café Especial', 'internal_code' => 'CAF-9', 'barcode' => null, 'prints_label' => true]);
        ProductBarcode::create(['product_id' => $wanted->id, 'barcode' => '74410009999', 'barcode_type' => 'EAN13', 'is_primary' => false, 'is_active' => true]);
        $this->product($company, ['name' => 'Otro', 'prints_label' => false]);

        foreach (['Café', 'CAF-9', '74410009999'] as $search) {
            $this->asContext($user, $company, $branch)->get(route('labels.index', ['search' => $search, 'prints_label' => 1]))
                ->assertOk()->assertSee('Café Especial')->assertDontSee('Otro');
        }
        $this->asContext($user, $company, $branch)->get(route('labels.index', ['category_id' => $wanted->category_id]))
            ->assertOk()->assertSee('Café Especial');
    }

    public function test_product_flag_and_settings_are_isolated_by_company_and_branch(): void
    {
        [$company, $branch] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Segunda', 'code' => 'S'.uniqid(), 'is_active' => true]);
        [$otherCompany] = $this->context('Otra');
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir', 'productos.etiquetas.configurar']);
        $user->branches()->attach($otherBranch);
        $product = $this->product($company);
        $foreign = $this->product($otherCompany);

        $this->asContext($user, $company, $branch)->patch(route('labels.products.update', $product), ['prints_label' => 1])->assertRedirect();
        $this->assertTrue($product->fresh()->prints_label);
        $this->asContext($user, $company, $branch)->patch(route('labels.products.update', $foreign), ['prints_label' => 1])->assertNotFound();

        $this->asContext($user, $company, $branch)->put(route('labels.settings.update'), $this->settings(['administrator']))->assertRedirect();
        $this->asContext($user, $company, $otherBranch)->put(route('labels.settings.update'), $this->settings(['cashier']))->assertRedirect();
        $this->assertSame(['administrator'], BranchLabelSetting::where('branch_id', $branch->id)->sole()->print_destinations);
        $this->assertSame(['cashier'], BranchLabelSetting::where('branch_id', $otherBranch->id)->sole()->print_destinations);
    }

    public function test_only_configurator_can_choose_cashier_administrator_or_both(): void
    {
        [$company, $branch] = $this->context();
        $printer = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $admin = $this->user($company, $branch, ['productos.etiquetas.imprimir', 'productos.etiquetas.configurar']);
        $this->asContext($printer, $company, $branch)->put(route('labels.settings.update'), $this->settings(['cashier']))->assertForbidden();
        $this->asContext($admin, $company, $branch)->put(route('labels.settings.update'), $this->settings(['cashier', 'administrator']))->assertRedirect();
        $this->assertSame(['cashier', 'administrator'], BranchLabelSetting::where('branch_id', $branch->id)->sole()->print_destinations);
    }

    public function test_preview_supports_multiple_products_quantities_templates_sizes_and_real_barcode_svg(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $first = $this->product($company, ['name' => 'Uno', 'barcode' => '744100000001']);
        $second = $this->product($company, ['name' => 'Dos', 'barcode' => null]);
        ProductBarcode::create(['product_id' => $second->id, 'barcode' => 'ALT-002', 'barcode_type' => 'CODE128', 'is_active' => true]);

        $response = $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$first->id, $second->id], 'quantities' => [$first->id => 2, $second->id => 1],
            'template' => 'name_price_barcode', 'size' => '50x30',
        ]);
        $response->assertOk()->assertSee('3 etiquetas')->assertSee('744100000001')->assertSee('ALT-002')->assertSee('<svg class="label-barcode"', false);

        $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$first->id], 'quantities' => [$first->id => 501], 'template' => 'sku', 'size' => '32x19',
        ])->assertSessionHasErrors('quantities.'.$first->id);
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
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Productos', 'is_active' => true]));
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);
        return $user;
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'is_active' => true]);
        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 100, 'sale_price' => 200, 'tax_rate' => 13, 'is_active' => true], $attributes));
    }

    private function asContext(User $user, Company $company, Branch $branch)
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }

    private function settings(array $destinations): array
    {
        return ['print_destinations' => $destinations, 'default_template' => 'name_price_barcode', 'default_size' => '50x30', 'custom_heading' => 'Oferta'];
    }
}
