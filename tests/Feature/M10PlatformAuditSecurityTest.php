<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M10PlatformAuditSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_contract_change_records_actor_company_and_snapshot(): void
    {
        $company = Company::create(['trade_name' => 'Auditada', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $other = Company::create(['trade_name' => 'Otra', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        app(CompanyLicenseService::class)->ensure($other);

        $this->actingAs($platform)->patch(route('platform.modules.update', $company), ['modules' => ['sales', 'inventory']])->assertRedirect();

        $event = $company->license->events()->where('action', 'modules')->firstOrFail();
        $this->assertSame($platform->id, $event->actor_id);
        $this->assertSame($company->id, $event->company_id);
        $this->assertSame(['sales', 'inventory'], $event->snapshot['modules']);
        $this->assertDatabaseMissing('company_license_events', ['company_id' => $other->id, 'action' => 'modules']);
    }
}
