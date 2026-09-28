<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyLicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_transitions_license_with_history(): void
    {
        [$company, , $user] = $this->tenant();
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        app(CompanyLicenseService::class)->ensure($company);
        $payload = ['status' => 'suspended', 'plan' => 'Pro', 'notes' => 'Mora'];
        $this->actingAs($user)->patch(route('platform.licenses.update', $company), $payload)->assertForbidden();
        $this->actingAs($admin)->patch(route('platform.licenses.update', $company), $payload)->assertRedirect();
        $this->assertDatabaseHas('company_license_events', ['company_id' => $company->id, 'actor_id' => $admin->id, 'to_status' => 'suspended']);
    }

    public function test_grace_expiry_blocks_direct_operations_and_reactivation_preserves_data(): void
    {
        [$company, $branch, $user] = $this->tenant();
        $service = app(CompanyLicenseService::class);
        $license = $service->ensure($company);
        $license->update(['status' => 'active', 'expires_at' => now()->subMinute(), 'grace_until' => now()->addDay()]);
        $this->assertSame('grace', $service->refresh($license->fresh())->status);
        $graceResponse = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->get(route('dashboard'));
        $this->assertNotSame(route('license.status'), $graceResponse->headers->get('Location'));
        $this->assertSame('grace', $license->fresh()->status);
        $license->update(['grace_until' => now()->subMinute()]);
        $this->get(route('dashboard'))->assertRedirect(route('license.status'));
        $this->get(route('license.status'))->assertOk()->assertSee('datos permanecen intactos');
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $service->updateContract($company, $platformAdmin, 'active', 'Pago', ['expires_at' => now()->addMonth(), 'grace_until' => now()->addMonth()->addDays(7)]);
        $reactivatedResponse = $this->get(route('dashboard'));
        $this->assertNotSame(route('license.status'), $reactivatedResponse->headers->get('Location'));
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }

    public function test_limits_and_company_isolation_are_centralized(): void
    {
        [$company] = $this->tenant();
        [$other] = $this->tenant('Otra');
        $service = app(CompanyLicenseService::class);
        $license = $service->ensure($company);
        $otherLicense = $service->ensure($other);
        $license->update(['user_limit' => 1, 'branch_limit' => 1]);
        try {
            $service->assertCapacity($company, 'users');
            $this->fail('El límite debía bloquear.');
        } catch (ValidationException) {
        }
        $this->assertSame('trial', $otherLicense->fresh()->status);
        $this->assertDatabaseCount('company_licenses', 2);
    }

    public function test_license_ui_is_responsive_and_keeps_modules_separate(): void
    {
        [$company] = $this->tenant();
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $this->actingAs($admin)->get(route('platform.companies.show', $company))->assertOk()->assertSee('Contrato efectivo')->assertSee('Módulos del contrato efectivo')->assertSee('grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3', false)->assertSee('overflow-x-auto', false);
    }

    private function tenant(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$company->id, 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }
}
