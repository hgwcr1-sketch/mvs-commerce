<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\BranchLabelSetting;
use App\Models\Color;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\LoyaltySetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DemoCompanyProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCompanyProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private DemoCompanyProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->provisioner = app(DemoCompanyProvisioner::class);
    }

    // =========================================================
    // A. CREACIÓN DE DEMO
    // =========================================================

    public function test_create_builds_complete_demo_company(): void
    {
        $company = $this->provisioner->create();

        $this->assertNotNull($company);
        $this->assertSame(config('demo.company_name'), $company->trade_name);
        $this->assertTrue($company->is_active);
        $this->assertSame('CRC', $company->currency);
        $this->assertSame('America/Costa_Rica', $company->timezone);
        $this->assertSame(config('demo.owner_email'), $company->owner->email);
    }

    public function test_create_builds_two_branches(): void
    {
        $company = $this->provisioner->create();

        $this->assertSame(2, $company->branches()->count());
        $this->assertDatabaseHas('branches', ['company_id' => $company->id, 'code' => 'DSJ', 'name' => 'Demo San José']);
        $this->assertDatabaseHas('branches', ['company_id' => $company->id, 'code' => 'DLB', 'name' => 'Demo Liberia']);
    }

    public function test_create_builds_four_users(): void
    {
        $company = $this->provisioner->create();

        $this->assertDatabaseHas('users', ['email' => 'demo@mvscommerce.com', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['email' => 'vendedor.demo@mvscommerce.com', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['email' => 'cajero.demo@mvscommerce.com', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['email' => 'bodeguero.demo@mvscommerce.com', 'is_active' => true]);
    }

    public function test_create_builds_catalogs(): void
    {
        $company = $this->provisioner->create();
        $cid = $company->id;

        $this->assertGreaterThanOrEqual(5, ProductCategory::where('company_id', $cid)->count());
        $this->assertGreaterThanOrEqual(3, Brand::where('company_id', $cid)->count());
        $this->assertGreaterThanOrEqual(3, ProductCategory::where('company_id', $cid)->whereNull('parent_id')->count());
        $this->assertGreaterThan(0, ProductCategory::where('company_id', $cid)->whereNotNull('parent_id')->count());
        $this->assertDatabaseHas('styles', ['company_id' => $cid]);
        $this->assertDatabaseHas('sizes', ['company_id' => $cid]);
        $this->assertDatabaseHas('colors', ['company_id' => $cid]);
    }

    public function test_create_builds_products_with_style_size_color(): void
    {
        $company = $this->provisioner->create();

        $products = Product::where('company_id', $company->id)->get();
        $this->assertGreaterThanOrEqual(25, $products->count());

        foreach ($products as $product) {
            $this->assertNotNull($product->style_id, "Product {$product->name} missing style");
            $this->assertNotNull($product->size_id, "Product {$product->name} missing size");
            $this->assertNotNull($product->color_id, "Product {$product->name} missing color");
            $this->assertTrue($product->track_inventory);
            $this->assertTrue($product->is_active);
        }
    }

    public function test_create_builds_inventory_in_both_branches(): void
    {
        $company = $this->provisioner->create();
        $branches = $company->branches()->get();
        $products = Product::where('company_id', $company->id)->get();

        foreach ($products as $product) {
            foreach ($branches as $branch) {
                $this->assertDatabaseHas('branch_product', [
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                ]);
            }
        }
    }

    public function test_create_builds_customers(): void
    {
        $company = $this->provisioner->create();

        $customerCount = Customer::where('company_id', $company->id)->count();
        $this->assertGreaterThanOrEqual(10, $customerCount);
    }

    public function test_create_builds_suppliers(): void
    {
        $company = $this->provisioner->create();

        $supplierCount = Supplier::where('company_id', $company->id)->count();
        $this->assertGreaterThanOrEqual(3, $supplierCount);
    }

    public function test_create_builds_loyalty_settings(): void
    {
        $company = $this->provisioner->create();

        $this->assertDatabaseHas('loyalty_settings', [
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => 5.0,
        ]);
    }

    public function test_create_builds_label_settings_per_branch(): void
    {
        $company = $this->provisioner->create();

        $branchCount = $company->branches()->count();
        $labelCount = BranchLabelSetting::where('company_id', $company->id)->count();
        $this->assertSame($branchCount, $labelCount);
    }

    public function test_create_builds_cash_registers_per_branch(): void
    {
        $company = $this->provisioner->create();

        $this->assertDatabaseHas('cash_registers', ['company_id' => $company->id, 'code' => 'CAJA-DSJ', 'name' => 'Caja Principal DSJ']);
        $this->assertDatabaseHas('cash_registers', ['company_id' => $company->id, 'code' => 'CAJA-DLB', 'name' => 'Caja Principal DLB']);
    }

    public function test_create_creates_asset_directories(): void
    {
        $company = $this->provisioner->create();

        $runtimeBase = config('demo.assets_runtime');
        $demoDir = $runtimeBase . '/' . $company->id;

        $this->assertDirectoryExists($demoDir);
        $this->assertDirectoryExists($demoDir . '/products');
        $this->assertDirectoryExists($demoDir . '/loyalty');
        $this->assertDirectoryExists($demoDir . '/promotions');
    }

    // =========================================================
    // B. SEGUNDA EJECUCIÓN NO DUPLICA
    // =========================================================

    public function test_second_create_does_not_duplicate(): void
    {
        $first = $this->provisioner->create();
        $second = $this->provisioner->create();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Company::where('trade_name', config('demo.company_name'))->count());
    }

    // =========================================================
    // C. RESET RESTAURA ESTADO BASE
    // =========================================================

    public function test_reset_restores_base_state(): void
    {
        $company = $this->provisioner->create();
        $originalId = $company->id;
        $originalBranchIds = $company->branches()->pluck('id')->toArray();

        $productCount = Product::where('company_id', $originalId)->count();
        $this->assertGreaterThan(0, $productCount);

        $reset = $this->provisioner->reset();

        $this->assertSame($originalId, $reset->id);
        $this->assertTrue($reset->fresh()->is_active);
        $this->assertSame(2, $reset->branches()->count());
        $this->assertGreaterThanOrEqual(25, Product::where('company_id', $originalId)->count());
        $this->assertGreaterThanOrEqual(10, Customer::where('company_id', $originalId)->count());
        $this->assertDatabaseHas('loyalty_settings', ['company_id' => $originalId, 'is_active' => true]);
    }

    // =========================================================
    // D. RESET BORRA CAMBIOS DEL DÍA SOLO DE DEMO
    // =========================================================

    public function test_reset_removes_products_added_to_demo(): void
    {
        $company = $this->provisioner->create();

        Product::create([
            'company_id' => $company->id,
            'category_id' => ProductCategory::where('company_id', $company->id)->first()->id,
            'unit_id' => \App\Models\Unit::where('company_id', $company->id)->first()->id,
            'name' => 'Producto Temporal',
            'internal_code' => 'TEMP-001',
            'product_type' => 'product',
            'cost' => 1000,
            'sale_price' => 2000,
            'tax_rate' => 13,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('products', ['internal_code' => 'TEMP-001']);

        $this->provisioner->reset();

        $this->assertDatabaseMissing('products', ['internal_code' => 'TEMP-001']);
        $this->assertGreaterThanOrEqual(25, Product::where('company_id', $company->id)->count());
    }

    public function test_reset_removes_customers_added_to_demo(): void
    {
        $company = $this->provisioner->create();

        Customer::create([
            'company_id' => $company->id,
            'identification' => '999999999',
            'name' => 'Cliente Temporal',
            'customer_type' => 'individual',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('customers', ['identification' => '999999999', 'company_id' => $company->id]);

        $this->provisioner->reset();

        $this->assertDatabaseMissing('customers', ['identification' => '999999999', 'company_id' => $company->id]);
    }

    // =========================================================
    // E. OTRA EMPRESA QUEDA INTACTA
    // =========================================================

    public function test_another_company_remains_intact_after_demo_create(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Otra Empresa ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $demo = $this->provisioner->create();

        $this->assertDatabaseHas('companies', ['id' => $otherCompany->id, 'trade_name' => $otherCompany->trade_name]);
        $this->assertTrue($otherCompany->fresh()->is_active);
    }

    public function test_another_company_remains_intact_after_demo_reset(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Otra Empresa Reset ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $otherOwner = User::factory()->create();
        $otherCompany->users()->attach($otherOwner->id);

        $demo = $this->provisioner->create();

        $this->provisioner->reset();

        $this->assertDatabaseHas('companies', ['id' => $otherCompany->id]);
        $this->assertTrue($otherCompany->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $otherOwner->id]);
    }

    // =========================================================
    // F. USUARIOS DEMO RECUPERAN CREDENCIALES/ROLES
    // =========================================================

    public function test_users_get_roles_with_permissions(): void
    {
        $company = $this->provisioner->create();

        $admin = User::where('email', 'demo@mvscommerce.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasPermission('dashboard.ver', $company));
        $this->assertTrue($admin->hasPermission('productos.crear', $company));
        $this->assertTrue($admin->hasPermission('inventario.ver', $company));
        $this->assertTrue($admin->hasPermission('ventas.crear', $company));
    }

    public function test_users_have_branch_access(): void
    {
        $company = $this->provisioner->create();

        $seller = User::where('email', 'vendedor.demo@mvscommerce.com')->first();
        $this->assertNotNull($seller);

        $branchIds = $company->branches()->pluck('branches.id')->all();
        foreach ($branchIds as $branchId) {
            $this->assertDatabaseHas('branch_user', ['user_id' => $seller->id, 'branch_id' => $branchId]);
        }
    }

    // =========================================================
    // G. RESET USER PASSWORDS
    // =========================================================

    public function test_reset_restores_user_passwords(): void
    {
        $company = $this->provisioner->create();

        $admin = User::where('email', 'demo@mvscommerce.com')->first();
        $admin->update(['password' => \Illuminate\Support\Facades\Hash::make('OldPassword123*')]);

        $this->provisioner->reset();

        $admin = User::where('email', 'demo@mvscommerce.com')->first();
        $this->assertTrue($admin->is_active);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(config('demo.owner_password'), $admin->password));
    }

    // =========================================================
    // H. PROTECCIÓN CONTRA RESET DE EMPRESA REAL
    // =========================================================

    public function test_reset_rejects_non_demo_company(): void
    {
        $demo = $this->provisioner->create();
        $originalProducts = Product::where('company_id', $demo->id)->count();

        $realCompany = Company::create([
            'trade_name' => 'Empresa Real',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $this->provisioner->reset();

        $this->assertDatabaseHas('companies', ['id' => $realCompany->id]);
        $this->assertTrue($realCompany->fresh()->is_active);
        $this->assertGreaterThanOrEqual($originalProducts, Product::where('company_id', $demo->id)->count());
    }

    public function test_is_demo_company_identifies_correctly(): void
    {
        $demo = $this->provisioner->create();
        $real = Company::create([
            'trade_name' => 'Empresa Real',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $this->assertTrue($this->provisioner->isDemoCompany($demo));
        $this->assertFalse($this->provisioner->isDemoCompany($real));
    }

    // =========================================================
    // I. NO DELETE/TRUNCATE GLOBAL
    // =========================================================

    public function test_provisioner_does_not_truncate_global_permissions(): void
    {
        $this->assertGreaterThan(0, \App\Models\Permission::count());

        $this->provisioner->create();

        $this->assertGreaterThan(0, \App\Models\Permission::count());
    }

    public function test_provisioner_does_not_truncate_global_countries(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('countries')->count();

        $this->provisioner->create();

        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('countries')->count());
    }

    // =========================================================
    // J. ARTISAN COMMAND REGISTRATION
    // =========================================================

    public function test_demo_command_is_registered(): void
    {
        $this->artisan('demo:company')
            ->assertExitCode(0);
    }

    public function test_demo_command_creates_on_first_run(): void
    {
        $this->artisan('demo:company')
            ->expectsOutputToContain('Empresa demo creada exitosamente')
            ->assertExitCode(0);

        $this->assertDatabaseHas('companies', ['trade_name' => config('demo.company_name')]);
    }

    public function test_demo_command_shows_info_when_exists(): void
    {
        $this->provisioner->create();

        $this->artisan('demo:company')
            ->expectsOutputToContain('La empresa demo ya existe')
            ->assertExitCode(0);
    }

    public function test_demo_command_reset_requires_confirmation(): void
    {
        $this->provisioner->create();

        $this->artisan('demo:company --reset')
            ->expectsConfirmation('¿Continuar con el reset?', 'no')
            ->expectsOutputToContain('Reset cancelado')
            ->assertExitCode(0);
    }

    public function test_demo_command_reset_proceeds_with_yes(): void
    {
        $this->provisioner->create();

        $this->artisan('demo:company --reset')
            ->expectsConfirmation('¿Continuar con el reset?', 'yes')
            ->expectsOutputToContain('Empresa demo reseteada exitosamente')
            ->assertExitCode(0);
    }

    // =========================================================
    // K. IMAGE PATHS Aislados
    // =========================================================

    public function test_demo_assets_are_isolated_per_company(): void
    {
        $demo = $this->provisioner->create();

        $runtimeBase = config('demo.assets_runtime');
        $demoDir = $runtimeBase . '/' . $demo->id;

        $this->assertDirectoryExists($demoDir);
        $this->assertDirectoryExists($demoDir . '/products');
        $this->assertDirectoryExists($demoDir . '/loyalty');
        $this->assertDirectoryExists($demoDir . '/promotions');
    }

    public function test_sync_demo_assets_only_creates_for_demo_company(): void
    {
        $demo = $this->provisioner->create();
        $otherCompany = Company::create([
            'trade_name' => 'Otra ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $runtimeBase = config('demo.assets_runtime');
        $demoDir = $runtimeBase . '/' . $demo->id;

        $this->assertDirectoryExists($demoDir);
        $this->assertDirectoryExists($demoDir . '/products');
        $this->assertDirectoryExists($demoDir . '/loyalty');
        $this->assertDirectoryExists($demoDir . '/promotions');
    }

    public function test_demo_assets_not_touched_on_another_company_operation(): void
    {
        $demo = $this->provisioner->create();

        $runtimeBase = config('demo.assets_runtime');
        $demoDir = $runtimeBase . '/' . $demo->id;
        @file_put_contents($demoDir . '/products/test.txt', 'demo file');

        $otherCompany = Company::create([
            'trade_name' => 'Otra ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $this->assertFileExists($demoDir . '/products/test.txt');
    }

    // =========================================================
    // L. LOYALTY / LABELS CONFIGURATION
    // =========================================================

    public function test_loyalty_is_configured_with_realistic_values(): void
    {
        $company = $this->provisioner->create();

        $loyalty = LoyaltySetting::where('company_id', $company->id)->first();
        $this->assertNotNull($loyalty);
        $this->assertTrue($loyalty->is_active);
        $this->assertSame('5.0000', (string) $loyalty->earning_percentage);
        $this->assertTrue($loyalty->birthday_enabled);
        $this->assertTrue($loyalty->expiration_enabled);
        $this->assertSame(12, $loyalty->expiration_months);
    }

    public function test_labels_configured_per_branch(): void
    {
        $company = $this->provisioner->create();

        $labels = BranchLabelSetting::where('company_id', $company->id)->get();
        $this->assertSame($company->branches()->count(), $labels->count());

        foreach ($labels as $label) {
            $this->assertSame('thermal', $label->default_print_mode);
            $this->assertSame('name_price_barcode', $label->default_template);
        }
    }

    // =========================================================
    // M. LICENSE DEMO PERMANENTE
    // =========================================================

    public function test_demo_license_is_permanent_after_create(): void
    {
        $company = $this->provisioner->create();

        $license = CompanyLicense::where('company_id', $company->id)->first();
        $this->assertNotNull($license);
        $this->assertSame('active', $license->status);
        $this->assertNull($license->expires_at);
        $this->assertNull($license->grace_until);
    }

    public function test_demo_license_is_permanent_after_reset(): void
    {
        $company = $this->provisioner->create();

        $license = CompanyLicense::where('company_id', $company->id)->first();
        $license->update(['status' => 'trial', 'expires_at' => now()->addDays(30), 'grace_until' => now()->addDays(37)]);

        $this->provisioner->reset();

        $license = $license->fresh();
        $this->assertSame('active', $license->status);
        $this->assertNull($license->expires_at);
        $this->assertNull($license->grace_until);
    }

    public function test_other_company_license_not_affected(): void
    {
        $otherCompany = Company::create([
            'trade_name' => 'Empresa Real',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $otherLicense = CompanyLicense::create([
            'company_id' => $otherCompany->id,
            'status' => 'trial',
            'plan' => 'Prueba',
            'starts_at' => now(),
            'expires_at' => now()->addDays(15),
            'grace_until' => now()->addDays(22),
        ]);

        $this->provisioner->create();

        $otherLicense->refresh();
        $this->assertSame('trial', $otherLicense->status);
        $this->assertNotNull($otherLicense->expires_at);
    }

    // =========================================================
    // N. RESET NO INTERACTIVO CON --force
    // =========================================================

    public function test_demo_command_reset_force_skips_confirmation(): void
    {
        $this->provisioner->create();

        $this->artisan('demo:company --reset --force')
            ->expectsOutputToContain('Empresa demo reseteada exitosamente')
            ->assertExitCode(0);
    }

    public function test_demo_command_reset_without_force_requires_confirmation(): void
    {
        $this->provisioner->create();

        $this->artisan('demo:company --reset')
            ->expectsConfirmation('¿Continuar con el reset?', 'no')
            ->expectsOutputToContain('Reset cancelado')
            ->assertExitCode(0);
    }

    // =========================================================
    // O. RESET DE IMÁGENES
    // =========================================================

    public function test_extra_file_disappears_after_reset(): void
    {
        $company = $this->provisioner->create();

        $runtimeBase = config('demo.assets_runtime');
        $demoDir = $runtimeBase . '/' . $company->id;
        file_put_contents($demoDir . '/products/uploaded_by_user.png', 'fake image data');

        $this->assertFileExists($demoDir . '/products/uploaded_by_user.png');

        $this->provisioner->reset();

        $this->assertFileDoesNotExist($demoDir . '/products/uploaded_by_user.png');
        $this->assertDirectoryExists($demoDir . '/products');
    }

    public function test_base_assets_restored_after_reset(): void
    {
        $company = $this->provisioner->create();

        $runtimeBase = config('demo.assets_runtime');
        $demoDir = $runtimeBase . '/' . $company->id;

        $this->assertDirectoryExists($demoDir . '/products');
        $this->assertDirectoryExists($demoDir . '/loyalty');
        $this->assertDirectoryExists($demoDir . '/promotions');

        $this->provisioner->reset();

        $this->assertDirectoryExists($demoDir . '/products');
        $this->assertDirectoryExists($demoDir . '/loyalty');
        $this->assertDirectoryExists($demoDir . '/promotions');
    }

    public function test_other_company_assets_not_touched_after_demo_reset(): void
    {
        $demo = $this->provisioner->create();

        $otherCompany = Company::create([
            'trade_name' => 'Otra ' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $runtimeBase = config('demo.assets_runtime');
        $otherDir = $runtimeBase . '/' . $otherCompany->id;
        @mkdir($otherDir . '/products', 0755, true);
        file_put_contents($otherDir . '/products/keep_me.txt', 'important');

        $this->provisioner->reset();

        $this->assertFileExists($otherDir . '/products/keep_me.txt');
    }

    // =========================================================
    // P. CATÁLOGO DETERMINISTA
    // =========================================================

    private function snapshotProducts(Company $company): array
    {
        return Product::where('company_id', $company->id)
            ->with(['style', 'size', 'color'])
            ->orderBy('internal_code')
            ->get()
            ->map(fn ($p) => [
                'code' => $p->internal_code,
                'name' => $p->name,
                'style' => $p->style?->name,
                'size' => $p->size?->name,
                'color' => $p->color?->name,
                'cost' => $p->cost,
                'sale_price' => $p->sale_price,
                'wholesale_price' => $p->wholesale_price,
            ])
            ->values()
            ->toArray();
    }

    public function test_create_produces_deterministic_products(): void
    {
        $first = $this->provisioner->create();
        $snapshot1 = $this->snapshotProducts($first);

        $this->provisioner->reset();
        $snapshot2 = $this->snapshotProducts($first);

        $this->assertSame(30, count($snapshot1));
        $this->assertSame($snapshot1, $snapshot2);
    }

    public function test_reset_produces_identical_catalog(): void
    {
        $company = $this->provisioner->create();
        $beforeReset = $this->snapshotProducts($company);

        $this->provisioner->reset();
        $afterReset = $this->snapshotProducts($company);

        $this->assertSame($beforeReset, $afterReset);
    }

    public function test_second_reset_still_identical(): void
    {
        $company = $this->provisioner->create();
        $original = $this->snapshotProducts($company);

        $this->provisioner->reset();
        $this->provisioner->reset();
        $final = $this->snapshotProducts($company);

        $this->assertSame($original, $final);
    }

    public function test_all_products_have_style_size_color(): void
    {
        $company = $this->provisioner->create();

        Product::where('company_id', $company->id)->each(function ($p) {
            $this->assertNotNull($p->style_id, "{$p->internal_code} missing style_id");
            $this->assertNotNull($p->size_id, "{$p->internal_code} missing size_id");
            $this->assertNotNull($p->color_id, "{$p->internal_code} missing color_id");
        });
    }
}
