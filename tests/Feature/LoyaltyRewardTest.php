<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_is_seeded_and_assigned_to_administrator_role(): void
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $administrator = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);

        $this->seed(PermissionSeeder::class);

        $permission = Permission::query()->where('name', 'fidelidad.premios')->firstOrFail();
        $this->assertSame('Fidelidad', $permission->module);
        $this->assertTrue($administrator->permissions()->whereKey($permission->getKey())->exists());
    }

    public function test_supports_product_discount_service_and_gift_types(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['fidelidad.premios']);
        $session = $this->activeSession($company, $branch);

        foreach (['product' => 'Café', 'discount' => '5% de descuento', 'service' => 'Envío gratis', 'gift' => 'Detalles sorpresa'] as $type => $name) {
            $this->actingAs($user)->withSession($session)->post(route('loyalty.rewards.store'), [
                'name' => $name,
                'type' => $type,
                'points_cost' => '100.0000',
            ])->assertRedirect();

            $reward = LoyaltyReward::query()->where('company_id', $company->id)->where('name', $name)->firstOrFail();
            $this->assertSame($type, $reward->type);
            $this->assertSame('100.0000', $reward->points_cost);
            $this->assertTrue($reward->is_active);
            $this->assertNull($reward->description);
        }
    }

    public function test_administrator_creates_edits_toggles_reward_with_decimal_precision(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['fidelidad.premios']);
        $session = $this->activeSession($company, $branch);

        $payload = ['name' => 'Combo desayuno', 'type' => 'product', 'points_cost' => '250.5', 'description' => 'Incluye café y pan'];
        $this->actingAs($user)->withSession($session)->post(route('loyalty.rewards.store'), $payload)->assertRedirect();
        $reward = LoyaltyReward::query()->where('company_id', $company->id)->where('name', 'Combo desayuno')->firstOrFail();
        $this->assertSame('250.5000', $reward->points_cost);

        $this->actingAs($user)->withSession($session)->put(route('loyalty.rewards.update', $reward), [
            'name' => 'Combo almuerzo',
            'type' => 'service',
            'points_cost' => '300',
            'description' => null,
        ])->assertRedirect();
        $reward->refresh();
        $this->assertSame('Combo almuerzo', $reward->name);
        $this->assertSame('service', $reward->type);
        $this->assertSame('300.0000', $reward->points_cost);
        $this->assertNull($reward->description);

        $this->actingAs($user)->withSession($session)->patch(route('loyalty.rewards.toggle', $reward))->assertRedirect();
        $this->assertFalse($reward->fresh()->is_active);

        $this->actingAs($user)->withSession($session)->patch(route('loyalty.rewards.toggle', $reward))->assertRedirect();
        $this->assertTrue($reward->fresh()->is_active);
    }

    public function test_validation_rejects_invalid_data_without_creating_rewards(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['fidelidad.premios']);
        $session = $this->activeSession($company, $branch);
        $base = ['name' => 'Premio válido', 'type' => 'product', 'points_cost' => '50'];

        $cases = [
            'name' => array_replace_recursive($base, ['name' => '']),
            'type' => array_replace_recursive($base, ['type' => 'cash']),
            'type_missing' => ['name' => 'Sin tipo', 'points_cost' => '50'],
            'cost_zero' => array_replace_recursive($base, ['points_cost' => '0']),
            'cost_negative' => array_replace_recursive($base, ['points_cost' => '-1']),
            'cost_fraction' => array_replace_recursive($base, ['points_cost' => '10.12345']),
            'cost_overflow' => array_replace_recursive($base, ['points_cost' => '9999999999999']),
        ];

        foreach ($cases as $case) {
            $this->actingAs($user)->withSession($session)->post(route('loyalty.rewards.store'), $case)->assertSessionHasErrors();
        }

        $this->assertDatabaseCount('loyalty_rewards', 0);
    }

    public function test_permission_and_company_isolation_protect_administration(): void
    {
        [$company, $branch] = $this->context();
        [$other] = $this->context();
        $foreign = LoyaltyReward::create(['company_id' => $other->id, 'name' => 'Premio ajeno', 'type' => 'gift', 'points_cost' => '999.0000', 'is_active' => true]);

        $without = $this->user($company, $branch, []);
        $sessionWithout = $this->activeSession($company, $branch);
        $this->actingAs($without)->withSession($sessionWithout)->get(route('loyalty.rewards.index'))->assertForbidden();
        $this->actingAs($without)->withSession($sessionWithout)->post(route('loyalty.rewards.store'), ['name' => 'X', 'type' => 'gift', 'points_cost' => '1'])->assertForbidden();

        $user = $this->user($company, $branch, ['fidelidad.premios']);
        $session = $this->activeSession($company, $branch);
        $response = $this->actingAs($user)->withSession($session)->get(route('loyalty.rewards.index'));
        $response->assertOk()->assertDontSee('Premio ajeno');

        $this->actingAs($user)->withSession($session)->put(route('loyalty.rewards.update', $foreign), ['name' => 'Hackeado', 'type' => 'gift', 'points_cost' => '1'])->assertNotFound();
        $this->actingAs($user)->withSession($session)->patch(route('loyalty.rewards.toggle', $foreign))->assertNotFound();
        $this->assertSame('Premio ajeno', $foreign->fresh()->name);
        $this->assertSame('999.0000', $foreign->fresh()->points_cost);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'earn_on_offers' => false]);

        return [$company, $branch];
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
