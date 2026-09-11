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
use Illuminate\Support\Facades\DB;
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

    public function test_a4_mode_renders_grid_layout(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $product = $this->product($company, ['barcode' => '744100000001']);

        $response = $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$product->id], 'quantities' => [$product->id => 1],
            'template' => 'name_price', 'size' => '50x30', 'print_mode' => 'a4',
        ]);
        $response->assertOk()->assertSee('1 etiquetas')->assertDontSee('Térmica');
    }

    public function test_thermal_mode_renders_consecutive_labels(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $product = $this->product($company, ['name' => 'Café', 'barcode' => '744100000001']);

        $response = $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$product->id], 'quantities' => [$product->id => 3],
            'template' => 'name_price_barcode', 'size' => '40x25', 'print_mode' => 'thermal',
        ]);
        $response->assertOk()->assertSee('3 etiquetas')->assertSee('Térmica');
    }

    public function test_thermal_custom_size_overrides_preset(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $product = $this->product($company, ['barcode' => '744100000001']);

        $response = $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$product->id], 'quantities' => [$product->id => 2],
            'template' => 'sku', 'size' => '50x30',
            'print_mode' => 'thermal', 'use_custom_size' => '1',
            'custom_width' => 80, 'custom_height' => 40,
        ]);
        $response->assertOk()->assertSee('80 × 40 mm')->assertSee('Térmica');
    }

    public function test_thermal_multiple_products_expands_quantities(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $a = $this->product($company, ['name' => 'Producto A', 'barcode' => '744100000001']);
        $b = $this->product($company, ['name' => 'Producto B', 'barcode' => '744100000002']);

        $response = $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$a->id, $b->id],
            'quantities' => [$a->id => 3, $b->id => 2],
            'template' => 'name_price', 'size' => '40x25', 'print_mode' => 'thermal',
        ]);
        $response->assertOk()->assertSee('5 etiquetas')->assertSee('Producto A')->assertSee('Producto B');
    }

    public function test_thermal_does_not_affect_inventory_or_products(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $product = $this->product($company, ['barcode' => '744100000001', 'sale_price' => 2500]);
        $beforePrice = $product->sale_price;
        $beforeStock = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock');

        $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$product->id], 'quantities' => [$product->id => 5],
            'template' => 'name_price_barcode', 'size' => '40x25', 'print_mode' => 'thermal',
        ])->assertOk();

        $this->assertSame($beforeStock, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame($beforePrice, $product->fresh()->sale_price);
    }

    public function test_index_shows_print_mode_selector(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $this->asContext($user, $company, $branch)->get(route('labels.index'))
            ->assertOk()->assertSee('print_mode')->assertSee('Hoja A4')->assertSee('Impresora térmica');
    }

    public function test_thermal_config_persists_and_loads_on_reload(): void
    {
        [$company, $branch] = $this->context();
        $admin = $this->user($company, $branch, ['productos.etiquetas.imprimir', 'productos.etiquetas.configurar']);

        $this->asContext($admin, $company, $branch)->put(route('labels.settings.update'), [
            'print_destinations' => ['administrator'],
            'default_template' => 'name_price_barcode',
            'default_size' => '50x30',
            'custom_heading' => null,
            'default_print_mode' => 'thermal',
            'use_custom_size' => '1',
            'custom_width' => 42,
            'custom_height' => 30,
        ])->assertRedirect();

        $setting = BranchLabelSetting::where('branch_id', $branch->id)->sole();
        $this->assertTrue($setting->use_custom_size);
        $this->assertSame(42, $setting->custom_width);
        $this->assertSame(30, $setting->custom_height);
        $this->assertSame('thermal', $setting->default_print_mode);

        $html = $this->asContext($admin, $company, $branch)->get(route('labels.index'))->assertOk()->getContent();

        $this->assertStringContainsString('value="thermal" selected', $html, 'Settings form: thermal option not selected');
        $this->assertStringContainsString('value="42"', $html, 'Settings form: custom_width not 42');
        $this->assertStringContainsString('value="30"', $html, 'Settings form: custom_height not 30');

        $batchSelected = str_contains($html, 'name="print_mode"') && str_contains($html, 'value="thermal" selected');
        $this->assertTrue($batchSelected, 'Batch form: thermal option not selected');

        $this->assertStringContainsString('name="use_custom_size" id="useCustomSize" value="1"', $html, 'Batch form: custom size checkbox missing');
        $this->assertStringContainsString('id="useCustomSize"', $html);
        $this->assertStringContainsString('value="42"', $html, 'Batch form: custom_width not 42');
        $this->assertStringContainsString('value="30"', $html, 'Batch form: custom_height not 30');
    }

    public function test_details_opens_after_saving_settings(): void
    {
        [$company, $branch] = $this->context();
        $admin = $this->user($company, $branch, ['productos.etiquetas.imprimir', 'productos.etiquetas.configurar']);

        $this->asContext($admin, $company, $branch)->put(route('labels.settings.update'), [
            'print_destinations' => ['administrator'],
            'default_template' => 'name_price_barcode',
            'default_size' => '50x30',
            'custom_heading' => null,
            'default_print_mode' => 'thermal',
            'use_custom_size' => '0',
            'custom_width' => 50,
            'custom_height' => 30,
        ])->assertRedirect();

        $html = $this->asContext($admin, $company, $branch)->get(route('labels.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<details', $html);
        $this->assertMatchesRegularExpression('/<details[^>]*\bopen\b/', $html, 'Details element should be open after save');
    }

    public function test_thermal_config_is_isolated_per_branch(): void
    {
        [$company, $branch] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal B', 'code' => 'SB'.uniqid(), 'is_active' => true]);
        $admin = $this->user($company, $branch, ['productos.etiquetas.imprimir', 'productos.etiquetas.configurar']);
        $admin->branches()->attach($otherBranch);

        $this->asContext($admin, $company, $branch)->put(route('labels.settings.update'), [
            'print_destinations' => ['administrator'],
            'default_template' => 'name_price_barcode',
            'default_size' => '50x30',
            'custom_heading' => null,
            'default_print_mode' => 'thermal',
            'use_custom_size' => '1',
            'custom_width' => 42,
            'custom_height' => 30,
        ])->assertRedirect();

        $this->asContext($admin, $company, $otherBranch)->put(route('labels.settings.update'), [
            'print_destinations' => ['cashier'],
            'default_template' => 'sku',
            'default_size' => '40x25',
            'custom_heading' => null,
            'default_print_mode' => 'a4',
            'use_custom_size' => '1',
            'custom_width' => 80,
            'custom_height' => 60,
        ])->assertRedirect();

        $this->assertSame(42, BranchLabelSetting::where('branch_id', $branch->id)->sole()->custom_width);
        $this->assertSame(30, BranchLabelSetting::where('branch_id', $branch->id)->sole()->custom_height);
        $this->assertSame('thermal', BranchLabelSetting::where('branch_id', $branch->id)->sole()->default_print_mode);

        $this->assertSame(80, BranchLabelSetting::where('branch_id', $otherBranch->id)->sole()->custom_width);
        $this->assertSame(60, BranchLabelSetting::where('branch_id', $otherBranch->id)->sole()->custom_height);
        $this->assertSame('a4', BranchLabelSetting::where('branch_id', $otherBranch->id)->sole()->default_print_mode);
    }

    public function test_a4_still_works_after_thermal_config_added(): void
    {
        [$company, $branch] = $this->context();
        $admin = $this->user($company, $branch, ['productos.etiquetas.imprimir', 'productos.etiquetas.configurar']);
        $product = $this->product($company, ['barcode' => '744100000001']);

        $this->asContext($admin, $company, $branch)->put(route('labels.settings.update'), [
            'print_destinations' => ['administrator'],
            'default_template' => 'name_price_barcode',
            'default_size' => '50x30',
            'custom_heading' => null,
            'default_print_mode' => 'a4',
            'use_custom_size' => '0',
            'custom_width' => 50,
            'custom_height' => 30,
        ])->assertRedirect();

        $response = $this->asContext($admin, $company, $branch)->post(route('labels.preview'), [
            'products' => [$product->id],
            'quantities' => [$product->id => 1],
            'template' => 'name_price_barcode',
            'size' => '50x30',
            'print_mode' => 'a4',
        ]);
        $response->assertOk()->assertSee('1 etiquetas')->assertDontSee('Térmica');
    }

    public function test_thermal_preview_header_shows_correct_format(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.etiquetas.imprimir']);
        $product = $this->product($company, ['barcode' => '744100000001']);

        $response = $this->asContext($user, $company, $branch)->post(route('labels.preview'), [
            'products' => [$product->id],
            'quantities' => [$product->id => 2],
            'template' => 'name_price_barcode',
            'size' => '40x25',
            'print_mode' => 'thermal',
        ]);
        $response->assertOk()->assertSee('2 etiquetas · 40 × 25 mm')->assertSee('Térmica');
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
        return ['print_destinations' => $destinations, 'default_template' => 'name_price_barcode', 'default_size' => '50x30', 'custom_heading' => 'Oferta', 'default_print_mode' => 'a4', 'use_custom_size' => '0', 'custom_width' => 50, 'custom_height' => 30];
    }
}
