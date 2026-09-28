<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRewardAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoyaltyRewardAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_infrastructure_columns_defaults_and_casts(): void
    {
        [$company, $branch] = $this->context();
        foreach (['availability_mode', 'stock_quantity', 'product_id'] as $column) {
            $this->assertTrue(DB::getSchemaBuilder()->hasColumn('loyalty_rewards', $column), "Falta la columna {$column}.");
        }

        $reward = LoyaltyReward::create(['company_id' => $company->id, 'name' => 'Base', 'type' => 'gift', 'points_cost' => '10.0000', 'is_active' => true]);
        $this->assertSame('unlimited', $reward->fresh()->availability_mode);
        $this->assertNull($reward->fresh()->stock_quantity);
        $this->assertNull($reward->fresh()->product_id);

        $limited = LoyaltyReward::create(['company_id' => $company->id, 'name' => 'Cupo 2.5', 'type' => 'gift', 'points_cost' => '10.0000', 'is_active' => true, 'availability_mode' => 'limited', 'stock_quantity' => '2.5']);
        $this->assertSame('2.5000', $limited->fresh()->stock_quantity);
    }

    public function test_validation_enforces_rules_for_each_availability_mode(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['fidelidad.premios']);
        $session = $this->activeSession($company, $branch);
        $product = $this->product($company);
        $foreignProduct = $this->product($this->context()[0]);
        $base = fn (array $extra) => array_merge(['name' => 'Premio modo', 'type' => 'gift', 'points_cost' => '20'], $extra);

        $invalidCases = [
            'mode_invalid' => $base(['availability_mode' => 'secret']),
            'mode_missing' => ['name' => 'Sin modo', 'type' => 'gift', 'points_cost' => '20'],
            'limited_without_quota' => $base(['availability_mode' => 'limited']),
            'limited_zero_quota' => $base(['availability_mode' => 'limited', 'stock_quantity' => '0']),
            'limited_negative_quota' => $base(['availability_mode' => 'limited', 'stock_quantity' => '-1']),
            'limited_fraction_quota' => $base(['availability_mode' => 'limited', 'stock_quantity' => '1.12345']),
            'product_without_product' => $base(['availability_mode' => 'product']),
            'product_foreign_product' => $base(['availability_mode' => 'product', 'product_id' => $foreignProduct->id]),
            'unlimited_with_quota_prohibited' => $base(['availability_mode' => 'unlimited', 'stock_quantity' => '5']),
            'unlimited_with_product_prohibited' => $base(['availability_mode' => 'unlimited', 'product_id' => $product->id]),
        ];

        foreach ($invalidCases as $case) {
            $this->actingAs($user)->withSession($session)->post(route('loyalty.rewards.store'), $case)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('loyalty_rewards', 0);

        $validCases = [
            $base(['availability_mode' => 'unlimited']),
            $base(['name' => 'Cupo válido', 'availability_mode' => 'limited', 'stock_quantity' => '5.5']),
            $base(['name' => 'Producto válido', 'availability_mode' => 'product', 'product_id' => $product->id]),
        ];
        foreach ($validCases as $case) {
            $this->actingAs($user)->withSession($session)->post(route('loyalty.rewards.store'), $case)->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('loyalty_rewards', 3);
        $withQuota = LoyaltyReward::query()->where('name', 'Cupo válido')->firstOrFail();
        $this->assertSame('5.5000', $withQuota->stock_quantity);
        $withProduct = LoyaltyReward::query()->where('name', 'Producto válido')->firstOrFail();
        $this->assertSame($product->id, (int) $withProduct->product_id);
    }

    public function test_switching_modes_clears_previous_mode_data_on_update(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['fidelidad.premios']);
        $session = $this->activeSession($company, $branch);
        $product = $this->product($company);

        $reward = LoyaltyReward::create(['company_id' => $company->id, 'name' => 'Mutante', 'type' => 'gift', 'points_cost' => '30.0000', 'is_active' => true, 'availability_mode' => 'limited', 'stock_quantity' => '9.0000']);

        $this->actingAs($user)->withSession($session)->put(route('loyalty.rewards.update', $reward), [
            'name' => 'Mutante', 'type' => 'gift', 'points_cost' => '30',
            'availability_mode' => 'product', 'product_id' => $product->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reward->refresh();
        $this->assertSame('product', $reward->availability_mode);
        $this->assertSame($product->id, (int) $reward->product_id);
        $this->assertNull($reward->stock_quantity);

        $this->actingAs($user)->withSession($session)->put(route('loyalty.rewards.update', $reward), [
            'name' => 'Mutante', 'type' => 'gift', 'points_cost' => '30',
            'availability_mode' => 'unlimited',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reward->refresh();
        $this->assertSame('unlimited', $reward->availability_mode);
        $this->assertNull($reward->product_id);
        $this->assertNull($reward->stock_quantity);

        $this->actingAs($user)->withSession($session)->patch(route('loyalty.rewards.toggle', $reward))->assertRedirect();
        $this->assertFalse($reward->fresh()->is_active);
    }

    public function test_unlimited_rewards_are_always_available_when_active(): void
    {
        [$company, $branch] = $this->context();
        $service = app(LoyaltyRewardAvailabilityService::class);
        $reward = $this->reward($company, 'unlimited');

        $result = $service->evaluate($reward, $company, $branch);
        $this->assertTrue($result['available']);
        $this->assertNull($result['reason']);
        $this->assertTrue($service->isAvailable($reward, $company, $branch));
    }

    public function test_limited_mode_requires_positive_quota(): void
    {
        [$company, $branch] = $this->context();
        $service = app(LoyaltyRewardAvailabilityService::class);

        $available = $this->reward($company, 'limited', ['stock_quantity' => '0.0001']);
        $exhausted = $this->reward($company, 'limited', ['stock_quantity' => '0.0000']);
        $withoutQuota = $this->reward($company, 'limited');
        $inactive = $this->reward($company, 'limited', ['stock_quantity' => '5.0000', 'is_active' => false]);

        $this->assertTrue($service->evaluate($available, $company, $branch)['available']);
        $this->assertFalse($service->evaluate($exhausted, $company, $branch)['available']);
        $this->assertSame('insufficient_quota', $service->evaluate($exhausted, $company, $branch)['reason']);
        $this->assertFalse($service->evaluate($withoutQuota, $company, $branch)['available']);
        $this->assertFalse($service->evaluate($inactive, $company, $branch)['available']);
        $this->assertSame('inactive', $service->evaluate($inactive, $company, $branch)['reason']);
    }

    public function test_product_mode_availability_follows_branch_stock_without_mutations(): void
    {
        [$company, $branch] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Secundaria', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $service = app(LoyaltyRewardAvailabilityService::class);
        $product = $this->product($company);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 1.5, 'created_at' => now(), 'updated_at' => now()]);

        $reward = $this->reward($company, 'product', ['product_id' => $product->id]);

        $here = $service->evaluate($reward, $company, $branch);
        $there = $service->evaluate($reward, $company, $otherBranch);

        $this->assertTrue($here['available']);
        $this->assertFalse($there['available']);
        $this->assertSame('out_of_stock', $there['reason']);

        $this->assertSame(1.5, (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('inventory_movements', 0);

        DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->update(['stock' => 0.9999]);
        $this->assertFalse($service->evaluate($reward, $company, $branch)['available']);

        $reward->update(['is_active' => false]);
        $this->assertFalse($service->evaluate($reward, $company, $branch)['available']);
    }

    public function test_cross_company_reward_evaluation_is_rejected(): void
    {
        [$company, $branch] = $this->context();
        [$other] = $this->context();
        $reward = $this->reward($other, 'unlimited');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LoyaltyRewardAvailabilityService::class)->evaluate($reward, $company, $branch);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'earn_on_offers' => false]);

        return [$company, $branch];
    }

    private function reward(Company $company, string $mode, array $extra = []): LoyaltyReward
    {
        return LoyaltyReward::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Premio '.uniqid(),
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
}
