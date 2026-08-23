<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRewardRedemption;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyRewardRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoyaltyRewardRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlimited_redemption_creates_history_and_movement(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);

        $redemption = $this->service()->redeem($customer, $this->reward($company, 'unlimited'), $company, $branch, $user, ['event_key' => 'k1']);

        $this->assertSame('100.0000', $redemption->points_cost);
        $this->assertSame('Premio base', $redemption->reward_name);
        $this->assertSame($customer->id, $redemption->customer_id);
        $this->assertSame($user->id, $redemption->user_id);
        $this->assertSame($branch->id, $redemption->branch_id);
        $this->assertNotNull($redemption->loyalty_movement_id);

        $account = LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('400.0000', (string) $account->fresh()->balance);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_limited_redemption_consumes_exactly_one_unit_each_time(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        $reward = $this->reward($company, 'limited', ['stock_quantity' => '2.5000']);

        $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'l1']);
        $this->assertSame('1.5000', (string) $reward->fresh()->stock_quantity);

        $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'l2']);
        $this->assertSame('0.5000', (string) $reward->fresh()->stock_quantity);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'l3']);
    }

    public function test_limited_exhausted_reward_blocks_without_mutations(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        $reward = $this->reward($company, 'limited', ['stock_quantity' => '0.0000']);

        try {
            $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'lx']);
            $this->fail('El canje debió bloquearse.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(['reward' => ['El premio no tiene cupo disponible.']], $e->errors());
        }

        $this->assertDatabaseCount('loyalty_reward_redemptions', 0);
        $this->assertSame(1.0, $this->accountBalance($customer, $company) / 500);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_product_redemption_posts_inventory_through_official_service(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        $product = $this->product($company);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 3.0, 'created_at' => now(), 'updated_at' => now()]);
        $reward = $this->reward($company, 'product', ['product_id' => $product->id]);

        $redemption = $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'p1']);

        $this->assertSame(2.0, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));

        $movement = InventoryMovement::query()->sole();
        $this->assertSame('reward_redemption', $movement->type);
        $this->assertSame('Salida por canje de premio', $movement->reason);
        $this->assertSame(LoyaltyRewardRedemption::class, $movement->reference_type);
        $this->assertSame($redemption->id, $movement->reference_id);
        $this->assertEquals(1, (float) $movement->quantity);
        $this->assertSame($product->id, $movement->product_id);
    }

    public function test_product_without_stock_blocks_and_rolls_back_everything(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        $product = $this->product($company);
        $reward = $this->reward($company, 'product', ['product_id' => $product->id]);

        try {
            $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'px']);
            $this->fail('El canje debió bloquearse por falta de stock.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame('El premio no tiene existencias disponibles en esta sucursal.', $e->errors()['reward'][0]);
        }

        $this->assertDatabaseCount('loyalty_reward_redemptions', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame('500.0000', $this->rawBalance($customer, $company));
    }

    public function test_insufficient_points_block_without_mutations(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(50);
        $reward = $this->reward($company, 'unlimited');

        try {
            $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'ix']);
            $this->fail('El canje debió bloquearse por saldo insuficiente.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('points', $e->errors());
        }

        $this->assertDatabaseCount('loyalty_reward_redemptions', 0);
        $this->assertDatabaseCount('loyalty_movements', 1); // solo la carga inicial
    }

    public function test_inactive_reward_blocks(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        $reward = $this->reward($company, 'unlimited', ['is_active' => false]);

        try {
            $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'ia']);
            $this->fail('El canje debió bloquearse por premio inactivo.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame('El premio está inactivo.', $e->errors()['reward'][0]);
        }
        $this->assertDatabaseCount('loyalty_reward_redemptions', 0);
    }

    public function test_cross_company_reward_customer_or_branch_is_blocked(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        [$other] = $this->context();

        foreach ([
            'recompensa ajena' => fn () => $this->service()->redeem($customer, $this->reward($other, 'unlimited'), $company, $branch, $user, ['event_key' => 'cx1']),
            'cliente ajeno' => fn () => $this->service()->redeem($other ? $this->customerOf($other) : $customer, $this->reward($company, 'unlimited'), $company, $branch, $user, ['event_key' => 'cx2']),
            'sucursal ajena' => fn () => $this->service()->redeem($customer, $this->reward($company, 'unlimited'), $company, Branch::create(['company_id' => $other->id, 'name' => 'Otra', 'code' => 'OB'.uniqid(), 'is_active' => true]), $user, ['event_key' => 'cx3']),
        ] as $case) {
            try {
                $case();
                $this->fail('El canje cross-company debió bloquearse.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('loyalty_reward_redemptions', 0);
    }

    public function test_kardex_and_redemption_record_match_with_snapshots(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        $reward = $this->reward($company, 'unlimited');

        $redemption = $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'k9']);

        $movement = $redemption->loyaltyMovement()->firstOrFail();
        $this->assertSame('-100.0000', (string) $movement->points);
        $this->assertSame('reward', $movement->type);
        $this->assertSame($redemption->points_cost, ltrim((string) $movement->points, '-'));
        $this->assertSame($this->rawBalance($customer, $company), (string) $movement->balance_after);
        $this->assertSame(LoyaltyRewardRedemption::class, $movement->source_type);
        $this->assertSame($redemption->id, (int) $movement->source_id);

        $account = LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame((string) $account->balance, (string) $movement->balance_after);
        $this->assertSame('100.0000', (string) $account->total_redeemed);

        // El snapshot histórico no cambia aunque el premio cambie después.
        $reward->update(['name' => 'Premio renombrado', 'points_cost' => '999.0000']);
        $redemption->refresh();
        $this->assertSame('Premio base', $redemption->reward_name);
        $this->assertSame('100.0000', $redemption->points_cost);
    }

    public function test_same_event_key_is_fully_idempotent(): void
    {
        [$company, $branch, $customer, $user] = $this->fundedContext(500);
        $reward = $this->reward($company, 'limited', ['stock_quantity' => '5.0000']);

        $first = $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'same-key']);
        $second = $this->service()->redeem($customer, $reward, $company, $branch, $user, ['event_key' => 'same-key']);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('loyalty_reward_redemptions', 1);
        $this->assertSame('4.0000', (string) $reward->fresh()->stock_quantity);
        $this->assertSame('400.0000', $this->rawBalance($customer, $company));

        $rewardMovements = DB::table('loyalty_movements')->where('type', 'reward')->count();
        $this->assertSame(1, $rewardMovements);
    }

    public function test_http_flow_requires_permission_isolates_company_and_renders_history(): void
    {
        [$company, $branch, $customer, , $session] = $this->httpContext();
        $reward = $this->reward($company, 'unlimited');
        [$otherCompany, $otherBranch, $otherCustomer] = $this->context();

        $without = $this->user($company, $branch, []);
        $this->actingAs($without)->withSession($session)->get(route('loyalty.redemptions.index'))->assertForbidden();
        $this->actingAs($without)->withSession($session)->post(route('loyalty.redemptions.store'), ['customer_id' => $customer->id, 'reward_id' => $reward->id])->assertForbidden();

        $user = $this->user($company, $branch, ['fidelidad.canjes']);
        $payload = ['customer_id' => $customer->id, 'reward_id' => $reward->id];
        $this->actingAs($user)->withSession(array_merge($session, ['_old_input' => []]))
            ->post(route('loyalty.redemptions.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();

        // Premio y cliente de otra empresa son rechazados por las reglas validadas.
        $this->actingAs($user)->withSession($session)->post(route('loyalty.redemptions.store'), [
            'customer_id' => $otherCustomer->id, 'reward_id' => $reward->id,
        ])->assertSessionHasErrors('customer_id');
        $this->actingAs($user)->withSession($session)->post(route('loyalty.redemptions.store'), [
            'customer_id' => $customer->id, 'reward_id' => $this->reward($otherCompany, 'unlimited')->id,
        ])->assertSessionHasErrors('reward_id');

        $response = $this->actingAs($user)->withSession($session)->get(route('loyalty.redemptions.index'));
        $response->assertOk()->assertSee('Premio base')->assertDontSee('Cliente ajeno');
    }

    private function service(): LoyaltyRewardRedemptionService
    {
        return app(LoyaltyRewardRedemptionService::class);
    }

    private function fundedContext(float $points): array
    {
        [$company, $branch, $customer] = $this->context();
        $user = User::factory()->create();
        $accounts = app(LoyaltyAccountService::class);
        $account = $accounts->getOrCreateAccount($customer, $company, $user);
        if ($points > 0) {
            $accounts->addPoints($account, number_format($points, 4, '.', ''), \App\Models\LoyaltyMovement::TYPE_PURCHASE, [
                'branch' => $branch,
                'user' => $user,
                'base_amount' => number_format($points * 20, 4, '.', ''),
                'earning_percentage' => '5.0000',
                'point_value' => '1.0000',
                'description' => 'Carga inicial de prueba',
                'event_key' => 'seed:'.$customer->id.':'.uniqid(),
            ]);
        }

        return [$company, $branch, $customer, $user];
    }

    private function httpContext(): array
    {
        [$company, $branch, $customer] = $this->context();
        $user = $this->user($company, $branch, ['fidelidad.canjes']);
        $session = $this->activeSession($company, $branch);
        $this->fund($customer, $company, $branch, 300);

        return [$company, $branch, $customer, $user, $session];
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.substr(uniqid(), -4), 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'earn_on_offers' => false]);

        return [$company, $branch, $customer];
    }

    private function customerOf(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'name' => 'Cliente ajeno', 'customer_type' => 'individual', 'is_active' => true]);
    }

    private function fund(Customer $customer, Company $company, Branch $branch, float $points): void
    {
        $accounts = app(LoyaltyAccountService::class);
        $account = $accounts->getOrCreateAccount($customer, $company);
        $accounts->addPoints($account, number_format($points, 4, '.', ''), \App\Models\LoyaltyMovement::TYPE_PURCHASE, [
            'branch' => $branch,
            'base_amount' => number_format($points * 20, 4, '.', ''),
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'description' => 'Carga inicial de prueba',
            'event_key' => 'seed:'.$customer->id.':'.uniqid(),
        ]);
    }

    private function reward(Company $company, string $mode, array $extra = []): LoyaltyReward
    {
        return LoyaltyReward::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Premio base',
            'type' => 'gift',
            'points_cost' => '100.0000',
            'is_active' => true,
            'availability_mode' => $mode,
        ], $extra));
    }

    private function product(Company $company): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoria '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function accountBalance(Customer $customer, Company $company): float
    {
        return (float) $this->rawBalance($customer, $company);
    }

    private function rawBalance(Customer $customer, Company $company): string
    {
        return (string) LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->value('balance');
    }
}
