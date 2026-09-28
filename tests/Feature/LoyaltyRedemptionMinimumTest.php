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
use App\Services\Loyalty\LoyaltyRedemptionEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyRedemptionMinimumTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_minimum_allows_positive_balance_but_blocks_zero_and_negative(): void
    {
        [$company, , $account] = $this->context('1.0000', false, '0.0000');
        $service = app(LoyaltyRedemptionEligibilityService::class);
        $account->update(['balance' => '0.0001']);
        $this->assertTrue($service->evaluate($account->fresh(), $company)['eligible']);
        $account->update(['balance' => '0.0000']);
        $this->assertSame('insufficient_points', $service->evaluate($account->fresh(), $company)['reason']);
        $account->update(['balance' => '-1.0000']);
        $this->assertFalse($service->evaluate($account->fresh(), $company)['eligible']);
    }

    public function test_minimum_with_value_one_blocks_below_allows_equal_and_reports_missing_money(): void
    {
        [$company, , $account] = $this->context('1.0000', true, '3000.0000');
        $service = app(LoyaltyRedemptionEligibilityService::class);
        $account->update(['balance' => '2999.0000']);
        $below = $service->evaluate($account->fresh(), $company);
        $this->assertFalse($below['eligible']);
        $this->assertSame('2999.0000', $below['available_money']);
        $this->assertSame('1.0000', $below['missing_money']);
        $this->assertSame('3000.0000', $below['required_points']);
        $account->update(['balance' => '3000.0000']);
        $this->assertTrue($service->evaluate($account->fresh(), $company)['eligible']);
        $account->update(['balance' => '3001.0000']);
        $this->assertTrue($service->evaluate($account->fresh(), $company)['eligible']);
    }

    public function test_half_value_requires_six_thousand_points_and_blocks_5999(): void
    {
        [$company, , $account] = $this->context('0.5000', true, '3000.0000');
        $service = app(LoyaltyRedemptionEligibilityService::class);
        $account->update(['balance' => '5999.0000']);
        $below = $service->evaluate($account->fresh(), $company);
        $this->assertFalse($below['eligible']);
        $this->assertSame('2999.5000', $below['available_money']);
        $this->assertSame('0.5000', $below['missing_money']);
        $this->assertSame('6000.0000', $below['required_points']);
        $account->update(['balance' => '6000.0000']);
        $this->assertTrue($service->evaluate($account->fresh(), $company)['eligible']);
    }

    public function test_point_value_and_minimum_changes_react_immediately_without_mutating_account_or_kardex(): void
    {
        [$company, $setting, $account] = $this->context('1.0000', true, '3000.0000');
        $account->update(['balance' => '3000.0000']);
        $service = app(LoyaltyRedemptionEligibilityService::class);
        $this->assertTrue($service->evaluate($account->fresh(), $company)['eligible']);
        $setting->update(['point_value' => '0.5000']);
        $this->assertFalse($service->evaluate($account->fresh(), $company)['eligible']);
        $setting->update(['redemption_minimum_amount' => '1000.0000']);
        $this->assertTrue($service->evaluate($account->fresh(), $company)['eligible']);
        $setting->update(['redemption_minimum_enabled' => false]);
        $this->assertTrue($service->evaluate($account->fresh(), $company)['eligible']);
        $this->assertSame('3000.0000', $account->fresh()->balance);
        $this->assertDatabaseCount('loyalty_movements', 0);
    }

    public function test_configuration_validates_minimum_permission_and_company_isolation(): void
    {
        [$company, $setting, , $branch] = $this->context();
        [$other, $otherSetting] = $this->context('1.0000', true, '5000.0000');
        $without = $this->user($company, $branch, []);
        $this->actingAs($without)->withSession($this->activeSession($company, $branch))->put(route('configuracion.update', 'fidelidad'), $this->payload('3000'))->assertForbidden();
        $user = $this->user($company, $branch, ['configuracion.editar']);
        $session = $this->activeSession($company, $branch);
        foreach (['0', '-1'] as $invalid) {
            $this->actingAs($user)->withSession($session)->put(route('configuracion.update', 'fidelidad'), $this->payload($invalid))->assertSessionHasErrors('redemption_minimum_amount');
        }
        $this->actingAs($user)->withSession($session)->put(route('configuracion.update', 'fidelidad'), $this->payload('3000'))->assertRedirect(route('configuracion.index'));
        $this->assertTrue($setting->fresh()->redemption_minimum_enabled);
        $this->assertSame('3000.0000', $setting->fresh()->redemption_minimum_amount);
        $this->assertSame('5000.0000', $otherSetting->fresh()->redemption_minimum_amount);
        $this->assertNotSame($company->id, $other->id);
    }

    private function context(string $pointValue = '1.0000', bool $enabled = false, string $minimum = '0.0000'): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'is_active' => true]);
        $setting = LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => $pointValue, 'redemption_minimum_enabled' => $enabled, 'redemption_minimum_amount' => $minimum]);
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => 0]);

        return [$company, $setting, $account, $branch];
    }

    private function payload(string $minimum): array
    {
        return ['earning_percentage' => '5', 'point_value' => '1', 'redemption_minimum_enabled' => '1', 'redemption_minimum_amount' => $minimum, 'earn_on_offers' => '0', 'birthday_enabled' => '0', 'birthday_points' => '0', 'returning_customer_enabled' => '0', 'returning_customer_days' => '0', 'returning_customer_points' => '0'];
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
