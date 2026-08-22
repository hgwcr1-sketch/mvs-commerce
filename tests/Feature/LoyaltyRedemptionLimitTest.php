<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRedemptionLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyRedemptionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_percentage_limits_for_twenty_fifty_one_hundred_and_decimal(): void
    {
        [$company, $setting, $account] = $this->context('1.0000', '50.0000', '20000.0000');
        $service = app(LoyaltyRedemptionLimitService::class);
        foreach (['20.0000' => '2000.0000', '50.0000' => '5000.0000', '100.0000' => '10000.0000', '33.3300' => '3333.0000'] as $percentage => $expected) {
            $setting->update(['maximum_redemption_percent' => $percentage]);
            $result = $service->calculate($account->fresh(), $company, '10000.0000');
            $this->assertSame($expected, $result['max_money_by_percentage']);
            $this->assertSame($expected, $result['max_redeemable_money']);
        }
    }

    public function test_balance_caps_real_limit_and_small_purchase_uses_percentage(): void
    {
        [$company, , $account] = $this->context('1.0000', '50.0000', '3000.0000');
        $service = app(LoyaltyRedemptionLimitService::class);
        $limitedByBalance = $service->calculate($account, $company, 10000);
        $this->assertSame('5000.0000', $limitedByBalance['max_money_by_percentage']);
        $this->assertSame('3000.0000', $limitedByBalance['max_redeemable_money']);
        $this->assertSame('3000.0000', $limitedByBalance['max_redeemable_points']);
        $account->update(['balance' => '10000.0000']);
        $small = $service->calculate($account->fresh(), $company, 1000);
        $this->assertSame('500.0000', $small['max_redeemable_money']);
    }

    public function test_point_value_changes_required_points_not_percentage_money_limit(): void
    {
        [$company, $setting, $account] = $this->context('1.0000', '50.0000', '20000.0000');
        $service = app(LoyaltyRedemptionLimitService::class);
        $one = $service->calculate($account, $company, 10000);
        $this->assertSame('5000.0000', $one['max_redeemable_points']);
        $setting->update(['point_value' => '0.5000']);
        $half = $service->calculate($account->fresh(), $company, 10000);
        $this->assertSame('5000.0000', $half['max_money_by_percentage']);
        $this->assertSame('10000.0000', $half['max_redeemable_points']);
    }

    public function test_f15_block_returns_zero_and_exact_minimum_calculates_normally_without_mutations(): void
    {
        [$company, $setting, $account] = $this->context('1.0000', '50.0000', '2500.0000');
        $setting->update(['redemption_minimum_enabled' => true, 'redemption_minimum_amount' => '3000.0000']);
        $service = app(LoyaltyRedemptionLimitService::class);
        $blocked = $service->calculate($account, $company, 10000);
        $this->assertFalse($blocked['eligible']);
        $this->assertSame('minimum_not_reached', $blocked['reason']);
        $this->assertSame('0.0000', $blocked['max_redeemable_money']);
        $account->update(['balance' => '3000.0000']);
        $allowed = $service->calculate($account->fresh(), $company, 10000);
        $this->assertTrue($allowed['eligible']);
        $this->assertSame('3000.0000', $allowed['max_redeemable_money']);
        $this->assertSame('3000.0000', $account->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_rounding_never_exceeds_percentage_money_or_available_points(): void
    {
        [$company, , $account] = $this->context('0.3000', '50.0000', '20000.0000');
        $result = app(LoyaltyRedemptionLimitService::class)->calculate($account, $company, 10000);
        $this->assertSame('5000.0000', $result['max_money_by_percentage']);
        $this->assertSame('16666.6666', $result['max_redeemable_points']);
        $this->assertSame('4999.9999', $result['max_redeemable_money']);
        $this->assertLessThanOrEqual(0, bccomp($result['max_redeemable_points'], $account->balance, 4));
        $this->assertLessThanOrEqual(0, bccomp($result['max_redeemable_money'], $result['max_money_by_percentage'], 4));
    }

    public function test_default_is_hundred_percent_and_company_configuration_is_protected_and_isolated(): void
    {
        $defaultCompany = Company::create(['trade_name' => 'Sin configuración', 'timezone' => 'UTC', 'is_active' => true]);
        $defaultCustomer = Customer::create(['company_id' => $defaultCompany->id, 'name' => 'Cliente default', 'customer_type' => 'individual', 'is_active' => true]);
        $defaultAccount = LoyaltyAccount::create(['company_id' => $defaultCompany->id, 'customer_id' => $defaultCustomer->id, 'balance' => '1000.0000']);
        $defaultResult = app(LoyaltyRedemptionLimitService::class)->calculate($defaultAccount, $defaultCompany, 1000);
        $this->assertSame('100.0000', $defaultResult['percentage']);
        $this->assertSame('1000.0000', $defaultResult['max_redeemable_money']);

        [$company, $setting, $account, $branch] = $this->context('1.0000', '50.0000', '20000.0000');
        [$other, $otherSetting] = $this->context('1.0000', '100.0000', '20000.0000');
        $without = $this->user($company, $branch, []);
        $this->actingAs($without)->withSession($this->activeSession($company, $branch))->put(route('configuracion.update', 'fidelidad'), $this->payload('75'))->assertForbidden();
        $user = $this->user($company, $branch, ['configuracion.editar']);
        $session = $this->activeSession($company, $branch);
        foreach (['0', '100.0001', '-1'] as $invalid) {
            $this->actingAs($user)->withSession($session)->put(route('configuracion.update', 'fidelidad'), $this->payload($invalid))->assertSessionHasErrors('maximum_redemption_percent');
        }
        $this->actingAs($user)->withSession($session)->put(route('configuracion.update', 'fidelidad'), $this->payload('75'))->assertRedirect(route('configuracion.index'));
        $this->assertSame('75.0000', $setting->fresh()->maximum_redemption_percent);
        $this->assertSame('100.0000', $otherSetting->fresh()->maximum_redemption_percent);
        $this->assertSame('7500.0000', app(LoyaltyRedemptionLimitService::class)->calculate($account, $company, 10000)['max_money_by_percentage']);
        $this->assertNotSame($company->id, $other->id);
    }

    private function context(string $pointValue, string $percentage, string $balance): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'is_active' => true]);
        $setting = LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => $pointValue, 'maximum_redemption_percent' => $percentage]);
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);

        return [$company, $setting, $account, $branch];
    }

    private function payload(string $percentage): array
    {
        return ['earning_percentage' => '5', 'point_value' => '1', 'maximum_redemption_percent' => $percentage, 'redemption_minimum_enabled' => '0', 'redemption_minimum_amount' => '0', 'earn_on_offers' => '0', 'birthday_enabled' => '0', 'birthday_points' => '0', 'returning_customer_enabled' => '0', 'returning_customer_days' => '0', 'returning_customer_points' => '0'];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Configuración', 'is_active' => true]);
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
