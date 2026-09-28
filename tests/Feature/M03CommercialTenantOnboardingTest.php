<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M03CommercialTenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_creates_only_minimum_tenant_owner_and_contract(): void
    {
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $response = $this->actingAs($platform)->post(route('platform.companies.store'), [
            'trade_name' => 'MYM referencia',
            'owner' => ['name' => 'Propietaria MYM', 'email' => 'owner@mym.test'],
            'plan' => 'Inicial', 'branch_limit' => 2, 'status' => 'trial',
            'modules' => ['sales', 'inventory'],
        ]);

        $company = Company::where('trade_name', 'MYM referencia')->firstOrFail();
        $owner = User::where('email', 'owner@mym.test')->firstOrFail();
        $response->assertRedirect(route('platform.companies.show', $company));
        $this->assertSame($owner->id, $company->owner_user_id);
        $this->assertFalse($owner->is_active);
        $this->assertFalse($owner->is_platform_admin);
        $this->assertSame(0, $company->branches()->count());
        $this->assertNull($company->identification_number);
        $this->assertNull($company->address);
        $this->assertDatabaseHas('company_licenses', ['company_id' => $company->id, 'plan' => 'Inicial', 'branch_limit' => 2]);
        $this->assertTrue($company->fresh()->isModuleEnabled('sales'));
        $this->assertFalse($company->fresh()->isModuleEnabled('loyalty'));
    }

    public function test_commercial_form_does_not_request_tenant_operational_data(): void
    {
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $this->actingAs($platform)->get(route('platform.companies.create'))->assertOk()
            ->assertSee('Alta comercial de tenant')
            ->assertDontSee('Razón social')
            ->assertDontSee('Primera sucursal')
            ->assertDontSee('Contraseña');
    }
}
