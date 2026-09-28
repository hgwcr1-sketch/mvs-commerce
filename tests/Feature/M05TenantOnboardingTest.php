<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class M05TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_activated_owner_must_complete_existing_tenant_and_first_branch(): void
    {
        Notification::fake();
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $company = app(CompanyProvisioner::class)->commercialOnboard(
            ['name' => 'Owner', 'email' => 'owner@m05.test'],
            ['trade_name' => 'Referencia M05', 'plan' => 'Pro', 'branch_limit' => 2, 'status' => 'active'],
            ['sales'], $platform,
        );
        $owner = $company->owner;
        $owner->update(['is_active' => true, 'tenant_activated_at' => now()]);

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('empresa.create'));
        $this->get(route('empresa.create'))->assertOk()->assertSee('Primera sucursal');
        $this->post(route('empresa.store'), [
            'trade_name' => 'MYM Beauty Center', 'legal_name' => 'MYM Sociedad',
            'identification_type' => '02', 'identification_number' => '3101000000',
            'currency' => 'CRC', 'timezone' => 'America/Costa_Rica',
            'branch_name' => 'San Ramón', 'branch_code' => 'SR',
        ])->assertRedirect(route('dashboard'));

        $company->refresh();
        $this->assertSame('MYM Beauty Center', $company->trade_name);
        $this->assertSame('3101000000', $company->identification_number);
        $this->assertSame(1, $company->branches()->count());
        $this->assertSame(1, $owner->fresh()->companies()->count());
        $this->assertSame('Pro', $company->license->plan);
        $this->assertSame(2, $company->license->branch_limit);
        $this->assertTrue($company->isModuleEnabled('sales'));
    }
}
