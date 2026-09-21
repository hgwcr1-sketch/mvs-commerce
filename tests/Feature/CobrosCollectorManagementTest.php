<?php

namespace Tests\Feature;

use App\Models\{Branch, Company, Permission, Role, User};
use App\Services\{CompanyCashSettingsProvisioner, CompanyLicenseService, Cobros\CollectorManagementService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CobrosCollectorManagementTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | CUPO MODE: Owner can create collectors
     | --------------------------------------------------------------------- */

    public function test_cupo_mode_owner_can_see_collectors_page(): void
    {
        [$company, $admin, $owner] = $this->cupoContext();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.collectors'))->assertOk()
            ->assertSee('Cobradores')
            ->assertSee('Registrar cobrador')
            ->assertSee('0 / 3');
    }

    public function test_cupo_mode_owner_can_create_collector(): void
    {
        [$company, $admin, $owner] = $this->cupoContext();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Juan Cobrador',
                'email' => 'juan@test.com',
                'password' => 'password123',
            ])->assertRedirect();

        $collector = User::where('email', 'juan@test.com')->first();
        $this->assertNotNull($collector);
        $this->assertTrue($collector->hasPermission('cobros.cobrar', $company));
    }

    public function test_cupo_mode_owner_can_activate_deactivate_collector(): void
    {
        [$company, $admin, $collector, $owner] = $this->cupoContextWithCollector();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->patch(route('cobros.owner.collectors.toggle', $collector))
            ->assertRedirect();

        $this->assertFalse($collector->fresh()->is_active);

        $this->patch(route('cobros.owner.collectors.toggle', $collector->fresh()))
            ->assertRedirect();

        $this->assertTrue($collector->fresh()->is_active);
    }

    public function test_cupo_mode_blocks_when_limit_reached(): void
    {
        [$company, $admin, $owner] = $this->cupoContext(['collector_limit' => 2]);
        $service = app(CollectorManagementService::class);

        $service->createCollector($company, [
            'name' => 'Cobrador 0',
            'email' => 'cob0@test.com',
            'password' => 'password123',
        ], $admin);

        $service->createCollector($company, [
            'name' => 'Cobrador 1',
            'email' => 'cob1@test.com',
            'password' => 'password123',
        ], $admin);

        $this->assertFalse($service->canCreateCollector($company));
        $this->assertSame(0, $service->getRemainingSlots($company));

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Extra',
                'email' => 'extra@test.com',
            ])->assertSessionHasErrors('collector');
    }

    public function test_cupo_mode_can_create_after_deactivation_frees_slot(): void
    {
        [$company, $admin, $collector, $owner] = $this->cupoContextWithCollector(['collector_limit' => 1]);
        $service = app(CollectorManagementService::class);

        $this->assertFalse($service->canCreateCollector($company));

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->patch(route('cobros.owner.collectors.toggle', $collector))
            ->assertRedirect();

        $this->assertTrue($service->canCreateCollector($company));
    }

    /* ---------------------------------------------------------------------
     | INDIVIDUAL MODE: Owner is read-only
     | --------------------------------------------------------------------- */

    public function test_individual_mode_owner_sees_read_only_view(): void
    {
        [$company, $admin, $owner] = $this->individualContext();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.collectors'))->assertOk()
            ->assertSee('Licencias individuales')
            ->assertSee('habilitados individualmente')
            ->assertDontSee('Registrar cobrador');
    }

    public function test_individual_mode_owner_cannot_create(): void
    {
        [$company, $admin, $owner] = $this->individualContext();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Test',
                'email' => 'test@test.com',
            ])->assertStatus(403);
    }

    public function test_individual_mode_owner_cannot_toggle(): void
    {
        [$company, $admin, $collector, $owner] = $this->individualContextWithCollector();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->patch(route('cobros.owner.collectors.toggle', $collector))
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------------------
     | PLATFORM ADMIN: collector info visible
     | --------------------------------------------------------------------- */

    public function test_platform_admin_sees_collector_count_in_show(): void
    {
        [$company, $admin] = $this->cupoContext();
        $service = app(CollectorManagementService::class);
        $service->createCollector($company, [
            'name' => 'Test Collector',
            'email' => 'tc@test.com',
            'password' => 'password123',
        ], $admin);

        $this->actingAs($admin)
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Cobradores CobrOS')
            ->assertSee('1');
    }

    /* ---------------------------------------------------------------------
     | PLATFORM ADMIN: individual mode management
     | --------------------------------------------------------------------- */

    public function test_platform_admin_sees_individual_collector_section(): void
    {
        [$company, $admin] = $this->individualContext();

        $this->actingAs($admin)
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertSee('Cobradores con licencia individual')
            ->assertSee('Registrar cobrador')
            ->assertSee('0');
    }

    public function test_platform_admin_can_create_collector_in_individual_mode(): void
    {
        [$company, $admin] = $this->individualContext();

        $this->actingAs($admin)
            ->post(route('platform.collectors.store', $company), [
                'name' => 'Cobrador Individual',
                'email' => 'ci@test.com',
                'password' => 'password123',
            ])->assertRedirect();

        $collector = User::where('email', 'ci@test.com')->first();
        $this->assertNotNull($collector);
        $this->assertTrue($collector->hasPermission('cobros.cobrar', $company));
    }

    public function test_platform_admin_can_toggle_collector_in_individual_mode(): void
    {
        [$company, $admin, $collector] = $this->individualContextWithCollector();

        $this->actingAs($admin)
            ->patch(route('platform.collectors.toggle', [$company, $collector]))
            ->assertRedirect();

        $this->assertFalse($collector->fresh()->is_active);

        $this->patch(route('platform.collectors.toggle', [$company, $collector->fresh()]))
            ->assertRedirect();

        $this->assertTrue($collector->fresh()->is_active);
    }

    public function test_platform_admin_can_manage_branches_in_individual_mode(): void
    {
        [$company, $admin, $collector] = $this->individualContextWithCollector();
        $branch = $company->branches->first();

        $this->actingAs($admin)
            ->put(route('platform.collectors.branches', [$company, $collector]), [
                'branch_ids' => [$branch->id],
            ])->assertRedirect();

        $this->assertTrue($collector->fresh()->branches->contains($branch->id));
    }

    public function test_owner_cannot_create_collector_in_individual_mode(): void
    {
        [$company, $admin, $owner] = $this->individualContext();

        $this->actingAs($owner)
            ->post(route('platform.collectors.store', $company), [
                'name' => 'Test',
                'email' => 'test@test.com',
            ])->assertForbidden();
    }

    public function test_cupo_mode_does_not_show_individual_management(): void
    {
        [$company, $admin] = $this->cupoContext();

        $this->actingAs($admin)
            ->get(route('platform.companies.show', $company))
            ->assertOk()
            ->assertDontSee('Cobradores con licencia individual')
            ->assertDontSee('Registrar cobrador')
            ->assertSee('Cobradores CobrOS')
            ->assertSee('El dueño administra sus cobradores');
    }

    public function test_platform_admin_cannot_create_collector_in_cupo_mode(): void
    {
        [$company, $admin] = $this->cupoContext();

        $this->actingAs($admin)
            ->post(route('platform.collectors.store', $company), [
                'name' => 'Test',
                'email' => 'test@test.com',
            ])->assertStatus(422);
    }

    /* ---------------------------------------------------------------------
     | Security: tenant isolation
     | --------------------------------------------------------------------- */

    public function test_owner_cannot_manage_collectors_of_other_company(): void
    {
        [$companyA, $adminA, $ownerA] = $this->cupoContext();
        [$companyB, $adminB, $ownerB] = $this->cupoContext();

        $service = app(CollectorManagementService::class);
        $collector = $service->createCollector($companyB, [
            'name' => 'Foreign',
            'email' => 'foreign@test.com',
            'password' => 'password123',
        ], $adminB);

        $this->actingAs($ownerA)->withSession(['active_company_id' => $companyA->id])
            ->patch(route('cobros.owner.collectors.toggle', $collector))
            ->assertStatus(404);
    }

    public function test_guest_cannot_access_collectors(): void
    {
        $this->get(route('cobros.owner.collectors'))->assertRedirect(route('cobros.login'));
    }

    public function test_collector_without_portal_dueno_cannot_access(): void
    {
        [$company, $admin, $collector, $owner] = $this->cupoContextWithCollector();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.collectors'))->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Service unit tests
     | --------------------------------------------------------------------- */

    public function test_service_get_collector_count_only_counts_active_cobradores(): void
    {
        [$company, $admin] = $this->cupoContext();
        $service = app(CollectorManagementService::class);

        $service->createCollector($company, ['name' => 'A', 'email' => 'a@t.com', 'password' => 'pass1234'], $admin);
        $service->createCollector($company, ['name' => 'B', 'email' => 'b@t.com', 'password' => 'pass1234'], $admin);

        $this->assertSame(2, $service->getCollectorCount($company));

        $inactive = User::where('email', 'a@t.com')->first();
        $inactive->update(['is_active' => false]);

        $this->assertSame(1, $service->getCollectorCount($company));
    }

    public function test_service_validate_email_prevents_duplicate_collector(): void
    {
        [$company, $admin] = $this->cupoContext();
        $service = app(CollectorManagementService::class);

        $service->createCollector($company, ['name' => 'A', 'email' => 'dup@t.com', 'password' => 'pass1234'], $admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->createCollector($company, ['name' => 'B', 'email' => 'dup@t.com', 'password' => 'pass1234'], $admin);
    }

    public function test_service_create_collector_with_existing_user(): void
    {
        [$company, $admin] = $this->cupoContext();
        $service = app(CollectorManagementService::class);

        $existing = User::factory()->create(['email' => 'exist@t.com', 'is_active' => true]);

        $result = $service->createCollector($company, [
            'name' => $existing->name,
            'email' => 'exist@t.com',
            'password' => 'password123',
        ], $admin);

        $this->assertSame($existing->id, $result->id);
        $this->assertTrue($result->hasPermission('cobros.cobrar', $company));
    }

    public function test_service_assign_branches_validates_company_scope(): void
    {
        [$company, $admin, $collector, $owner] = $this->cupoContextWithCollector();
        $service = app(CollectorManagementService::class);

        $otherCompany = Company::create(['trade_name' => 'Other', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $foreignBranch = Branch::create(['company_id' => $otherCompany->id, 'name' => 'Foreign', 'code' => 'F', 'is_active' => true]);
        $service->assignBranches($collector, $company, [$foreignBranch->id]);

        $this->assertFalse($collector->fresh()->branches->contains($foreignBranch->id));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | --------------------------------------------------------------------- */

    private function setupCompany(string $name = 'Test'): array
    {
        $company = Company::create([
            'trade_name' => $name.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);

        return [$company, $admin];
    }

    private function setupOwner(Company $company): User
    {
        Permission::firstOrCreate(['name' => 'cobros.portal_dueno'], ['label' => 'Portal Dueño', 'module' => 'CobrOS', 'is_active' => true]);
        Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'Acceder', 'module' => 'CobrOS', 'is_active' => true]);
        Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'Cobrar', 'module' => 'CobrOS', 'is_active' => true]);

        $owner = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño'.uniqid(), 'is_active' => true]);
        $ownerPortal = Permission::where('name', 'cobros.portal_dueno')->first();
        $ownerAcceder = Permission::where('name', 'cobros.acceder')->first();
        $role->permissions()->attach([$ownerPortal->id, $ownerAcceder->id]);
        $owner->companies()->syncWithoutDetaching([$company->id => ['role_id' => $role->id]]);

        return $owner;
    }

    private function cupoContext(array $overrides = []): array
    {
        [$company, $admin] = $this->setupCompany();
        $owner = $this->setupOwner($company);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => $overrides['collector_limit'] ?? 3,
                'admin_license_charged' => false,
            ]);

        $company->refresh();

        return [$company, $admin, $owner];
    }

    private function individualContext(): array
    {
        [$company, $admin] = $this->setupCompany();
        $owner = $this->setupOwner($company);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ]);

        $company->refresh();

        return [$company, $admin, $owner];
    }

    private function cupoContextWithCollector(array $overrides = []): array
    {
        [$company, $admin, $owner] = $this->cupoContext($overrides);
        $service = app(CollectorManagementService::class);
        $collector = $service->createCollector($company, [
            'name' => 'Cobrador Test',
            'email' => 'cobtest@test.com',
            'password' => 'password123',
        ], $admin);

        $company->refresh();

        return [$company, $admin, $collector, $owner];
    }

    private function individualContextWithCollector(): array
    {
        [$company, $admin, $owner] = $this->individualContext();

        $collector = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador'.uniqid(), 'is_active' => true]);
        $p1 = Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'Acceder', 'module' => 'CobrOS', 'is_active' => true]);
        $p2 = Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'Cobrar', 'module' => 'CobrOS', 'is_active' => true]);
        $role->permissions()->attach([$p1->id, $p2->id]);
        $collector->companies()->attach($company->id, ['role_id' => $role->id]);

        $company->refresh();

        return [$company, $admin, $collector, $owner];
    }
}
