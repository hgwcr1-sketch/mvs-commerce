<?php

namespace Tests\Feature;

use App\Models\CobrosCreditRequest;
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

/** 12A — semáforo de riesgo en solicitudes del dueño (listado y detalle). */
class CobrosCreditRisk12ATest extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Cobros', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: Customer} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Riesgo12A '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta 12A', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente 12A', 'is_active' => true]);

        return [$company, $collector, $route, $customer];
    }

    private function requestFor(Company $company, CobrosRoute $route, User $collector, ?Customer $customer): CobrosCreditRequest
    {
        return CobrosCreditRequest::create([
            'company_id' => $company->id,
            'cobros_route_id' => $route->id,
            'collector_user_id' => $collector->id,
            'customer_id' => $customer?->id,
            'applicant_type' => $customer ? 'existente' : 'nuevo',
            'status' => CobrosCreditRequest::STATUS_PENDING,
            'identification_type' => 'cedula',
            'identification' => '101110111',
            'name' => 'Solicitante 12A',
            'phone' => '88888888',
            'address' => 'San Ramón',
            'amount_requested' => '5000.00',
            'submitted_at' => now(),
        ]);
    }

    public function test_listado_y_detalle_del_dueno_muestran_semaforo_junto_al_nombre(): void
    {
        [$company, $collector, $route, $customer] = $this->context();
        $request = $this->requestFor($company, $route, $collector, $customer);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);

        $index = $this->get(route('cobros.owner.solicitudes.index'));
        $index->assertOk()
            ->assertSee('role="img"', false)
            ->assertSee('Estado de crédito: Al día', false)
            ->assertSee('stroke="#00B84A"', false);

        $show = $this->get(route('cobros.owner.solicitudes.show', $request->id));
        $show->assertOk()
            ->assertSee('role="img"', false)
            ->assertSee('Estado de crédito: Al día', false);
    }

    public function test_semaforo_respeta_aislamiento_entre_empresas(): void
    {
        [$companyA, $collectorA, $routeA, $customerA] = $this->context();
        [$companyB, $collectorB, $routeB, $customerB] = $this->context();
        $foreign = $this->requestFor($companyB, $routeB, $collectorB, $customerB);

        $ownerA = $this->member($companyA, ['cobros.acceder', 'cobros.portal_dueno']);
        $this->actingAs($ownerA)->withSession(['active_company_id' => $companyA->id]);

        // Solicitud ajena → 404 (IDOR), nunca renderiza su semáforo.
        $this->get(route('cobros.owner.solicitudes.show', $foreign->id))->assertNotFound();

        // El listado de A nunca incluye datos de B.
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()
            ->assertDontSee('Solicitante 12A', false);
    }
}
