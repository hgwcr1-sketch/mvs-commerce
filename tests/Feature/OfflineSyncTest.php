<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\OfflineSyncOperation;
use App\Models\OfflineTerminal;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\OfflineAuthorizationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $testKeysDir;
    private string $testPrivateKeyPath;
    private string $testPublicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testKeysDir = storage_path('app/private/mvs-offline-test');
        $this->testPrivateKeyPath = $this->testKeysDir . '/private.pem';
        $this->testPublicKeyPath = $this->testKeysDir . '/public.pem';

        if (! File::isDirectory($this->testKeysDir)) {
            File::makeDirectory($this->testKeysDir, 0700, true, true);
        }

        $this->generateTestKeyPair();

        config([
            'offline.keys.private' => 'app/private/mvs-offline-test/private.pem',
            'offline.keys.public' => 'app/private/mvs-offline-test/public.pem',
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testKeysDir)) {
            File::deleteDirectory($this->testKeysDir);
        }

        parent::tearDown();
    }

    // =====================================================================
    // SUCCESS + IDEMPOTENCY
    // =====================================================================

    public function test_valid_offline_sale_syncs_to_official_sale(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processed')
            ->assertJsonStructure(['sale_id', 'sale_number']);

        $saleId = $response->json('sale_id');
        $this->assertNotNull($saleId);

        $sale = Sale::query()->where('id', $saleId)->where('company_id', $company->id)->firstOrFail();

        $this->assertSame(Sale::DOCUMENT_ELECTRONIC_TICKET, $sale->document_type);
        $this->assertSame(1, $sale->items()->count());
        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);

        $this->assertSame(1, $this->operationCount($company));
        $record = DB::table('offline_sync_operations')->where('company_id', $company->id)->first();
        $this->assertSame('synced', $record->status);
        $this->assertSame('offline_sale', $record->operation_type);
        $this->assertSame((string) $saleId, (string) $record->sale_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record->payload_hash);
    }

    public function test_same_operation_uuid_twice_returns_same_sale_single_record(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $uuid = (string) Str::uuid();
        $body = $this->payload($terminal, $token, $cashSession, $product, $method, ['operation_uuid' => $uuid]);

        $first = $this->postSync($company, $branch, $user, $body);
        $second = $this->postSync($company, $branch, $user, $body);

        $first->assertOk()->assertJsonPath('status', 'processed');
        $second->assertOk()->assertJsonPath('status', 'already_processed');

        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('offline_sync_operations')->count());
        $this->assertSame('synced', DB::table('offline_sync_operations')->where('operation_uuid', $uuid)->value('status'));
    }

    public function test_same_operation_uuid_ten_times_creates_single_sale(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $uuid = (string) Str::uuid();
        $body = $this->payload($terminal, $token, $cashSession, $product, $method, ['operation_uuid' => $uuid]);

        $firstSaleId = null;

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postSync($company, $branch, $user, $body);
            $response->assertOk();

            if ($firstSaleId === null) {
                $firstSaleId = $response->json('sale_id');
            } else {
                $this->assertSame($firstSaleId, $response->json('sale_id'));
            }
        }

        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('offline_sync_operations')->count());
    }

    public function test_operation_uuid_reused_with_different_payload_is_conflict(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $uuid = (string) Str::uuid();

        $first = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method, [
            'operation_uuid' => $uuid,
        ]));
        $first->assertOk();

        $second = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method, [
            'operation_uuid' => $uuid,
            'payload.payments.0.amount' => '2000',
        ]));

        $second->assertStatus(409);
        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());
        $this->assertSame('conflict', DB::table('offline_sync_operations')->where('operation_uuid', $uuid)->value('status'));
    }

    public function test_lost_ack_is_recovered_returning_same_sale(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $uuid = (string) Str::uuid();
        $body = $this->payload($terminal, $token, $cashSession, $product, $method, ['operation_uuid' => $uuid]);

        $first = $this->postSync($company, $branch, $user, $body);
        $first->assertOk();

        // Simular ACK perdido: la venta quedó registrada pero el registro sigue "processing".
        DB::table('offline_sync_operations')->where('operation_uuid', $uuid)
            ->update(['status' => 'processing', 'sale_id' => null]);

        $retry = $this->postSync($company, $branch, $user, $body);

        $retry->assertOk()
            ->assertJsonPath('status', 'already_processed')
            ->assertJsonPath('sale_id', $first->json('sale_id'));

        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());
        $this->assertSame('synced', DB::table('offline_sync_operations')->where('operation_uuid', $uuid)->value('status'));
    }

    public function test_retries_do_not_duplicate_loyalty_accrual(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $customer = $this->customer($company);

        LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '0.0100',
            'point_value' => '1.0000',
            'minimum_redemption_points' => '1.0000',
            'redemption_minimum_enabled' => false,
            'redemption_minimum_amount' => '0.0000',
            'maximum_redemption_percent' => '0.0000',
            'earn_on_offers' => false,
            'birthday_enabled' => false,
            'birthday_points' => '0.0000',
            'returning_customer_enabled' => false,
            'returning_customer_days' => 30,
            'returning_customer_points' => '0.0000',
            'redeem_on_offers' => false,
            'expiration_enabled' => false,
            'expiration_months' => 12,
        ]);

        $uuid = (string) Str::uuid();
        $body = $this->payload($terminal, $token, $cashSession, $product, $method, [
            'operation_uuid' => $uuid,
            'payload.customer_id' => $customer->id,
        ]);

        $first = $this->postSync($company, $branch, $user, $body);
        $first->assertOk();
        $second = $this->postSync($company, $branch, $user, $body);
        $second->assertOk();

        $sale = Sale::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('offline_sync_operations')->count());
        $this->assertSame(
            1,
            LoyaltyMovement::query()->where('event_key', "sale:{$sale->id}:loyalty:earn")->count(),
        );
    }

    // =====================================================================
    // CONTEXT / AUTHORIZATION REJECTIONS
    // =====================================================================

    public function test_company_context_mismatch_is_rejected(): void
    {
        [$companyA, $branchA, $userA, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        [$companyB, $branchB, $userB] = $this->tenant('Otra Empresa');

        $this->attachPermission($userB, $companyB, $branchB, ['pos.acceder', 'ventas.crear']);

        $response = $this->postSync($companyB, $branchB, $userB, $this->payload($terminal, $token, $cashSession, $product, $method));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    public function test_branch_context_mismatch_is_rejected(): void
    {
        [$company, $branchA, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $branchB = $this->branch($company, 'Sucursal B');
        $userB = $this->attachPermission($user, $company, $branchB, ['pos.acceder', 'ventas.crear']);
        $user = $userB;

        $response = $this->postSync($company, $branchB, $user, $this->payload($terminal, $token, $cashSession, $product, $method));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    public function test_terminal_context_mismatch_is_rejected(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $otherTerminal = $this->createActiveTerminal($company, $branch);

        $response = $this->postSync($company, $branch, $user, $this->payload($otherTerminal, $token, $cashSession, $product, $method));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    public function test_revoked_terminal_authorization_is_rejected(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();

        $terminal->update(['status' => OfflineTerminal::STATUS_REVOKED]);

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    public function test_invalid_authorization_token_is_rejected(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, 'token-invalido', $cashSession, $product, $method));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    // =====================================================================
    // VALIDATION
    // =====================================================================

    public function test_unsupported_operation_type_is_rejected(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method, [
            'operation_type' => 'offline_refund',
        ]));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    public function test_invalid_payload_version_is_rejected(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method, [
            'payload_version' => 0,
        ]));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    public function test_payload_without_items_is_rejected_without_creating_records(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method, [
            'payload.items' => [],
        ]));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    public function test_customer_from_another_company_is_rejected_without_partial_writes(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        [$otherCompany] = $this->tenant('Otra');
        $foreignCustomer = $this->customer($otherCompany);

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $product, $method, [
            'payload.customer_id' => $foreignCustomer->id,
        ]));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('sale_items')->count());
        $this->assertSame(0, DB::table('sale_payments')->count());
        $this->assertSame(1, DB::table('offline_sync_operations')->count());
        $this->assertSame('conflict', DB::table('offline_sync_operations')->value('status'));
    }

    public function test_business_rule_failure_rolls_back_atomically(): void
    {
        [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();
        $trackedProduct = $this->product($company, true, false, ['sale_price' => 1000, 'tax_rate' => 13]);
        $this->stock($branch, $trackedProduct, 123);

        // Pago insuficiente para cubrir el total -> la transacción del procesador se revierte.
        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $token, $cashSession, $trackedProduct, $method, [
            'payload.payments.0.amount' => '500',
            'payload.payments.0.received_amount' => '500',
        ]));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('sale_items')->count());
        $this->assertSame(0, DB::table('sale_payments')->count());
        $this->assertEquals(123, (int) round((float) DB::table('branch_product')->where('product_id', $trackedProduct->id)->value('stock')));
        $this->assertSame('conflict', DB::table('offline_sync_operations')->value('status'));
    }

    // =====================================================================
    // LATE SYNC WINDOW
    // =====================================================================

    public function test_creation_inside_window_can_sync_after_authorization_expired(): void
    {
        [$company, $branch, $user, $terminal, $cashSession, $product, $method] = $this->saleContextLite();

        $expiredToken = $this->signExpiredToken([
            'authorization_id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'issued_at' => now()->subHours(50)->toIso8601String(),
            'valid_until' => now()->subHours(2)->toIso8601String(),
            'license_status' => 'active',
        ]);

        $createdAtLocal = now()->subHours(49)->toISOString();

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $expiredToken, $cashSession, $product, $method, [
            'created_at_local' => $createdAtLocal,
        ]));

        $response->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());
        $this->assertSame('synced', DB::table('offline_sync_operations')->value('status'));
    }

    public function test_creation_after_expiration_is_rejected(): void
    {
        [$company, $branch, $user, $terminal, $cashSession, $product, $method] = $this->saleContextLite();

        $expiredToken = $this->signExpiredToken([
            'authorization_id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'issued_at' => now()->subHours(50)->toIso8601String(),
            'valid_until' => now()->subHours(2)->toIso8601String(),
            'license_status' => 'active',
        ]);

        // Operación creada DESPUÉS de vencer la autorización -> no puede fabricarse.
        $createdAtLocal = now()->subMinutes(30)->toISOString();

        $response = $this->postSync($company, $branch, $user, $this->payload($terminal, $expiredToken, $cashSession, $product, $method, [
            'created_at_local' => $createdAtLocal,
        ]));

        $response->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    // =====================================================================
    // DATABASE-LEVEL IDEMPOTENCY
    // =====================================================================

    public function test_unique_operation_uuid_is_enforced_at_database_level(): void
    {
        [$company, $branch, $user] = $this->tenant('Empresa');

        $uuid = (string) Str::uuid();
        $this->createOperationRow($uuid, $company, $branch, $user);

        $this->expectException(QueryException::class);
        $this->createOperationRow($uuid, $company, $branch, $user);
    }

    // =====================================================================
    // PERMISSIONS
    // =====================================================================

    public function test_sync_requires_ventas_crear_permission(): void
    {
        [$company, $branch, $userWithoutPermission] = $this->tenant('Sin Permiso');
        [$company2, $branch2, $user2, $terminal, $token, $cashSession, $product, $method] = $this->saleContext();

        $body = $this->payload($terminal, $token, $cashSession, $product, $method);

        $response = $this->actingAs($userWithoutPermission)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('offline.sync'), $body);

        $response->assertForbidden();
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('offline_sync_operations')->count());
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function totalForProduct(Product $product): string
    {
        $unitPrice = (float) $product->sale_price;
        $taxRate = (float) ($product->tax_rate ?? 0);

        return (string) (int) round($unitPrice * (1 + ($taxRate / 100)), 0, PHP_ROUND_HALF_UP);
    }

    private function payload(
        OfflineTerminal $terminal,
        string $token,
        CashSession $cashSession,
        Product $product,
        PaymentMethod $method,
        array $overrides = [],
    ): array {
        $total = $this->totalForProduct($product);

        $base = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => OfflineSyncOperation::OPERATION_TYPE_OFFLINE_SALE,
            'terminal_uuid' => $terminal->terminal_uuid,
            'payload_version' => 1,
            'created_at_local' => now()->toISOString(),
            'payload' => [
                'payload_version' => 1,
                'document_type' => 'ticket',
                'cash_session_id' => $cashSession->id,
                'customer_id' => null,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => '1',
                        'discount' => '0',
                        'discount_type' => 'fixed',
                        'unit_price' => null,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $method->id,
                        'amount' => $total,
                        'received_amount' => $total,
                        'received_amount_usd' => null,
                        'change_currency' => null,
                        'reference' => null,
                    ],
                ],
            ],
            'authorization' => $token,
        ];

        foreach ($overrides as $key => $value) {
            $keys = explode('.', $key);
            $ref = &$base;
            while (count($keys) > 1) {
                $k = array_shift($keys);
                if (! isset($ref[$k])) {
                    $ref[$k] = [];
                }
                $ref = &$ref[$k];
            }
            $ref[array_shift($keys)] = $value;
        }

        return $base;
    }

    private function postSync(Company $company, Branch $branch, User $user, array $body)
    {
        return $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('offline.sync'), $body);
    }

    private function saleContext(): array
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $user = $this->attachPermission($user, $company, $branch, ['pos.acceder', 'ventas.crear']);

        $terminal = $this->createActiveTerminal($company, $branch);
        $token = app(OfflineAuthorizationService::class)->authorize($terminal, $user)['authorization'];
        $cashSession = $this->cashSession($company, $branch, $user);
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);
        $method = $this->paymentMethod($company);

        return [$company, $branch, $user, $terminal, $token, $cashSession, $product, $method];
    }

    private function saleContextLite(): array
    {
        [$company, $branch, $user] = $this->tenant('Empresa');
        $user = $this->attachPermission($user, $company, $branch, ['pos.acceder', 'ventas.crear']);

        $terminal = $this->createActiveTerminal($company, $branch);
        $cashSession = $this->cashSession($company, $branch, $user);
        $product = $this->product($company, false, false, ['sale_price' => 1000, 'tax_rate' => 13]);
        $method = $this->paymentMethod($company);

        return [$company, $branch, $user, $terminal, $cashSession, $product, $method];
    }

    private function tenant(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = $this->branch($company, 'Principal');
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

    private function attachPermission(User $user, Company $company, Branch $branch, array $permissions): User
    {
        $role = Role::where('company_id', $company->id)->firstOrFail();

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'POS', 'is_active' => true],
            );
            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user->companies()->syncWithoutDetaching([$company->id => ['role_id' => $role->id]]);
        $user->branches()->syncWithoutDetaching([$branch->id]);

        return $user;
    }

    private function createActiveTerminal(Company $company, Branch $branch): OfflineTerminal
    {
        return OfflineTerminal::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => (string) Str::uuid(),
            'status' => OfflineTerminal::STATUS_ACTIVE,
        ]);
    }

    private function cashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $register = CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'CAJA-'.uniqid(),
            'name' => 'Caja',
            'is_active' => true,
        ]);

        return CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CAJA-'.uniqid(),
            'opened_by' => $user->id,
            'status' => CashSession::STATUS_OPEN,
            'open_guard' => CashSession::OPEN_GUARD,
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);
    }

    private function paymentMethod(Company $company, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'company_id' => $company->id,
            'code' => 'cash-'.uniqid(),
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_active' => true,
            'allows_change' => true,
        ], $attributes));
    }

    private function product(Company $company, bool $tracked, bool $decimals = false, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => $decimals, 'is_active' => true]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 1000,
            'stock' => 123,
            'tax_rate' => 13,
            'track_inventory' => $tracked,
            'is_active' => true,
        ], $attributes));
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente '.uniqid(),
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => $name.'-'.$company->id.'-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function createOperationRow(string $operationUuid, Company $company, Branch $branch, User $user): void
    {
        OfflineSyncOperation::create([
            'operation_uuid' => $operationUuid,
            'operation_type' => 'offline_sale',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'payload_version' => '1',
            'payload' => ['items' => [], 'payments' => []],
            'payload_hash' => str_repeat('a', 64),
            'created_at_local' => now(),
            'status' => 'received',
        ]);
    }

    private function operationCount(Company $company): int
    {
        return DB::table('offline_sync_operations')->where('company_id', $company->id)->count();
    }

    private function signExpiredToken(array $payload): string
    {
        $service = app(OfflineAuthorizationService::class);
        $method = new \ReflectionMethod(OfflineAuthorizationService::class, 'signToken');
        $method->setAccessible(true);

        return $method->invoke($service, $payload);
    }

    private function generateTestKeyPair(): void
    {
        $opensslConfigPath = $this->findOpenSslConfigPath();
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if ($opensslConfigPath) {
            $config['config'] = $opensslConfigPath;
        }

        $res = openssl_pkey_new($config);
        $this->assertNotFalse($res, 'Failed to generate test RSA key pair');

        $exportConfig = $opensslConfigPath ? ['config' => $opensslConfigPath] : [];
        $privateKeyContent = '';
        openssl_pkey_export($res, $privateKeyContent, null, $exportConfig);
        File::put($this->testPrivateKeyPath, $privateKeyContent);

        $details = openssl_pkey_get_details($res);
        File::put($this->testPublicKeyPath, $details['key']);

        openssl_free_key($res);
    }

    private function findOpenSslConfigPath(): ?string
    {
        $defaultPath = 'C:\\Program Files\\Common Files\\SSL\\openssl.cnf';
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        $bundledPath = base_path('storage/app/openssl.cnf');
        if (file_exists($bundledPath)) {
            return $bundledPath;
        }

        $alternatives = [
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf',
            'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf',
        ];
        foreach ($alternatives as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}