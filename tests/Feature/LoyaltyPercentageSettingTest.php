<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyEarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPercentageSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_updates_percentage_for_only_the_active_company(): void
    {
        [$companyA, $branchA, $user] = $this->authorizedContext();
        [$companyB] = $this->companyContext('Empresa B');
        $settingA = $this->setting($companyA, '5.0000');
        $settingB = $this->setting($companyB, '10.0000');

        $this->actingAs($user)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->get(route('configuracion.index'))
            ->assertOk()
            ->assertSee('Porcentaje de acumulación')
            ->assertSee('id="tab-fidelizacion"', false)
            ->assertSee('id="panel-fidelizacion"', false)
            ->assertSee('id="tab-whatsapp"', false)
            ->assertSee('id="panel-whatsapp"', false)
            ->assertSee('value="5.00"', false);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->put(route('configuracion.update', 'fidelidad'), [
                'earning_percentage' => '3',
                'birthday_enabled' => '0',
                'birthday_points' => '0',
                'returning_customer_enabled' => '0',
                'returning_customer_days' => '0',
                'returning_customer_points' => '0',
            ])
            ->assertRedirect(route('configuracion.index'));

        $this->assertSame('3.0000', $settingA->fresh()->earning_percentage);
        $this->assertSame('10.0000', $settingB->fresh()->earning_percentage);
    }

    public function test_invalid_percentage_is_rejected_without_changing_previous_value(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company, '5.0000');

        foreach (['-1', '0', '100.0001', '2.12345'] as $invalid) {
            $this->actingAs($user)
                ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
                ->from(route('configuracion.index'))
                ->put(route('configuracion.update', 'fidelidad'), [
                    'earning_percentage' => $invalid,
                    'birthday_enabled' => '0',
                    'birthday_points' => '0',
                    'returning_customer_enabled' => '0',
                    'returning_customer_days' => '0',
                    'returning_customer_points' => '0',
                ])
                ->assertRedirect(route('configuracion.index'))
                ->assertSessionHasErrors('earning_percentage');

            $this->assertSame('5.0000', $setting->fresh()->earning_percentage);
        }
    }

    public function test_earning_service_reads_each_updated_percentage_including_decimal(): void
    {
        [$company] = $this->companyContext('Cálculo');
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente cálculo', 'is_active' => true]);
        $setting = $this->setting($company, '5.0000');
        $service = app(LoyaltyEarningService::class);

        $expected = [
            '5.0000' => '50.0000',
            '3.0000' => '30.0000',
            '10.0000' => '100.0000',
            '2.5000' => '25.0000',
        ];

        foreach ($expected as $percentage => $points) {
            $setting->update(['earning_percentage' => $percentage]);
            $movement = $service->earnFromEligibleAmount($customer, $company, 1000, [
                'event_key' => 'percentage:'.$percentage,
            ]);
            $this->assertSame($points, $movement->points);
            $this->assertSame($percentage, $movement->earning_percentage);
        }
    }

    public function test_administrator_configures_birthday_bonus_for_active_company(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company, '5.0000');

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('configuracion.update', 'fidelidad'), [
                'earning_percentage' => '5',
                'birthday_enabled' => '1',
                'birthday_points' => '250.5000',
                'returning_customer_enabled' => '0',
                'returning_customer_days' => '0',
                'returning_customer_points' => '0',
            ])
            ->assertRedirect(route('configuracion.index'));

        $setting->refresh();
        $this->assertTrue($setting->birthday_enabled);
        $this->assertSame('250.5000', $setting->birthday_points);
    }

    public function test_administrator_configures_and_validates_returning_customer_bonus(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company, '5.0000');
        $base = [
            'earning_percentage' => '5',
            'birthday_enabled' => '0',
            'birthday_points' => '0',
            'returning_customer_enabled' => '1',
        ];

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('configuracion.update', 'fidelidad'), $base + [
                'returning_customer_days' => '30',
                'returning_customer_points' => '150.5000',
            ])
            ->assertRedirect(route('configuracion.index'));

        $setting->refresh();
        $this->assertTrue($setting->returning_customer_enabled);
        $this->assertSame(30, $setting->returning_customer_days);
        $this->assertSame('150.5000', $setting->returning_customer_points);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->from(route('configuracion.index'))
            ->put(route('configuracion.update', 'fidelidad'), $base + [
                'returning_customer_days' => '0',
                'returning_customer_points' => '-1',
            ])
            ->assertSessionHasErrors(['returning_customer_days', 'returning_customer_points']);

        $this->assertSame(30, $setting->fresh()->returning_customer_days);
        $this->assertSame('150.5000', $setting->fresh()->returning_customer_points);
    }

    public function test_offer_earning_setting_is_saved_only_for_active_company(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        [$other] = $this->companyContext('Otra');
        $setting = $this->setting($company, '5.0000');
        $otherSetting = $this->setting($other, '5.0000');

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->put(route('configuracion.update', 'fidelidad'), [
                'earning_percentage' => '5', 'earn_on_offers' => '1', 'birthday_enabled' => '0', 'birthday_points' => '0',
                'returning_customer_enabled' => '0', 'returning_customer_days' => '0', 'returning_customer_points' => '0',
            ])->assertRedirect(route('configuracion.index'));

        $this->assertTrue($setting->fresh()->earn_on_offers);
        $this->assertFalse($otherSetting->fresh()->earn_on_offers);
    }

    public function test_settings_page_renders_all_loyalty_fields_inside_the_form(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $this->setting($company, '5.0000');

        $content = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('configuracion.index'))
            ->getContent();

        $formOpen = strpos($content, '<form method="POST" action="'.route('configuracion.update', 'fidelidad').'"');
        $this->assertNotFalse($formOpen);
        $formClose = strpos($content, '</form>', $formOpen);
        $this->assertNotFalse($formClose);

        foreach (
            [
                'name="earning_percentage"',
                'name="is_active"',
                'name="birthday_enabled"',
                'name="birthday_points"',
                'name="returning_customer_enabled"',
                'name="returning_customer_days"',
                'name="returning_customer_points"',
                'name="point_value"',
                'name="redemption_minimum_enabled"',
                'name="redemption_minimum_amount"',
                'name="maximum_redemption_percent"',
            ] as $field
        ) {
            $position = strpos($content, $field);
            $this->assertNotFalse($position, "El campo {$field} no aparece en la página.");
            $this->assertTrue(
                $position > $formOpen && $position < $formClose,
                "El campo {$field} está fuera del formulario de Fidelización.",
            );
        }
    }

    public function test_validation_errors_for_missing_fields_are_displayed_in_spanish(): void
    {
        [$company, $branch, $user] = $this->authorizedContext();
        $setting = $this->setting($company, '5.0000');

        $response = $this->followingRedirects()
            ->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->from(route('configuracion.index'))
            ->put(route('configuracion.update', 'fidelidad'), [
                'earning_percentage' => '5',
                'is_active' => '1',
            ]);

        $response->assertOk()
            ->assertSee('El campo de puntos de cumpleaños es obligatorio.')
            ->assertSee('El campo de días sin comprar es obligatorio.')
            ->assertSee('El campo de puntos por retorno es obligatorio.');

        $this->assertSame('5.0000', $setting->fresh()->earning_percentage);
    }

    public function test_loyalty_activation_toggle_is_saved_only_for_the_active_company(): void
    {
        [$companyA, $branchA, $user] = $this->authorizedContext();
        [$companyB] = $this->companyContext('Empresa B');
        $settingA = $this->setting($companyA, '5.0000');
        $settingB = $this->setting($companyB, '10.0000');
        $settingB->update(['is_active' => false]);

        $permission = Permission::firstOrCreate(
            ['name' => 'fidelidad.configuracion'],
            ['label' => 'fidelidad.configuracion', 'module' => 'Fidelización', 'is_active' => true],
        );
        foreach ($user->roles as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $session = fn () => $this->actingAs($user)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id]);

        $session()->get(route('loyalty.settings'))->assertRedirect(route('configuracion.index'));

        $payload = [
            'is_active' => '1',
            'earning_percentage' => '5',
            'birthday_enabled' => '0',
            'birthday_points' => '0',
            'returning_customer_enabled' => '0',
            'returning_customer_days' => '0',
            'returning_customer_points' => '0',
        ];

        $session()->put(route('configuracion.update', 'fidelidad'), $payload)
            ->assertRedirect(route('configuracion.index'));
        $settingA->refresh();
        $this->assertTrue($settingA->is_active);
        $this->assertFalse($settingB->fresh()->is_active);

        $payload['is_active'] = '0';

        $session()->put(route('configuracion.update', 'fidelidad'), $payload)
            ->assertRedirect(route('configuracion.index'));
        $this->assertFalse($settingA->fresh()->is_active);
        $this->assertFalse($settingB->fresh()->is_active);
    }

    private function authorizedContext(): array
    {
        [$company, $branch] = $this->companyContext('Administración');
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

    private function setting(Company $company, string $percentage): LoyaltySetting
    {
        return LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => $percentage,
            'point_value' => '1.0000',
            'earn_on_offers' => false,
        ]);
    }
}
