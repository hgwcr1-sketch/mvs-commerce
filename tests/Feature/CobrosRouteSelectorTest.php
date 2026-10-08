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

class CobrosRouteSelectorTest extends TestCase
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

    /** @return array{0: Company, 1: User} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Selector '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        return [$company, $this->member($company, ['cobros.acceder', 'cobros.cobrar'])];
    }

    private function routeFor(Company $company, User $collector, string $name, bool $active = true, bool $licensed = true): CobrosRoute
    {
        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => $name, 'active' => $active]);
        $route->users()->attach($collector->id, ['active' => true]);
        if ($licensed) {
            app(CollectorLicenseService::class)->renewForRoute($route, $company,
                User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), now()->addMonth());
        }

        return $route;
    }

    private function assignmentFor(Company $company, CobrosRoute $route, User $collector, string $name): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => $name, 'customer_type' => 'individual', 'is_active' => true]);
        CobrosAssignment::create(['company_id' => $company->id, 'cobros_route_id' => $route->id,
            'customer_id' => $customer->id, 'collector_user_id' => $collector->id,
            'assigned_by' => $collector->id, 'assigned_at' => now()]);

        return $customer;
    }

    public function test_single_available_route_auto_enters_without_selector(): void
    {
        [$company, $collector] = $this->context();
        $only = $this->routeFor($company, $collector, 'San Ramón');

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertDontSee('Seleccione su ruta')
            ->assertSee('Ruta: San Ramón');

        $this->assertSame($only->id, (int) session('active_cobros_route_id'));
    }

    public function test_multiple_routes_show_selector_and_selection_persists(): void
    {
        [$company, $collector] = $this->context();
        $this->routeFor($company, $collector, 'San Ramón');
        $palmares = $this->routeFor($company, $collector, 'Palmares');

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertRedirect(route('cobros.routes.select'));

        $this->get(route('cobros.routes.select'))->assertOk()
            ->assertSee('Seleccione su ruta')->assertSee('San Ramón')->assertSee('Palmares');

        $this->post(route('cobros.routes.store'), ['route_id' => $palmares->id])
            ->assertRedirect(route('cobros.collector.portfolio'));

        $this->assertSame($palmares->id, (int) session('active_cobros_route_id'));
        $this->get(route('cobros.collector.portfolio'))->assertOk()->assertSee('Ruta: Palmares')
            ->assertSee('Cambiar ruta');
    }

    public function test_cambiar_ruta_link_visible_only_with_multiple_routes(): void
    {
        [$company, $collector] = $this->context();
        $this->routeFor($company, $collector, 'Única');

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()->assertDontSee('Cambiar ruta');
    }

    public function test_foreign_inactive_unlicensed_and_unassigned_routes_are_rejected(): void
    {
        [$foreignCompany, $foreignCollector] = $this->context();
        $foreignRoute = $this->routeFor($foreignCompany, $foreignCollector, 'Ruta secreta');

        [$company, $collector] = $this->context();
        $inactive = $this->routeFor($company, $collector, 'Inactiva', active: false);
        $unlicensed = $this->routeFor($company, $collector, 'Sin licencia', licensed: false);
        $active = $this->routeFor($company, $collector, 'Válida');
        $active2 = $this->routeFor($company, $collector, 'Válida 2');
        $other = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $unassigned = $this->routeFor($company, $other, 'De otro cobrador');

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        foreach ([$foreignRoute->id, $inactive->id, $unlicensed->id, $unassigned->id] as $routeId) {
            $this->post(route('cobros.routes.store'), ['route_id' => $routeId])
                ->assertSessionHasErrors('cobros_route_id');
        }
        $this->assertNull(session('active_cobros_route_id'));

        // El selector solo lista activas, licenciadas y asignadas del usuario actual.
        $this->get(route('cobros.routes.select'))->assertOk()
            ->assertDontSee('Ruta secreta')->assertDontSee('Inactiva')
            ->assertDontSee('Sin licencia')->assertDontSee('De otro cobrador')
            ->assertSee('Válida')->assertSee('Válida 2');

        // Con una única ruta disponible el acceso es directo, sin selector.
        $this->post(route('cobros.routes.store'), ['route_id' => $active->id])->assertRedirect();
        $this->assertSame($active->id, (int) session('active_cobros_route_id'));

        $this->post(route('cobros.routes.store'), [])->assertSessionHasErrors('route_id');
    }

    public function test_portfolio_a_never_mixes_route_b_customers(): void
    {
        [$company, $collector] = $this->context();
        $routeA = $this->routeFor($company, $collector, 'San Ramón');
        $routeB = $this->routeFor($company, $collector, 'Palmares');
        $clientA = $this->assignmentFor($company, $routeA, $collector, 'Cliente Sur '.uniqid());
        $clientB = $this->assignmentFor($company, $routeB, $collector, 'Cliente Norte '.uniqid());

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.routes.store'), ['route_id' => $routeA->id])->assertRedirect();

        $this->get(route('cobros.collector.portfolio'))->assertOk()
            ->assertSee($clientA->name)->assertDontSee($clientB->name);

        $this->post(route('cobros.routes.store'), ['route_id' => $routeB->id])->assertRedirect();
        $this->get(route('cobros.collector.portfolio'))->assertOk()
            ->assertSee($clientB->name)->assertDontSee($clientA->name);
    }

    public function test_owner_and_supervisor_are_never_trapped_in_selector(): void
    {
        [$company] = $this->context();
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);
        $supervisor = $this->member($company, ['cobros.acceder', 'cobros.supervisar']);

        foreach ([$owner, $supervisor] as $user) {
            // Nunca cae en el selector: su landing de gestión redirige al panel propio.
            $this->actingAs($user)->withSession(['active_company_id' => $company->id])
                ->get(route('cobros.index'))
                ->assertRedirect(match (true) {
                    $user->hasPermission('cobros.portal_dueno', $company) => route('cobros.owner.dashboard'),
                    default => route('cobros.supervisor.index'),
                });
            $this->assertNull(session('active_cobros_route_id'));
        }
    }
}