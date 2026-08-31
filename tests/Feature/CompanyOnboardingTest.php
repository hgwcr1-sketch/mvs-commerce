<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_creates_commercial_tenant_without_operational_data(): void
    {
        Notification::fake();
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $platform = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $response = $this->actingAs($platform)->post(route('platform.companies.store'), $this->payload());
        $company = Company::where('trade_name', 'Nueva Empresa')->firstOrFail();

        $response->assertRedirect(route('platform.companies.show', $company));
        $this->assertSame(0, $company->branches()->count());
        $this->assertNull($company->identification_number);
        $this->assertSame('tenant-admin@example.test', $company->owner->email);
        $this->assertFalse($company->owner->is_platform_admin);
    }

    public function test_commercial_onboarding_rejects_duplicate_owner_without_creating_company(): void
    {
        Notification::fake();
        $platform = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        User::factory()->create(['email' => 'tenant-admin@example.test']);
        $this->actingAs($platform)->post(route('platform.companies.store'), $this->payload())
            ->assertSessionHasErrors('owner.email');
        $this->assertDatabaseMissing('companies', ['trade_name' => 'Nueva Empresa']);
    }

    public function test_commercial_onboarding_requires_platform_admin_and_is_mobile_first(): void
    {
        $regular = User::factory()->create(['is_active' => true]);
        $this->actingAs($regular)->get(route('platform.companies.create'))->assertForbidden();
        $regular->update(['is_platform_admin' => true]);
        $this->actingAs($regular->fresh())->get(route('platform.companies.create'))->assertOk()
            ->assertSee('Alta comercial de tenant')->assertSee('md:grid-cols-2', false)
            ->assertSee('min-h-11', false)->assertDontSee('Razón social');
    }

    private function payload(): array
    {
        return [
            'trade_name' => 'Nueva Empresa',
            'owner' => ['name' => 'Admin Tenant', 'email' => 'tenant-admin@example.test'],
            'plan' => 'Personalizado', 'branch_limit' => 2, 'user_limit' => 10,
            'status' => 'trial', 'modules' => ['sales', 'inventory', 'administration'],
        ];
    }
}
