<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M14LicenseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_activation_and_active_grace_transition_are_audited(): void
    {
        [$company] = $this->tenant();
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $service = app(CompanyLicenseService::class);
        $license = $service->ensure($company);

        $license = $service->changeLifecycle($company, $admin, 'activate', 'Contrato aprobado.');
        $this->assertSame('active', $license->status);
        $this->assertSame('trial', $license->events()->first()->from_status);
        $this->assertSame($admin->id, $license->events()->first()->actor_id);

        $license->update(['expires_at' => now()->subMinute(), 'grace_until' => now()->addDays(7)]);
        $license = $service->refresh($license->fresh());
        $this->assertSame('grace', $license->status);
        $this->assertDatabaseHas('company_license_events', ['company_id' => $company->id, 'action' => 'automatic', 'from_status' => 'active', 'to_status' => 'grace']);
        $license->update(['grace_until' => now()->subMinute()]);
        $license = $service->refresh($license->fresh());
        $this->assertSame('expired', $license->status);
        $this->assertSame('cancelled', $service->changeLifecycle($company, $admin, 'cancel', 'Contrato terminado.')->status);
    }

    public function test_suspension_and_reactivation_preserve_tenant_data_and_access(): void
    {
        [$company, $branch, $owner] = $this->tenant();
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $service = app(CompanyLicenseService::class);
        $service->changeLifecycle($company, $admin, 'suspend', 'Revisión administrativa.');

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('dashboard'))->assertRedirect(route('license.status'));
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'company_id' => $company->id]);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $owner->id]);

        $service->changeLifecycle($company, $admin, 'reactivate', 'Revisión concluida.');
        $response = $this->get(route('dashboard'));
        $this->assertNotSame(route('license.status'), $response->headers->get('Location'));
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }

    public function test_renewal_extends_contract_and_records_actor_and_changed_fields(): void
    {
        [$company] = $this->tenant();
        [$other] = $this->tenant('Otra empresa');
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $service = app(CompanyLicenseService::class);
        $license = $service->ensure($company);
        $originalExpiry = now()->addDays(10)->startOfMinute();
        $license->update(['status' => 'grace', 'expires_at' => $originalExpiry]);
        $otherLicense = $service->ensure($other);

        $renewed = $service->renew($company, $admin, now()->addYear()->startOfMinute(), now()->addMonths(11)->startOfMinute(), now()->addYear()->addDays(7)->startOfMinute(), 'Renovación anual.');
        $event = $renewed->events()->first();

        $this->assertSame('active', $renewed->status);
        $this->assertSame('renewal_manual', $event->action);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertArrayHasKey('expires_at', $event->changes);
        $this->assertArrayHasKey('next_renewal_at', $event->changes);
        $this->assertSame('trial', $otherLicense->fresh()->status);
    }

    public function test_tenant_cannot_change_or_renew_its_license(): void
    {
        [$company, , $owner] = $this->tenant();
        $license = app(CompanyLicenseService::class)->ensure($company);

        $this->actingAs($owner)->patch(route('platform.licenses.update', $company), [
            'action' => 'renew', 'status' => 'active', 'plan' => $license->plan,
            'expires_at' => now()->addYear()->toDateTimeString(),
        ])->assertForbidden();
        $this->assertSame('trial', $license->fresh()->status);
    }

    private function tenant(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$company->id, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Propietario', 'is_active' => true]);
        $owner = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);
        $owner->branches()->attach($branch->id);

        return [$company, $branch, $owner];
    }
}
