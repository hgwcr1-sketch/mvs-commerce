<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyExpirationSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_expiration_represents_that_points_never_expire(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company);

        $this->putSettings($company, $branch, $user, [
            'expiration_enabled' => '0',
            'expiration_months' => null,
        ])->assertRedirect(route('configuracion.index'));

        $setting->refresh();
        $this->assertFalse($setting->expiration_enabled);
        $this->assertNull($setting->expiration_months);
    }

    public function test_disabling_after_being_active_clears_stored_months(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company);
        $setting->update(['expiration_enabled' => true, 'expiration_months' => 7]);

        $this->putSettings($company, $branch, $user, [
            'expiration_enabled' => '0',
            'expiration_months' => null,
        ])->assertRedirect(route('configuracion.index'));

        $setting->refresh();
        $this->assertFalse($setting->expiration_enabled);
        $this->assertNull($setting->expiration_months);
    }

    public function test_free_month_values_like_one_two_seven_and_twelve_are_accepted(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company);

        foreach ([1, 2, 7, 12] as $months) {
            $this->putSettings($company, $branch, $user, [
                'expiration_enabled' => '1',
                'expiration_months' => (string) $months,
            ])->assertRedirect(route('configuracion.index'))->assertSessionHasNoErrors();

            $setting->refresh();
            $this->assertTrue($setting->expiration_enabled);
            $this->assertSame($months, $setting->expiration_months);
        }
    }

    public function test_zero_negative_decimal_and_out_of_range_months_are_rejected(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company);

        foreach (['0', '-3', '2.5', '0.5', '121', 'abc'] as $invalid) {
            $this->putSettings($company, $branch, $user, [
                'expiration_enabled' => '1',
                'expiration_months' => $invalid,
            ])->assertSessionHasErrors('expiration_months');
        }

        // Activado sin meses también se rechaza.
        $this->putSettings($company, $branch, $user, ['expiration_enabled' => '1'])
            ->assertSessionHasErrors('expiration_months');

        $setting->refresh();
        $this->assertFalse((bool) $setting->expiration_enabled);
    }

    public function test_months_prohibited_when_expiration_is_disabled(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company);

        $this->putSettings($company, $branch, $user, [
            'expiration_enabled' => '0',
            'expiration_months' => '6',
        ])->assertSessionHasErrors('expiration_months');

        $setting->refresh();
        $this->assertFalse($setting->expiration_enabled);
    }

    public function test_company_isolation_keeps_each_policy_independent(): void
    {
        [$companyA, $branchA, $user] = $this->authorizedContext();
        [$companyB] = $this->companyContext('Empresa B');
        $settingA = $this->setting($companyA);
        $settingB = $this->setting($companyB);
        $settingB->update(['expiration_enabled' => true, 'expiration_months' => 4]);

        $this->putSettings($companyA, $branchA, $user, [
            'expiration_enabled' => '1',
            'expiration_months' => '9',
        ])->assertRedirect(route('configuracion.index'));

        $settingA->refresh();
        $settingB->refresh();
        $this->assertTrue($settingA->expiration_enabled);
        $this->assertSame(9, $settingA->expiration_months);
        $this->assertSame(4, $settingB->expiration_months);
    }

    public function test_settings_page_requires_edit_permission_and_persists_toggle_changes(): void
    {
        [$company, $branch] = $this->companyContext('Permisos vencimiento');
        $setting = $this->setting($company);

        $makeUser = function (array $permissions) use ($company, $branch): User {
            $user = User::factory()->create(['is_active' => true]);
            $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
            foreach ($permissions as $name) {
                $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Configuración', 'is_active' => true]);
                $role->permissions()->attach($permission);
            }
            $user->companies()->attach($company->id, ['role_id' => $role->id]);
            $user->branches()->attach($branch->id);

            return $user;
        };

        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $viewer = $makeUser(['configuracion.ver']);
        $this->actingAs($viewer)->withSession($session)->get(route('configuracion.index'))
            ->assertOk()->assertDontSee('Guardar configuración de Fidelización');
        $this->actingAs($viewer)->withSession($session)
            ->put(route('configuracion.update', 'fidelidad'), $this->payload('1', '5'))
            ->assertForbidden();

        $editor = $makeUser(['configuracion.ver', 'configuracion.editar']);
        $this->actingAs($editor)->withSession($session)
            ->put(route('configuracion.update', 'fidelidad'), $this->payload('1', '12'))
            ->assertRedirect(route('configuracion.index'));
        $this->assertTrue((bool) $setting->fresh()->expiration_enabled);
        $this->assertSame(12, $setting->fresh()->expiration_months);

        $this->actingAs($editor)->withSession($session)
            ->put(route('configuracion.update', 'fidelidad'), $this->payload('0', null))
            ->assertRedirect(route('configuracion.index'));
        $this->assertFalse((bool) $setting->fresh()->expiration_enabled);
        $this->assertNull($setting->fresh()->expiration_months);
    }

    private function payload(string $enabled, ?string $months): array
    {
        return array_filter([
            'earning_percentage' => '5',
            'birthday_enabled' => '0',
            'birthday_points' => '0',
            'returning_customer_enabled' => '0',
            'returning_customer_days' => '0',
            'returning_customer_points' => '0',
            'maximum_redemption_percent' => '100',
            'redemption_minimum_enabled' => '0',
            'redemption_minimum_amount' => '0',
            'point_value' => '1',
            'expiration_enabled' => $enabled,
            'expiration_months' => $months,
        ], static fn ($value) => $value !== null);
    }

    private function putSettings(Company $company, Branch $branch, User $user, array $extra)
    {
        return $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->from(route('configuracion.index'))
            ->put(route('configuracion.update', 'fidelidad'), array_merge($this->payload('0', null), $extra));
    }

    private function authorizedContext(): array
    {
        [$company, $branch] = $this->companyContext('Administración vencimiento');
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Configurador '.uniqid(), 'is_active' => true]);
        foreach (['configuracion.ver', 'configuracion.editar'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Configuración', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function companyContext(string $name): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);

        return [$company, $branch];
    }

    private function setting(Company $company): LoyaltySetting
    {
        return LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'earn_on_offers' => false,
        ]);
    }
}
