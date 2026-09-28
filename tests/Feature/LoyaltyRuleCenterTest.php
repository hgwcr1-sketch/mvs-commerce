<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyBirthdayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyRuleCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_administrator_sees_the_rule_center(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion']);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('loyalty.rules.index'));

        $response->assertOk();
        $response->assertSee('Centro de reglas');
        $response->assertSee('Bono de cumpleaños');
        $response->assertSee('Bono por retorno');
        $response->assertSee('Acumular puntos en productos con precio de oferta');
        $response->assertSee('Los puntos vencen por inactividad');
    }

    public function test_user_without_permission_cannot_view_or_update_rules(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.ver']);

        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('loyalty.rules.index'))
            ->assertForbidden();

        $this->actingAs($user)->withSession($session)
            ->put(route('loyalty.rules.update'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('loyalty_settings', 0);
    }

    public function test_saved_rules_update_the_real_configuration_without_duplicates(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion']);
        $existing = LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'birthday_enabled' => false,
            'birthday_points' => '0.0000',
            'returning_customer_enabled' => false,
            'returning_customer_days' => 0,
            'returning_customer_points' => '0.0000',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('loyalty.rules.update'), $this->payload([
                'birthday_enabled' => '1',
                'birthday_points' => '125.5',
                'returning_customer_enabled' => '1',
                'returning_customer_days' => '45',
                'returning_customer_points' => '80',
                'expiration_enabled' => '1',
                'expiration_months' => '7',
            ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $setting = LoyaltySetting::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame($existing->id, $setting->id);
        $this->assertSame(1, LoyaltySetting::query()->where('company_id', $company->id)->count());
        $this->assertTrue($setting->birthday_enabled);
        $this->assertSame('125.5000', (string) $setting->birthday_points);
        $this->assertTrue($setting->returning_customer_enabled);
        $this->assertSame(45, $setting->returning_customer_days);
        $this->assertSame('80.0000', (string) $setting->returning_customer_points);
        $this->assertTrue($setting->expiration_enabled);
        $this->assertSame(7, $setting->expiration_months);
        $this->assertSame('5.0000', (string) $setting->earning_percentage);
    }

    public function test_rule_change_affects_the_existing_services_not_a_copy(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion']);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'birth_date' => '1992-04-18', 'is_active' => true]);

        $service = app(LoyaltyBirthdayService::class);
        $this->assertNull($service->awardIfEligible($customer, $company, CarbonImmutable::parse('2026-04-18 10:00:00', $company->timezone)));

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('loyalty.rules.update'), $this->payload([
                'birthday_enabled' => '1',
                'birthday_points' => '99.25',
            ]))
            ->assertRedirect();

        $movement = $service->awardIfEligible($customer, $company, CarbonImmutable::parse('2027-04-18 10:00:00', $company->timezone));

        $this->assertNotNull($movement);
        $this->assertSame('99.2500', (string) $movement->points);
        $this->assertSame(1, LoyaltySetting::query()->where('company_id', $company->id)->count());
    }

    public function test_invalid_rule_values_are_rejected_without_touching_the_row(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion']);
        LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'birthday_enabled' => false,
            'birthday_points' => '20.0000',
            'returning_customer_enabled' => false,
            'returning_customer_days' => 0,
            'returning_customer_points' => '0.0000',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('loyalty.rules.update'), $this->payload(['birthday_enabled' => '1', 'birthday_points' => '0']));

        $response->assertRedirect();
        $response->assertSessionHasErrors('birthday_points');

        $setting = LoyaltySetting::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertFalse($setting->birthday_enabled);
        $this->assertSame('20.0000', (string) $setting->birthday_points);
    }

    public function test_each_company_saves_its_own_isolated_configuration(): void
    {
        [$companyA, $branchA, $userA] = $this->context(['fidelidad.configuracion']);
        [$companyB, $branchB, $userB] = $this->context(['fidelidad.configuracion']);

        $this->actingAs($userA)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->put(route('loyalty.rules.update'), $this->payload(['earning_percentage' => '3']));

        $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->put(route('loyalty.rules.update'), $this->payload(['earning_percentage' => '9']));

        $this->assertSame('3.0000', (string) LoyaltySetting::query()->where('company_id', $companyA->id)->value('earning_percentage'));
        $this->assertSame('9.0000', (string) LoyaltySetting::query()->where('company_id', $companyB->id)->value('earning_percentage'));
        $this->assertSame(1, LoyaltySetting::query()->where('company_id', $companyA->id)->count());
        $this->assertSame(1, LoyaltySetting::query()->where('company_id', $companyB->id)->count());
    }

    private function context(array $permissions): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission->id);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'is_active' => '1',
            'earning_percentage' => '5',
            'point_value' => '1',
            'redemption_minimum_enabled' => '0',
            'redemption_minimum_amount' => null,
            'maximum_redemption_percent' => '100',
            'earn_on_offers' => '1',
            'birthday_enabled' => '0',
            'birthday_points' => '50',
            'returning_customer_enabled' => '0',
            'returning_customer_days' => '30',
            'returning_customer_points' => '100',
            'expiration_enabled' => '0',
            'expiration_months' => null,
        ], $overrides);
    }
}
