<?php

namespace Tests\Feature;

use App\Models\CobrosAssignment;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosOwnerRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function context(string $mode = 'company_cupo'): array
    {
        $company = Company::create(['trade_name' => 'Rutas '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);
        $company->modules()->where('module_key', 'cobros')->update(['license_mode' => $mode]);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);

        return [$company, $owner, $collector, $admin];
    }

    public function test_owner_creates_reads_edits_deactivates_and_reassigns_without_branches(): void
    {
        [$company, $owner, $collector] = $this->context();
        $other = $this->member($company, ['cobros.cobrar']);
        $this->get(route('cobros.owner.routes.create'))->assertOk()->assertDontSee('Sucursal');
        foreach (['San Ramón', 'Palmares'] as $name) {
            $this->post(route('cobros.owner.routes.store'), ['name' => $name, 'active' => 1, 'collector_ids' => [$collector->id]])
                ->assertRedirect(route('cobros.owner.routes'))->assertSessionHasNoErrors();
        }
        $route = CobrosRoute::where('name', 'San Ramón')->firstOrFail();
        $second = CobrosRoute::where('name', 'Palmares')->firstOrFail();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'customer_type' => 'individual', 'is_active' => true]);
        CobrosAssignment::create(['company_id' => $company->id, 'cobros_route_id' => $route->id, 'customer_id' => $customer->id, 'collector_user_id' => $collector->id, 'assigned_by' => $owner->id, 'assigned_at' => now()]);
        $this->get(route('cobros.owner.routes'))->assertOk()->assertSee('San Ramón')->assertSee($collector->name)
            ->assertSee('Pendiente de activación MVS')->assertSee('Clientes vinculados:')
            ->assertViewHas('routes', fn ($routes) => $routes->firstWhere('id', $route->id)->assignments_count === 1);
        $this->get(route('cobros.owner.routes.edit', $route))->assertOk()->assertSee('San Ramón');
        $this->put(route('cobros.owner.routes.update', $route), ['name' => 'Naranjo', 'active' => 0, 'collector_ids' => [$other->id]])
            ->assertRedirect(route('cobros.owner.routes'))->assertSessionHasNoErrors();
        $this->assertSame('Naranjo', $route->fresh()->name);
        $this->assertFalse($route->fresh()->active);
        $this->assertSame([$other->id], $route->fresh()->users->modelKeys());
        $this->assertSame([$collector->id], $second->fresh()->users->modelKeys());
        $this->assertDatabaseCount('branch_user', 0);
        $this->put(route('cobros.owner.routes.update', $route), ['name' => 'Naranjo', 'active' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($route->fresh()->active);
        $this->assertCount(0, $route->fresh()->users);
        $this->assertDatabaseHas('cobros_assignments', ['id' => $customer->id, 'collector_user_id' => $collector->id]);
    }

    public function test_both_commercial_modes_never_grant_or_modify_licenses(): void
    {
        foreach (['individual', 'company_cupo'] as $mode) {
            [$company, , $collector, $admin] = $this->context($mode);
            $this->post(route('cobros.owner.routes.store'), ['name' => 'Nueva', 'active' => 1, 'collector_ids' => [$collector->id], 'status' => 'active'])
                ->assertSessionHasNoErrors()->assertRedirect();
            $route = CobrosRoute::forCompany($company->id)->firstOrFail();
            $this->assertNull($route->license);
            $license = app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
            $before = $license->fresh()->getRawOriginal();
            $this->put(route('cobros.owner.routes.update', $route), ['name' => 'Renombrada', 'active' => 0])->assertSessionHasNoErrors();
            $this->assertSame($before, $license->fresh()->getRawOriginal());
            $this->assertSame($mode, $company->modules()->where('module_key', 'cobros')->value('license_mode'));
        }
        $this->assertDatabaseCount('cobros_collector_licenses', 0);
    }

    public function test_foreign_routes_and_collectors_are_rejected_and_company_input_is_ignored(): void
    {
        [$foreignCompany, , $foreignCollector] = $this->context();
        $foreign = CobrosRoute::create(['company_id' => $foreignCompany->id, 'name' => 'Ruta secreta']);
        [$company, , $collector] = $this->context();
        $this->get(route('cobros.owner.routes'))->assertOk()->assertDontSee('Ruta secreta')->assertDontSee($foreignCollector->name);
        $this->get(route('cobros.owner.routes.edit', $foreign))->assertNotFound();
        $this->put(route('cobros.owner.routes.update', $foreign), ['name' => 'Ataque', 'active' => 1])->assertNotFound();
        $this->post(route('cobros.owner.routes.store'), ['name' => 'No guardar', 'active' => 1, 'collector_ids' => [$foreignCollector->id]])
            ->assertSessionHasErrors('collector_ids');
        $this->assertDatabaseMissing('cobros_routes', ['name' => 'No guardar']);
        $this->post(route('cobros.owner.routes.store'), ['company_id' => $foreignCompany->id, 'name' => 'Local', 'active' => 1, 'collector_ids' => [$collector->id]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cobros_routes', ['company_id' => $company->id, 'name' => 'Local']);
        $route = CobrosRoute::where('name', 'Local')->firstOrFail();
        $this->put(route('cobros.owner.routes.update', $route), ['name' => 'No cambiar', 'active' => 0, 'collector_ids' => [$foreignCollector->id]])->assertSessionHasErrors('collector_ids');
        $this->assertSame('Local', $route->fresh()->name);
        $this->assertSame([$collector->id], $route->fresh()->users->modelKeys());
    }

    public function test_collector_cannot_access_any_route_management_endpoint(): void
    {
        [$company, , $collector] = $this->context();
        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Protegida']);
        $this->actingAs($collector);
        $this->get(route('cobros.owner.routes'))->assertForbidden();
        $this->get(route('cobros.owner.routes.create'))->assertForbidden();
        $this->get(route('cobros.owner.routes.edit', $route))->assertForbidden();
        $this->post(route('cobros.owner.routes.store'), ['name' => 'No', 'active' => 1])->assertForbidden();
        $this->put(route('cobros.owner.routes.update', $route), ['name' => 'No', 'active' => 0])->assertForbidden();
        $this->assertDatabaseCount('cobros_routes', 1);
        $this->assertSame('Protegida', $route->fresh()->name);
    }

    public function test_invalid_name_inactive_user_and_non_collector_do_not_write(): void
    {
        [$company, $owner, $collector] = $this->context();
        $collector->update(['is_active' => false]);
        foreach ([$owner->id, $collector->id] as $id) {
            $this->post(route('cobros.owner.routes.store'), ['name' => 'No', 'active' => 1, 'collector_ids' => [$id]])->assertSessionHasErrors('collector_ids');
        }
        $this->post(route('cobros.owner.routes.store'), ['name' => ' ', 'active' => 1])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('cobros_routes', 0);
        CobrosRoute::create(['company_id' => $company->id, 'name' => 'Única']);
        $this->post(route('cobros.owner.routes.store'), ['name' => 'Única', 'active' => 1])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('cobros_routes', 1);
    }
}
