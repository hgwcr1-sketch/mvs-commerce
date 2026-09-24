<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Cobros\PortfolioAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosGeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_saves_valid_location_on_assigned_customer_without_marking_validated(): void
    {
        [$c, $u, $customer] = $this->context();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.portfolio.location', $customer), [
                'latitude' => '9.93, ', 'longitude' => '-84.08',
            ])->assertSessionHasErrors('latitude');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.portfolio.location', $customer), [
                'latitude' => '9.931', 'longitude' => '-84.082',
                'location_reference' => 'Frente al parque',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $customer = $customer->fresh();
        $this->assertTrue($customer->hasLocation());
        $this->assertSame('9.93100000', $customer->latitude);
        $this->assertSame('Frente al parque', $customer->location_reference);
        // Capturar != validar: el cobrador no marca location_validated_*.
        $this->assertNull($customer->location_validated_at);
        $this->assertNull($customer->location_validated_by);
    }

    public function test_save_location_unassigned_and_cross_company_return_404_and_ignore_collector_id(): void
    {
        [$cA, $uA, $customerA] = $this->context();
        [$cB, $uB, $customerB] = $this->context();
        $unassigned = Customer::create(['company_id' => $cA->id, 'customer_type' => 'individual', 'name' => 'Libre '.uniqid(), 'is_active' => true]);

        foreach ([$customerB, $unassigned] as $foreign) {
            $this->actingAs($uA)->withSession(['active_company_id' => $cA->id])
                ->post(route('cobros.collector.portfolio.location', $foreign), [
                    'latitude' => '10.0', 'longitude' => '-84.1', 'collector_id' => $uA->id,
                ])->assertNotFound();
        }

        $this->assertNull($customerB->fresh()->latitude);
        $this->assertNull($unassigned->fresh()->latitude);
        // collector_id del request no altera autoridad: la asignación real manda.
        $this->assertTrue($customerA->fresh()->latitude === null);
    }

    public function test_ficha_shows_navigate_or_save_location_and_never_technical_coords(): void
    {
        [$c, $u, $customer] = $this->context();

        $without = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))->getContent();
        $this->assertStringContainsString('GUARDAR UBICACIÓN ACTUAL', $without);
        $this->assertStringNotContainsString('IR AL CLIENTE', $without);

        $customer->update(['latitude' => '9.93', 'longitude' => '-84.08']);
        $with = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))->getContent();
        $this->assertStringContainsString('IR AL CLIENTE', $with);
        $this->assertStringContainsString('data-nav-app="waze"', $with);
        $this->assertStringContainsString('data-nav-app="google"', $with);
        $this->assertStringContainsString('data-nav-app="apple"', $with);
        // Sin números de coordenadas crudos visibles como texto técnico al cobrador.
        $this->assertStringNotContainsString('Latitud', $with);
        $this->assertStringNotContainsString('Longitud', $with);
    }

    public function test_management_snapshot_is_optional_and_does_not_touch_customer_or_money(): void
    {
        [$c, $u, $customer] = $this->context();
        $customer->update(['latitude' => '9.93', 'longitude' => '-84.08']);
        $before = $customer->fresh();

        $visit = $this->postVisit($u, $c, $customer);

        // Sin snapshot: la gestión funciona normal.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), ['status' => 'no_localizado'])
            ->assertSessionHasNoErrors();
        $fresh = $visit->fresh();
        $this->assertSame('no_localizado', $fresh->status);
        $this->assertNull($fresh->latitude);
        $this->assertNull($fresh->location_captured_at);

        // Con snapshot: evidencia en la visita, Customer y dinero intactos.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), [
                'status' => 'visitado',
                'latitude' => '9.94', 'longitude' => '-84.09',
            ])->assertSessionHasNoErrors();
        $fresh = $visit->fresh();
        $this->assertSame('visitado', $fresh->status);
        $this->assertNotNull($fresh->latitude);
        $this->assertNotNull($fresh->location_captured_at);

        $after = $customer->fresh();
        $this->assertSame($before->latitude, $after->latitude);
        $this->assertSame($before->longitude, $after->longitude);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    public function test_invalid_latitude_ranges_are_rejected_and_supervisor_cannot_save(): void
    {
        [$c, $b, $u, $customer] = $this->contextWithBranch();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.portfolio.location', $customer), [
                'latitude' => '999', 'longitude' => '-84.08',
            ])->assertSessionHasErrors('latitude');

        [$sup] = $this->extraCollector($c, $b, ['cobros.acceder', 'cobros.supervisar']);
        $this->actingAs($sup)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.portfolio.location', $customer), [
                'latitude' => '9.93', 'longitude' => '-84.08',
            ])->assertForbidden();
        $this->assertNull($customer->fresh()->latitude);
    }

    // ------------------------------------------------------------------

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        [$c, $b, $u] = $this->base($permissions);
        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Geo '.uniqid(), 'is_active' => true]);
        app(PortfolioAssignmentService::class)->assign($c, $customer, $u, $this->platformAdmin());

        return [$c, $u, $customer];
    }

    private function contextWithBranch(): array
    {
        [$c, $b, $u] = $this->base();
        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Geo '.uniqid(), 'is_active' => true]);
        app(PortfolioAssignmentService::class)->assign($c, $customer, $u, $this->platformAdmin());

        return [$c, $b, $u, $customer];
    }

    private function base(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Geo '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'G'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, $this->platformAdmin(), ['cobros']);

        return [$company->fresh(), $branch, $user];
    }

    private function extraCollector(Company $c, Branch $b, array $permissions): array
    {
        [$c2, $b2, $user] = $this->base($permissions);
        $user->companies()->wherePivot('company_id', $c2->id)->detach();
        $user->companies()->attach($c->id, ['role_id' => Role::create(['company_id' => $c->id, 'name' => 'Rol '.uniqid(), 'is_active' => true])->id]);
        $user->branches()->wherePivot('branch_id', $b2->id)->detach();
        $user->branches()->attach($b->id);
        $role = $user->roleInCompany($c);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }

        return [$user->fresh()];
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    private function postVisit(User $u, Company $c, Customer $customer): \App\Models\CobrosVisit
    {
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), [
                'scheduled_for' => today($c->timezone)->toDateString(),
            ])->assertSessionHasNoErrors();

        return \App\Models\CobrosVisit::query()
            ->where('company_id', $c->id)->where('customer_id', $customer->id)
            ->where('collector_user_id', $u->id)->orderByDesc('id')->firstOrFail();
    }
}
