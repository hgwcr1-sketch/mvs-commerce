<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplier;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ControlCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlCenterTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. ACCESS CONTROL ──────────────────────────────────────────

    public function test_user_with_dashboard_admin_can_access(): void
    {
        [$company, $branch, $user] = $this->ctx(['dashboard.admin']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('control-center.index'))
            ->assertOk()
            ->assertSee('Centro de Control');
    }

    public function test_user_without_dashboard_admin_is_denied(): void
    {
        [$company, $branch, $user] = $this->ctx(['compras.ver']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('control-center.index'))
            ->assertForbidden();
    }

    // ── 2. MULTITENANT ISOLATION ──────────────────────────────────

    public function test_company_a_never_sees_company_b_products(): void
    {
        [$company, $branch, $user] = $this->ctx(['dashboard.admin']);
        [$coB, $brB] = $this->ctxBranch('OtherCo');
        $suffixB = bin2hex(random_bytes(4));
        $catB = ProductCategory::create(['company_id' => $coB->id, 'name' => 'C '.$suffixB, 'slug' => 'c-'.$suffixB, 'is_active' => true]);
        $unitB = Unit::create(['company_id' => $coB->id, 'name' => 'U '.$suffixB, 'abbreviation' => 'UB', 'slug' => 'ub-'.$suffixB, 'allows_decimals' => true, 'is_active' => true]);
        $prodB = Product::create([
            'company_id' => $coB->id, 'category_id' => $catB->id, 'unit_id' => $unitB->id,
            'name' => 'Secreto B', 'internal_code' => 'SB', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);
        $this->stock($brB, $prodB, '2');

        // Product in A below minimum
        $suffixA = bin2hex(random_bytes(4));
        $catA = ProductCategory::create(['company_id' => $company->id, 'name' => 'C '.$suffixA, 'slug' => 'ca-'.$suffixA, 'is_active' => true]);
        $unitA = Unit::create(['company_id' => $company->id, 'name' => 'U '.$suffixA, 'abbreviation' => 'UA', 'slug' => 'ua-'.$suffixA, 'allows_decimals' => true, 'is_active' => true]);
        $prodA = Product::create([
            'company_id' => $company->id, 'category_id' => $catA->id, 'unit_id' => $unitA->id,
            'name' => 'Producto A', 'internal_code' => 'PA', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);
        $this->stock($branch, $prodA, '2');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $allNames = collect($result['replenishment'])->pluck('product_name')
            ->merge(collect($result['transfer_suggestions'])->pluck('product_name'))
            ->values()->all();

        $this->assertContains('Producto A', $allNames);
        $this->assertNotContains('Secreto B', $allNames);
    }

    // ── 3. TRANSFER BEFORE PURCHASE ───────────────────────────────

    public function test_transfer_recommended_when_surplus_exists(): void
    {
        [$company, $from, $to, $user] = $this->ctxMultiBranch(['dashboard.admin', 'inventario.transferir']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Producto X', 'internal_code' => 'PX', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        // "from" branch has surplus, "to" branch is short
        $this->stock($from, $product, '25');
        $this->stock($to, $product, '3');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $to->id);

        $transferIds = array_column($result['transfer_suggestions'], 'product_id');
        $purchaseIds = array_column($result['replenishment'], 'product_id');

        $this->assertContains($product->id, $transferIds, 'Product should appear as transfer suggestion');
        $this->assertNotContains($product->id, $purchaseIds, 'Product fully covered by transfer should NOT appear as purchase');
    }

    public function test_partial_transfer_still_generates_purchase(): void
    {
        [$company, $from, $to] = $this->ctxMultiBranch(['dashboard.admin']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Producto Partial', 'internal_code' => 'PP', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 20, 'is_active' => true,
        ]);

        // from has only 5 surplus, to needs 17 (20-3)
        $this->stock($from, $product, '25'); // surplus = 5
        $this->stock($to, $product, '3');    // deficit = 17

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $to->id);

        $transferItem = collect($result['transfer_suggestions'])->firstWhere('product_id', $product->id);
        $purchaseItem = collect($result['replenishment'])->firstWhere('product_id', $product->id);

        $this->assertNotNull($transferItem, 'Transfer suggestion should exist');
        $this->assertEquals('5', $transferItem['suggested_quantity']);
        $this->assertNotNull($purchaseItem, 'Purchase suggestion should exist for remainder');
    }

    public function test_no_surplus_means_no_transfer(): void
    {
        [$company, $from, $to] = $this->ctxMultiBranch(['dashboard.admin']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Sin Excedente', 'internal_code' => 'SE', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        $this->stock($from, $product, '5'); // no surplus
        $this->stock($to, $product, '3');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $to->id);

        $this->assertEmpty($result['transfer_suggestions']);
        $this->assertCount(1, $result['replenishment']);
    }

    // ── 4. PURCHASE GROUPING BY SUPPLIER ──────────────────────────

    public function test_products_same_supplier_are_grouped(): void
    {
        [$company, $branch] = $this->ctxBranch('TestCo');

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Proveedor Uno', 'identification' => '123', 'is_active' => true]);

        $p1 = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Prod A', 'internal_code' => 'PA', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);
        $p2 = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Prod B', 'internal_code' => 'PB', 'cost' => 15, 'sale_price' => 30,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 8, 'is_active' => true,
        ]);

        ProductSupplier::create(['company_id' => $company->id, 'product_id' => $p1->id, 'supplier_id' => $supplier->id, 'current_cost' => '10.0000', 'is_primary' => true, 'is_active' => true]);
        ProductSupplier::create(['company_id' => $company->id, 'product_id' => $p2->id, 'supplier_id' => $supplier->id, 'current_cost' => '15.0000', 'is_primary' => true, 'is_active' => true]);

        $this->stock($branch, $p1, '2');
        $this->stock($branch, $p2, '1');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $this->assertCount(1, $result['purchase_groups'], 'Both products should be in one group');
        $this->assertEquals($supplier->id, $result['purchase_groups'][0]['supplier_id']);
        $this->assertCount(2, $result['purchase_groups'][0]['items']);
    }

    public function test_different_suppliers_create_separate_groups(): void
    {
        [$company, $branch] = $this->ctxBranch('TestCo2');

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $sup1 = Supplier::create(['company_id' => $company->id, 'name' => 'Proveedor A', 'identification' => '111', 'is_active' => true]);
        $sup2 = Supplier::create(['company_id' => $company->id, 'name' => 'Proveedor B', 'identification' => '222', 'is_active' => true]);

        $p1 = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Prod 1', 'internal_code' => 'P1', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);
        $p2 = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Prod 2', 'internal_code' => 'P2', 'cost' => 15, 'sale_price' => 30,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 8, 'is_active' => true,
        ]);

        ProductSupplier::create(['company_id' => $company->id, 'product_id' => $p1->id, 'supplier_id' => $sup1->id, 'current_cost' => '10.0000', 'is_primary' => true, 'is_active' => true]);
        ProductSupplier::create(['company_id' => $company->id, 'product_id' => $p2->id, 'supplier_id' => $sup2->id, 'current_cost' => '15.0000', 'is_primary' => true, 'is_active' => true]);

        $this->stock($branch, $p1, '2');
        $this->stock($branch, $p2, '1');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $this->assertCount(2, $result['purchase_groups']);
        $supplierIds = array_column($result['purchase_groups'], 'supplier_id');
        $this->assertContains($sup1->id, $supplierIds);
        $this->assertContains($sup2->id, $supplierIds);
    }

    public function test_product_without_supplier_has_null_supplier_id(): void
    {
        [$company, $branch] = $this->ctxBranch('NoSup');

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Huerfano', 'internal_code' => 'HU', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        $this->stock($branch, $product, '2');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $this->assertEmpty($result['purchase_groups'], 'No supplier = no purchase group');
        $this->assertEquals(1, $result['summary']['without_supplier']);
    }

    // ── 5. TRANSFER PREFILL ───────────────────────────────────────

    public function test_transfer_create_prefill_loads_products(): void
    {
        [$company, $from, $to, $user] = $this->ctxMultiBranch(['dashboard.admin', 'inventario.transferir']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'PrefillProd', 'internal_code' => 'PF', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);
        $this->stock($from, $product, '20');

        $prefill = json_encode(['products' => [['product_id' => $product->id, 'quantity' => '5']]]);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $from))
            ->get(route('transferencias.create', ['prefill' => $prefill]));

        $response->assertOk();
        $response->assertSee('PrefillProd');
        $response->assertSee('5');

        // Verify no transfer was created
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    // ── 6. PURCHASE PREFILL ───────────────────────────────────────

    public function test_purchase_create_prefill_loads_supplier_and_items(): void
    {
        [$company, $branch, $user] = $this->ctx(['dashboard.admin', 'compras.crear']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Prefill Supplier', 'identification' => '999', 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'CompraProd', 'internal_code' => 'CP', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true,
        ]);

        $prefill = json_encode([
            'supplier_id' => $supplier->id,
            'supplier_name' => 'Prefill Supplier',
            'items' => [['product_id' => $product->id, 'name' => 'CompraProd', 'internal_code' => 'CP', 'quantity' => 10, 'unit_cost' => 10]],
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('compras.create', ['prefill' => $prefill]));

        $response->assertOk();
        $response->assertSee('window.purchaseEdit');
        $response->assertSee('Prefill Supplier');

        // Verify no purchase was created
        $this->assertDatabaseCount('purchases', 0);
    }

    // ── 7. EMPTY RESULT ───────────────────────────────────────────

    public function test_empty_when_no_shortages(): void
    {
        [$company, $branch] = $this->ctxBranch('FullCo');

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Sufficient', 'internal_code' => 'SF', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 5, 'is_active' => true,
        ]);
        $this->stock($branch, $product, '20');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $this->assertEquals(0, $result['summary']['shortage_count']);
        $this->assertEmpty($result['replenishment']);
        $this->assertEmpty($result['transfer_suggestions']);
    }

    public function test_empty_when_no_branch(): void
    {
        [$company] = $this->ctxBranch('NoBranch');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, null);

        $this->assertEquals(0, $result['summary']['shortage_count']);
        $this->assertArrayHasKey('empty_reason', $result);
    }

    // ── 8. CLIENTES / PORTAL — TESTS ─────────────────────────────

    public function test_new_customer_appears_in_control_center(): void
    {
        [$company, $branch, $user] = $this->ctx(['dashboard.admin']);

        Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'María Rodríguez', 'identification' => '123456789',
            'phone' => '83526142', 'phone_country_code' => '506',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $this->assertNotEmpty($result['customers']);
        $names = array_column($result['customers'], 'name');
        $this->assertContains('María Rodríguez', $names);
    }

    public function test_customer_from_other_company_does_not_appear(): void
    {
        [$company, $branch, $user] = $this->ctx(['dashboard.admin']);
        [$otherCompany, $otherBranch] = $this->ctxBranch('OtherCo');

        Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Cliente Propio', 'identification' => '111',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);
        Customer::create([
            'company_id' => $otherCompany->id, 'customer_type' => 'individual',
            'name' => 'Cliente Ajeno', 'identification' => '222',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $names = array_column($result['customers'], 'name');
        $this->assertContains('Cliente Propio', $names);
        $this->assertNotContains('Cliente Ajeno', $names);
    }

    public function test_customer_without_portal_is_identified(): void
    {
        [$company, $branch] = $this->ctxBranch('PortalTest');

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Sin Portal', 'identification' => '333',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $found = collect($result['customers'])->firstWhere('id', $customer->id);
        $this->assertNotNull($found);
        $this->assertEquals('sin_portal', $found['portal_status']['key']);
    }

    public function test_portal_created_does_not_mean_logged_in(): void
    {
        [$company, $branch] = $this->ctxBranch('PortalCreated');

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Credencial Sin Login', 'identification' => '444',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        DB::table('loyalty_portal_credentials')->insert([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'username' => 'cred'.$customer->id,
            'email' => 'cred'.$customer->id.'@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $found = collect($result['customers'])->firstWhere('id', $customer->id);
        $this->assertNotNull($found);
        $this->assertEquals('portal_creado', $found['portal_status']['key']);
        $this->assertNull($found['portal_status']['first_login_at']);
    }

    public function test_first_login_is_recorded(): void
    {
        [$company, $branch] = $this->ctxBranch('FirstLogin');

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Primer Login', 'identification' => '555',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $credentialId = DB::table('loyalty_portal_credentials')->insertGetId([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'username' => 'fl'.$customer->id,
            'email' => 'fl'.$customer->id.'@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('loyalty_portal_credentials')->where('id', $credentialId)->update([
            'last_login_at' => now(),
            'first_login_at' => now(),
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $found = collect($result['customers'])->firstWhere('id', $customer->id);
        $this->assertNotNull($found);
        $this->assertEquals('ya_ingreso', $found['portal_status']['key']);
        $this->assertNotNull($found['portal_status']['first_login_at']);
    }

    public function test_first_login_at_not_overwritten_on_second_login(): void
    {
        [$company, $branch] = $this->ctxBranch('NoOverwrite');

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'No Sobreescribir', 'identification' => '666',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $firstLogin = now()->subDays(10);
        DB::table('loyalty_portal_credentials')->insert([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'username' => 'no'.$customer->id,
            'email' => 'no'.$customer->id.'@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'first_login_at' => $firstLogin,
            'last_login_at' => $firstLogin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate second login — should NOT overwrite first_login_at
        $credential = DB::table('loyalty_portal_credentials')
            ->where('customer_id', $customer->id)->first();
        $update = ['last_login_at' => now()];
        if (is_null($credential->first_login_at)) {
            $update['first_login_at'] = now();
        }
        DB::table('loyalty_portal_credentials')->where('id', $credential->id)->update($update);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $found = collect($result['customers'])->firstWhere('id', $customer->id);
        $this->assertNotNull($found);
        $this->assertEquals($firstLogin->format('Y-m-d H:i:s'), Carbon::parse($found['portal_status']['first_login_at'])->format('Y-m-d H:i:s'));
    }

    public function test_last_login_continues_working(): void
    {
        [$company, $branch] = $this->ctxBranch('LastLogin');

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Último Acceso', 'identification' => '777',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $lastLogin = now()->subHours(3);
        DB::table('loyalty_portal_credentials')->insert([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'username' => 'll'.$customer->id,
            'email' => 'll'.$customer->id.'@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'first_login_at' => now()->subDays(5),
            'last_login_at' => $lastLogin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $found = collect($result['customers'])->firstWhere('id', $customer->id);
        $this->assertNotNull($found);
        $this->assertEquals($lastLogin->format('Y-m-d H:i:s'), Carbon::parse($found['portal_status']['last_login_at'])->format('Y-m-d H:i:s'));
    }

    public function test_whatsapp_with_valid_phone_generates_correct_link(): void
    {
        [$company, $branch] = $this->ctxBranch('WhatsappValid');

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'WhatsApp Cliente', 'identification' => '888',
            'phone' => '83526142', 'phone_country_code' => '506',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $found = collect($result['customers'])->firstWhere('id', $customer->id);
        $this->assertNotNull($found);
        $this->assertNotNull($found['whatsapp_url']);
        $this->assertStringContainsString('wa.me/50683526142', $found['whatsapp_url']);
        $this->assertStringContainsString('WhatsApp Cliente', rawurldecode($found['whatsapp_url']));
    }

    public function test_invalid_phone_does_not_generate_false_link(): void
    {
        [$company, $branch] = $this->ctxBranch('WhatsappInvalid');

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Sin Teléfono', 'identification' => '999',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $branch->id);

        $found = collect($result['customers'])->firstWhere('id', $customer->id);
        $this->assertNotNull($found);
        $this->assertNull($found['whatsapp_url']);
    }

    public function test_view_customer_links_to_correct_route(): void
    {
        [$company, $branch, $user] = $this->ctx(['dashboard.admin']);

        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Ver Cliente', 'identification' => '1010',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('control-center.index'))
            ->assertOk()
            ->assertSee(route('clientes.show', $customer->id));
    }

    public function test_permissions_respected_for_control_center(): void
    {
        [$company, $branch, $user] = $this->ctx(['dashboard.admin']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('control-center.index'))
            ->assertOk();
    }

    public function test_inventory_sections_still_function_with_customers(): void
    {
        [$company, $from, $to, $user] = $this->ctxMultiBranch(['dashboard.admin', 'inventario.transferir']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Prod Mix', 'internal_code' => 'PM', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        $this->stock($from, $product, '25');
        $this->stock($to, $product, '3');

        Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Cliente Test', 'identification' => '2020',
            'is_active' => true, 'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal',
        ]);

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, $to->id);

        // Inventario sigue funcionando
        $this->assertNotEmpty($result['transfer_suggestions']);
        $this->assertNotEmpty($result['customers']);

        // Vía HTTP
        $this->actingAs($user)
            ->withSession($this->activeSession($company, $to))
            ->get(route('control-center.index'))
            ->assertOk()
            ->assertSee('Centro de Control')
            ->assertSee('Cliente Test');
    }

    // ── 8. ENTERPRISE VIEW DESTINATION BRANCH ───────────────────

    public function test_enterprise_view_says_todas_las_sucursales(): void
    {
        [$company, $from, $to] = $this->ctxMultiBranch(['dashboard.admin']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Enterprise Test', 'internal_code' => 'ET', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        $this->stock($from, $product, '25');
        $this->stock($to, $product, '3');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, null);

        $this->assertEquals('Todas las sucursales', $result['branch_name']);
        $this->assertNull($result['branch_id']);
        $this->assertNotEmpty($result['transfer_suggestions']);
    }

    public function test_transfer_recommendation_never_uses_global_label_as_destination(): void
    {
        [$company, $from, $to] = $this->ctxMultiBranch(['dashboard.admin']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'No Global Dest', 'internal_code' => 'NGD', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        $this->stock($from, $product, '25');
        $this->stock($to, $product, '3');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, null);

        foreach ($result['transfer_suggestions'] as $suggestion) {
            $this->assertArrayHasKey('to_branch_name', $suggestion);
            $this->assertNotEquals('Todas las sucursales', $suggestion['to_branch_name']);
            $this->assertNotEquals('Todas las sucursales', $suggestion['from_branch_name']);
        }
    }

    public function test_transfer_destination_branch_id_corresponds_to_needy_branch(): void
    {
        [$company, $from, $to] = $this->ctxMultiBranch(['dashboard.admin']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Branch ID Test', 'internal_code' => 'BIT', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        // "from" has surplus, "to" is short
        $this->stock($from, $product, '25');
        $this->stock($to, $product, '3');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, null);

        $suggestion = collect($result['transfer_suggestions'])->firstWhere('product_id', $product->id);
        $this->assertNotNull($suggestion);
        $this->assertEquals($to->id, $suggestion['to_branch_id']);
        $this->assertEquals($to->name, $suggestion['to_branch_name']);
        $this->assertEquals($from->id, $suggestion['from_branch_id']);
        $this->assertEquals($from->name, $suggestion['from_branch_name']);
    }

    public function test_prefill_preserves_concrete_origin_and_destination(): void
    {
        [$company, $from, $to] = $this->ctxMultiBranch(['dashboard.admin']);

        $cat = ProductCategory::create(['company_id' => $company->id, 'name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'U', 'abbreviation' => 'U', 'slug' => 'u', 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'unit_id' => $unit->id,
            'name' => 'Prefill Test', 'internal_code' => 'PFT', 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'minimum_stock' => 10, 'is_active' => true,
        ]);

        $this->stock($from, $product, '25');
        $this->stock($to, $product, '3');

        $svc = app(ControlCenterService::class);
        $result = $svc->forCompany($company, null);

        $suggestion = collect($result['transfer_suggestions'])->firstWhere('product_id', $product->id);
        $this->assertNotNull($suggestion);

        // Verify the prefill URL in the view contains concrete IDs
        $prefill = json_encode([
            'products' => [['product_id' => $suggestion['product_id'], 'quantity' => $suggestion['suggested_quantity']]],
            'from_branch_id' => $suggestion['from_branch_id'],
            'to_branch_id' => $suggestion['to_branch_id'],
        ]);

        $this->assertEquals($from->id, $suggestion['from_branch_id']);
        $this->assertEquals($to->id, $suggestion['to_branch_id']);
        $this->assertStringNotContainsString('null', $prefill);
    }

    // ── HELPERS ───────────────────────────────────────────────────

    private function ctx(array $permissions): array
    {
        $suffix = bin2hex(random_bytes(4));
        $company = Company::create(['trade_name' => 'Co '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Branch '.$suffix, 'code' => strtoupper(substr($suffix, 0, 4)), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Role '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function ctxMultiBranch(array $permissions): array
    {
        [$company, $branch, $user] = $this->ctx($permissions);
        $branch2 = Branch::create(['company_id' => $company->id, 'name' => 'Branch2 '.$company->trade_name, 'code' => 'B2'.rand(1000,9999), 'is_active' => true]);
        $user->branches()->attach($branch2->id);

        return [$company, $branch, $branch2, $user];
    }

    private function ctxBranch(string $suffix): array
    {
        $company = Company::create(['trade_name' => $suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Branch '.$suffix, 'code' => strtoupper(substr($suffix, 0, 4)), 'is_active' => true]);

        return [$company, $branch];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function stock(Branch $branch, Product $product, string $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
            'minimum_stock' => null,
            'maximum_stock' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
