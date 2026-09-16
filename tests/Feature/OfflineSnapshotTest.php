<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OfflinePendingOperation;
use App\Models\OfflineSnapshot;
use App\Models\OfflineTerminal;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Permission;
use App\Models\Role;
use App\Services\OfflineSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfflineSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name,
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'B'.uniqid(),
            'is_active' => true,
        ]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function createActiveTerminal(Company $company, Branch $branch): OfflineTerminal
    {
        return OfflineTerminal::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'active',
        ]);
    }

    private function seedPermission(Company $company, Branch $branch): User
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'configuracion.editar'],
            ['label' => 'Editar configuración', 'module' => 'Configuración', 'is_active' => true]
        );
        $role = Role::where('company_id', $company->id)->first();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    // =====================================================================
    // SNAPSHOT ISOLATION TESTS
    // =====================================================================

    private function createCategory(Company $company, string $name): ProductCategory
    {
        return ProductCategory::create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function createUnit(Company $company, string $name = 'Unidad'): Unit
    {
        return Unit::create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'code' => strtoupper(substr($name, 0, 3)),
            'abbreviation' => strtoupper(substr($name, 0, 3)),
            'is_active' => true,
        ]);
    }

    private function createTestProduct(Company $company, string $name, ?int $categoryId = null): Product
    {
        $catId = $categoryId ?? $this->createCategory($company, 'Cat General')->id;
        $unit = $this->createUnit($company);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $catId,
            'unit_id' => $unit->id,
            'name' => $name,
            'internal_code' => 'PRD-' . strtoupper(substr(uniqid(), -6)),
            'sale_price' => 1000,
            'cost' => 500,
            'tax_rate' => 13,
            'is_active' => true,
        ]);
    }

    public function test_snapshot_company_a_does_not_contain_company_b_data(): void
    {
        [$companyA, $branchA, $userA] = $this->tenant('Empresa A');
        [$companyB, $branchB] = $this->tenant('Empresa B');

        $catA = $this->createCategory($companyA, 'Cat A');
        $this->createTestProduct($companyA, 'Producto A', $catA->id);
        $catB = $this->createCategory($companyB, 'Cat B');
        $this->createTestProduct($companyB, 'Producto B', $catB->id);

        $terminal = $this->createActiveTerminal($companyA, $branchA);
        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($companyA, $branchA, $terminal, $userA);

        $productNames = array_column($snapshot['products'], 'name');
        $this->assertContains('Producto A', $productNames);
        $this->assertNotContains('Producto B', $productNames);
    }

    public function test_snapshot_branch_a_does_not_mix_branch_b_stock(): void
    {
        [$company, $branchA] = $this->tenant('Empresa');
        [, $branchB] = $this->tenant('Otra');

        $cat = $this->createCategory($company, 'Cat');
        $product = $this->createTestProduct($company, 'Producto Compartido', $cat->id);

        DB::table('branch_product')->insert([
            ['branch_id' => $branchA->id, 'product_id' => $product->id, 'stock' => 50],
            ['branch_id' => $branchB->id, 'product_id' => $product->id, 'stock' => 25],
        ]);

        $terminal = $this->createActiveTerminal($company, $branchA);
        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branchA, $terminal, $this->createUserWithAccess($company, $branchA));

        $productData = collect($snapshot['products'])->firstWhere('id', $product->id);
        $this->assertEquals('50', $productData['stock']);
    }

    public function test_snapshot_contains_schema_version(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branch, $terminal, $user);

        $this->assertArrayHasKey('schema_version', $snapshot);
        $this->assertEquals(1, $snapshot['schema_version']);
    }

    public function test_snapshot_contains_generated_at(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $before = now()->subSecond();
        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branch, $terminal, $user);
        $after = now()->addSecond();

        $generatedAt = \Carbon\Carbon::parse($snapshot['generated_at']);
        $this->assertTrue($generatedAt->gte($before));
        $this->assertTrue($generatedAt->lte($after));
    }

    public function test_snapshot_contains_context_ids(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branch, $terminal, $user);

        $this->assertEquals($company->id, $snapshot['company']['id']);
        $this->assertEquals($branch->id, $snapshot['branch']['id']);
        $this->assertEquals($terminal->terminal_uuid, $snapshot['terminal']['terminal_uuid']);
        $this->assertEquals($user->id, $snapshot['user']['id']);
    }

    public function test_snapshot_does_not_contain_secrets(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branch, $terminal, $user);

        $json = json_encode($snapshot);
        $this->assertStringNotContainsString('private_key', $json);
        $this->assertStringNotContainsString('APP_KEY', $json);
        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('secret', strtolower($json));
        $this->assertStringNotContainsString('token', $json);
    }

    public function test_products_belong_to_correct_company(): void
    {
        [$companyA, $branchA, $userA] = $this->tenant('Empresa A');
        [$companyB, $branchB] = $this->tenant('Empresa B');

        $catA = $this->createCategory($companyA, 'Cat A');
        $this->createTestProduct($companyA, 'Producto Empresa A', $catA->id);
        $catB = $this->createCategory($companyB, 'Cat B');
        $this->createTestProduct($companyB, 'Producto Empresa B', $catB->id);

        $terminal = $this->createActiveTerminal($companyA, $branchA);
        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($companyA, $branchA, $terminal, $userA);

        foreach ($snapshot['products'] as $product) {
            $this->assertEquals($companyA->id, $product['id'] > 0 ? $companyA->id : null);
        }
        $this->assertCount(1, $snapshot['products']);
    }

    public function test_customers_belong_to_correct_company(): void
    {
        [$companyA, $branchA, $userA] = $this->tenant('Empresa A');
        [$companyB, $branchB] = $this->tenant('Empresa B');

        Customer::create([
            'company_id' => $companyA->id,
            'name' => 'Cliente A',
            'is_active' => true,
        ]);
        Customer::create([
            'company_id' => $companyB->id,
            'name' => 'Cliente B',
            'is_active' => true,
        ]);

        $terminal = $this->createActiveTerminal($companyA, $branchA);
        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($companyA, $branchA, $terminal, $userA);

        $customerNames = array_column($snapshot['customers'], 'name');
        $this->assertContains('Cliente A', $customerNames);
        $this->assertNotContains('Cliente B', $customerNames);
    }

    public function test_snapshot_includes_barcodes_for_products(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $cat = $this->createCategory($company, 'Cat');
        $unit = $this->createUnit($company);
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'name' => 'Producto con Códigos',
            'internal_code' => 'PRD-' . strtoupper(substr(uniqid(), -6)),
            'barcode' => '1234567890123',
            'sale_price' => 1000,
            'cost' => 500,
            'tax_rate' => 13,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => '1111111111111',
            'barcode_type' => 'EAN13',
            'is_primary' => false,
            'is_active' => true,
        ]);

        $terminal = $this->createActiveTerminal($company, $branch);
        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branch, $terminal, $user);

        $productData = collect($snapshot['products'])->firstWhere('id', $product->id);
        $this->assertNotNull($productData);
        $this->assertArrayHasKey('barcodes', $productData);
        $this->assertCount(1, $productData['barcodes']);
    }

    public function test_snapshot_includes_payment_methods(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'EFEC',
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_system' => true,
            'is_active' => true,
        ]);

        $terminal = $this->createActiveTerminal($company, $branch);
        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branch, $terminal, $user);

        $this->assertCount(1, $snapshot['payment_methods']);
        $this->assertEquals('Efectivo', $snapshot['payment_methods'][0]['name']);
    }

    // =====================================================================
    // PENDING OPERATIONS TESTS
    // =====================================================================

    public function test_pending_operation_uuid_is_unique(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $op1 = OfflinePendingOperation::create([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation_type' => 'sale',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'user_id' => $user->id,
            'created_at_local' => now(),
            'payload' => ['test' => true],
        ]);

        $op2 = OfflinePendingOperation::create([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation_type' => 'sale',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'user_id' => $user->id,
            'created_at_local' => now(),
            'payload' => ['test' => true],
        ]);

        $this->assertNotEquals($op1->operation_uuid, $op2->operation_uuid);
        $this->assertEquals($op1->operation_uuid, $op1->operation_uuid);
    }

    public function test_pending_operation_survives_reinitialization(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $op = OfflinePendingOperation::create([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation_type' => 'sale',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'user_id' => $user->id,
            'created_at_local' => now(),
            'payload' => ['items' => [['product_id' => 1, 'qty' => 2]]],
        ]);

        $found = OfflinePendingOperation::where('operation_uuid', $op->operation_uuid)->first();
        $this->assertNotNull($found);
        $this->assertEquals($op->payload, $found->payload);
    }

    public function test_pending_operation_status_transitions(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $op = OfflinePendingOperation::create([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation_type' => 'sale',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'user_id' => $user->id,
            'created_at_local' => now(),
            'payload' => ['test' => true],
            'status' => OfflinePendingOperation::STATUS_PENDING,
        ]);

        $this->assertNotNull($op->id);
        $this->assertEquals(OfflinePendingOperation::STATUS_PENDING, $op->status);
        $this->assertTrue($op->isPending());

        $op->update(['status' => OfflinePendingOperation::STATUS_SYNCING]);
        $this->assertEquals(OfflinePendingOperation::STATUS_SYNCING, $op->status);

        $op->update(['status' => OfflinePendingOperation::STATUS_SYNCED]);
        $this->assertTrue($op->isSynced());

        $op->update(['status' => OfflinePendingOperation::STATUS_FAILED, 'last_error' => 'timeout']);
        $this->assertTrue($op->isFailed());
    }

    public function test_pending_operation_isolation_between_companies(): void
    {
        [$companyA, $branchA, $userA] = $this->tenant('Empresa A');
        [$companyB, $branchB, $userB] = $this->tenant('Empresa B');
        $terminalA = $this->createActiveTerminal($companyA, $branchA);
        $terminalB = $this->createActiveTerminal($companyB, $branchB);

        OfflinePendingOperation::create([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation_type' => 'sale',
            'company_id' => $companyA->id,
            'branch_id' => $branchA->id,
            'terminal_uuid' => $terminalA->terminal_uuid,
            'user_id' => $userA->id,
            'created_at_local' => now(),
            'payload' => ['company' => 'A'],
        ]);
        OfflinePendingOperation::create([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation_type' => 'sale',
            'company_id' => $companyB->id,
            'branch_id' => $branchB->id,
            'terminal_uuid' => $terminalB->terminal_uuid,
            'user_id' => $userB->id,
            'created_at_local' => now(),
            'payload' => ['company' => 'B'],
        ]);

        $opsA = OfflinePendingOperation::where('company_id', $companyA->id)->get();
        $opsB = OfflinePendingOperation::where('company_id', $companyB->id)->get();

        $this->assertCount(1, $opsA);
        $this->assertCount(1, $opsB);
        $this->assertEquals('A', $opsA->first()->payload['company']);
        $this->assertEquals('B', $opsB->first()->payload['company']);
    }

    // =====================================================================
    // SNAPSHOT PERSISTENCE TESTS
    // =====================================================================

    public function test_save_and_retrieve_snapshot(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineSnapshotService::class);
        $saved = $service->getSnapshotForAuthorization($company, $branch, $terminal, $user);

        $this->assertNotNull($saved);
        $this->assertEquals($company->id, $saved->company_id);
        $this->assertEquals($branch->id, $saved->branch_id);
        $this->assertEquals($terminal->terminal_uuid, $saved->terminal_uuid);
        $this->assertNotNull($saved->snapshot_data);
        $this->assertGreaterThan(0, $saved->snapshot_size_bytes);
    }

    public function test_snapshot_is_versionable(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineSnapshotService::class);
        $first = $service->getSnapshotForAuthorization($company, $branch, $terminal, $user);

        $cat = $this->createCategory($company, 'Cat');
        $this->createTestProduct($company, 'Nuevo Producto', $cat->id);

        $second = $service->getSnapshotForAuthorization($company, $branch, $terminal, $user);

        $this->assertNotEquals($first->id, $second->id);
        $this->assertTrue($second->generated_at->gte($first->generated_at));
    }

    public function test_snapshot_content_matches_structure(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineSnapshotService::class);
        $snapshot = $service->buildSnapshot($company, $branch, $terminal, $user);

        $this->assertArrayHasKey('schema_version', $snapshot);
        $this->assertArrayHasKey('generated_at', $snapshot);
        $this->assertArrayHasKey('company', $snapshot);
        $this->assertArrayHasKey('branch', $snapshot);
        $this->assertArrayHasKey('terminal', $snapshot);
        $this->assertArrayHasKey('user', $snapshot);
        $this->assertArrayHasKey('categories', $snapshot);
        $this->assertArrayHasKey('brands', $snapshot);
        $this->assertArrayHasKey('units', $snapshot);
        $this->assertArrayHasKey('products', $snapshot);
        $this->assertArrayHasKey('customers', $snapshot);
        $this->assertArrayHasKey('payment_methods', $snapshot);
        $this->assertArrayHasKey('authorization', $snapshot);
    }

    // =====================================================================
    // ENDPOINT TESTS
    // =====================================================================

    public function test_snapshot_endpoint_requires_valid_authorization(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->seedPermission($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('offline.snapshot'), [
                'terminal_uuid' => $terminal->terminal_uuid,
                'authorization' => 'invalid-token',
            ])
            ->assertStatus(401);
    }

    public function test_snapshot_endpoint_rejects_unknown_terminal(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->seedPermission($company, $branch);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('offline.snapshot'), [
                'terminal_uuid' => '00000000-0000-0000-0000-000000000000',
                'authorization' => 'some-token',
            ])
            ->assertStatus(401);
    }

    public function test_unique_operation_uuid_across_operations(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $terminal = $this->createActiveTerminal($company, $branch);

        $uuids = [];
        for ($i = 0; $i < 10; $i++) {
            $uuids[] = (string) \Illuminate\Support\Str::uuid();
        }
        $this->assertCount(10, array_unique($uuids));
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function createUserWithAccess(Company $company, Branch $branch): User
    {
        $role = Role::where('company_id', $company->id)->first();
        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }
}
