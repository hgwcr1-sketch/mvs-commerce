<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class M13CommercialLicensePlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_is_a_template_and_tenant_override_does_not_change_other_contracts(): void
    {
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $service = app(CompanyLicenseService::class);
        $plan = $service->savePlan(null, $platform, [
            'code' => 'PRO', 'name' => 'Profesional', 'branch_limit' => 2, 'user_limit' => 10,
            'modules' => ['sales', 'inventory'], 'is_active' => true,
        ]);
        $mym = $this->company('MYM');
        $other = $this->company('Otro Profesional');

        $service->applyPlan($mym, $plan, $platform, ['status' => 'active', 'branch_limit' => 3]);
        $service->applyPlan($other, $plan, $platform, ['status' => 'active']);

        $this->assertSame(2, $plan->fresh()->branch_limit);
        $this->assertSame(3, $mym->license->fresh()->branch_limit);
        $this->assertSame(2, $other->license->fresh()->branch_limit);
        $this->assertSame(10, $mym->license->fresh()->user_limit);
        $this->assertSame($plan->id, $mym->license->fresh()->license_plan_id);
        $this->assertTrue($mym->fresh()->isModuleEnabled('sales'));
        $this->assertFalse($mym->fresh()->isModuleEnabled('loyalty'));
    }

    public function test_tenant_cannot_save_or_apply_a_plan(): void
    {
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $tenant = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $service = app(CompanyLicenseService::class);
        $plan = $service->savePlan(null, $platform, [
            'code' => 'BASE', 'name' => 'Base', 'branch_limit' => 1, 'user_limit' => 2,
            'modules' => ['sales'], 'is_active' => true,
        ]);

        foreach ([
            fn () => $service->savePlan($plan, $tenant, ['branch_limit' => 99]),
            fn () => $service->applyPlan($this->company('Tenant'), $plan, $tenant),
        ] as $operation) {
            try {
                $operation();
                $this->fail('El tenant no debe administrar planes o contratos.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_platform_is_supervisor_and_real_owner_completes_onboarding(): void
    {
        Notification::fake();
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $platform = User::factory()->create(['email' => 'admin@mvscommerce.com', 'is_active' => true, 'is_platform_admin' => true]);

        $this->actingAs($platform)->post(route('platform.companies.store'), [
            'trade_name' => 'MYM referencia',
            'owner' => ['name' => 'Propietaria MYM', 'email' => 'propietaria@mym.test'],
            'plan' => 'Personalizado', 'branch_limit' => 3, 'user_limit' => 10,
            'status' => 'trial', 'modules' => ['sales'],
        ])->assertRedirect();

        $company = Company::where('trade_name', 'MYM referencia')->firstOrFail();
        $this->assertSame('propietaria@mym.test', $company->owner->email);
        $this->assertNotSame($platform->id, $company->owner_user_id);
        $this->assertFalse($company->owner->is_platform_admin);
        $this->actingAs($platform)->get(route('platform.companies.show', $company))->assertOk()
            ->assertSee('Solo lectura')
            ->assertDontSee('Guardar configuración')
            ->assertDontSee('Desactivar', false);
        $this->assertFalse(app('router')->has('platform.companies.update'));
        $this->assertFalse(app('router')->has('platform.branches.update'));
        $this->assertFalse(app('router')->has('platform.users.update'));
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }
}
