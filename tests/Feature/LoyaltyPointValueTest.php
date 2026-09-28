<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyEarningService;
use App\Services\Loyalty\LoyaltyPointValueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPointValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_and_configured_values_convert_points_to_money_with_bcmath(): void
    {
        [$company] = $this->context(false);
        $service = app(LoyaltyPointValueService::class);
        $this->assertSame('1.0000', $service->pointValue($company));
        $this->assertSame('100.0000', $service->moneyFromPoints('100', $company));

        foreach (['0.5000' => '50.0000', '2.0000' => '200.0000', '0.2500' => '25.0000'] as $value => $expected) {
            LoyaltySetting::query()->updateOrCreate(['company_id' => $company->id], ['point_value' => $value]);
            $this->assertSame($expected, $service->moneyFromPoints('100.0000', $company));
        }
    }

    public function test_inverse_conversion_rounds_up_so_it_never_requires_too_few_points(): void
    {
        [$company, , , $setting] = $this->context();
        $setting->update(['point_value' => '0.5000']);
        $service = app(LoyaltyPointValueService::class);
        $this->assertSame('200.0000', $service->pointsForMoney('100', $company));
        $setting->update(['point_value' => '0.3000']);
        $this->assertSame('333.3334', $service->pointsForMoney('100', $company));
        $this->assertSame('100.0000', $service->moneyFromPoints('333.3334', $company));
    }

    public function test_configuration_accepts_valid_values_rejects_nonpositive_and_isolates_companies(): void
    {
        [$company, $branch, , $setting] = $this->context();
        [$other, , , $otherSetting] = $this->context();
        $user = $this->user($company, $branch, ['configuracion.editar', 'configuracion.ver']);
        $session = $this->activeSession($company, $branch);

        foreach (['1', '0.50', '2'] as $value) {
            $this->actingAs($user)->withSession($session)->put(route('configuracion.update', 'fidelidad'), $this->payload($value))->assertRedirect(route('configuracion.index'));
            $this->assertSame(number_format((float) $value, 4, '.', ''), $setting->fresh()->point_value);
        }
        foreach (['0', '-1'] as $invalid) {
            $this->actingAs($user)->withSession($session)->put(route('configuracion.update', 'fidelidad'), $this->payload($invalid))->assertSessionHasErrors('point_value');
        }
        $this->assertSame('1.0000', $otherSetting->fresh()->point_value);
    }

    public function test_changing_point_value_does_not_change_balance_history_or_f08_earning(): void
    {
        [$company, , $customer, $setting] = $this->context();
        $service = app(LoyaltyEarningService::class);
        $first = $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'before']);
        $account = LoyaltyAccount::firstOrFail();
        $setting->update(['point_value' => '0.5000']);
        $second = $service->earnFromEligibleAmount($customer, $company, 1000, ['event_key' => 'after']);

        $this->assertSame('50.0000', $first->points);
        $this->assertSame('50.0000', $second->points);
        $this->assertSame('1.0000', $first->point_value);
        $this->assertSame('0.5000', $second->point_value);
        $this->assertSame('100.0000', $account->fresh()->balance);
        $this->assertSame('50.0000', $first->fresh()->points);
    }

    public function test_multiplier_changes_points_but_point_value_only_changes_their_redemption_value(): void
    {
        [$company, $branch, $customer, $setting] = $this->context();
        $setting->update(['point_value' => '0.5000']);
        $instant = CarbonImmutable::parse('2026-08-22 12:00:00', 'UTC');
        LoyaltyMultiplier::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Doble', 'multiplier' => 2, 'starts_at' => $instant->subHour(), 'ends_at' => $instant->addHour(), 'is_active' => true]);
        $movement = app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, 1000, ['branch' => $branch, 'effective_at' => $instant]);

        $this->assertSame('100.0000', $movement->points);
        $this->assertSame('50.0000', app(LoyaltyPointValueService::class)->moneyFromPoints($movement->points, $company));
    }

    private function context(bool $withSetting = true): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'is_active' => true]);
        $setting = $withSetting ? LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => 1, 'earn_on_offers' => false]) : null;

        return [$company, $branch, $customer, $setting];
    }

    private function payload(string $pointValue): array
    {
        return ['earning_percentage' => '5', 'point_value' => $pointValue, 'earn_on_offers' => '0', 'birthday_enabled' => '0', 'birthday_points' => '0', 'returning_customer_enabled' => '0', 'returning_customer_days' => '0', 'returning_customer_points' => '0'];
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
