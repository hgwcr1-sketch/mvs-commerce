<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CobrosAssignment;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Cobros\PortfolioAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosVisitTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_schedules_visit_for_assigned_customer(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');
        $tomorrow = today($c->timezone)->addDay()->toDateString();

        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), [
                'scheduled_for' => $tomorrow,
                'notes' => 'Cobro de cuota',
            ])
            ->assertRedirect('/')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cobros_visits', [
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $mario->id, 'status' => 'pendiente',
        ]);
    }

    public function test_schedule_requires_explicit_date_and_always_forces_pending(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');

        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), ['scheduled_for' => ''])
            ->assertSessionHasErrors('scheduled_for');

        // El navegador NO manda estado/promesa al programar: se fuerza pendiente.
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), [
                'scheduled_for' => today($c->timezone)->toDateString(),
                'status' => 'promesa_pago',
                'promise_date' => today($c->timezone)->addDays(3)->toDateString(),
                'promise_amount' => '999',
            ])->assertSessionHasNoErrors();

        $visit = \App\Models\CobrosVisit::query()->where('company_id', $c->id)
            ->where('customer_id', $customer->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('pendiente', $visit->status);
        $this->assertNull($visit->promise_date);
        $this->assertNull($visit->promise_amount);
    }

    public function test_today_agenda_shows_only_today_visits_not_whole_portfolio(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');
        $today = today($c->timezone)->toDateString();
        $this->postVisit($mario, $c, $customer, $today);
        [$other] = $this->extraCustomer($c, $b, $mario);
        $this->postVisit($mario, $c, $other, today($c->timezone)->addDay()->toDateString());

        $response = $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertDontSee($other->name)
            ->assertViewHas('visits', fn ($rows) => $rows->count() === 1);
    }

    public function test_status_transitions_with_fast_controls(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');
        $visit = $this->postVisit($mario, $c, $customer, today($c->timezone)->toDateString());

        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), ['status' => 'no_localizado', 'notes' => 'Cerrado'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cobros_visits', ['id' => $visit->id, 'status' => 'no_localizado']);
        $this->assertNotNull($visit->fresh()->visited_at);

        // Promesa exige fecha; monto opcional informativo.
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), ['status' => 'promesa_pago'])
            ->assertSessionHasErrors('promise_date');
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), ['status' => 'promesa_pago', 'promise_date' => today($c->timezone)->addDays(3)->toDateString(), 'promise_amount' => '12500.50'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cobros_visits', ['id' => $visit->id, 'status' => 'promesa_pago']);
        $this->assertTrue(bccomp((string) $visit->fresh()->promise_amount, '12500.5000', 4) === 0);
    }

    public function test_reschedule_is_traced_and_keeps_single_pending_per_customer(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');
        $visit = $this->postVisit($mario, $c, $customer, today($c->timezone)->toDateString());
        $newDate = today($c->timezone)->addDays(2)->toDateString();

        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), ['status' => 'reprogramada', 'scheduled_for' => $newDate, 'notes' => 'Cliente pidió jueves'])
            ->assertSessionHasNoErrors();

        // La reprogramación crea la visita futura y cierra la original con trazabilidad.
        $this->assertDatabaseHas('cobros_visits', ['id' => $visit->id, 'status' => 'reprogramada']);
        $newVisit = \App\Models\CobrosVisit::query()->where('company_id', $c->id)->where('customer_id', $customer->id)->where('status', 'pendiente')->first();
        $this->assertNotNull($newVisit);
        $this->assertSame($newDate, $newVisit->scheduled_for->toDateString());
        $this->assertDatabaseHas('cobros_visit_logs', [
            'company_id' => $c->id, 'visit_id' => $visit->id,
            'from_status' => 'pendiente', 'to_status' => 'reprogramada', 'actor_user_id' => $mario->id,
        ]);
    }

    public function test_customer_history_is_chronological_and_isolated(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');
        $visited = $this->postVisit($mario, $c, $customer, today($c->timezone)->subDay()->toDateString(), ['notes' => 'Primera']);
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visited), ['status' => 'visitado'])->assertSessionHasNoErrors();
        $this->postVisit($mario, $c, $customer, today($c->timezone)->toDateString());

        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.customer', $customer))
            ->assertOk()
            ->assertViewHas('visits', fn ($rows) => $rows->count() === 2
                && $rows->first()->scheduled_for->toDateString() === today($c->timezone)->toDateString());
    }

    public function test_idor_foreign_unassigned_and_cross_company_return_404(): void
    {
        [$cA, $bA, $mario, $customerA] = $this->contextWithCustomer('Mario');
        [$cB, $bB, $juan, $customerB] = $this->contextWithCustomer('Juan');
        $unassigned = Customer::create(['company_id' => $cA->id, 'customer_type' => 'individual', 'name' => 'Libre '.uniqid(), 'is_active' => true]);
        $visitB = $this->postVisit($juan, $cB, $customerB, today($cB->timezone)->toDateString());

        foreach ([$customerB, $unassigned] as $foreign) {
            $this->actingAs($mario)->withSession(['active_company_id' => $cA->id])
                ->post(route('cobros.visits.store', $foreign), ['scheduled_for' => today($cA->timezone)->toDateString()])
                ->assertNotFound();
            $this->actingAs($mario)->withSession(['active_company_id' => $cA->id])
                ->get(route('cobros.visits.customer', $foreign))->assertNotFound();
        }

        // Visita ajena: ni ver ni modificar; collector_id del request ignorado.
        $this->actingAs($mario)->withSession(['active_company_id' => $cA->id])
            ->patch(route('cobros.visits.update', $visitB), ['status' => 'visitado', 'collector_id' => $juan->id])
            ->assertNotFound();
        $this->assertDatabaseHas('cobros_visits', ['id' => $visitB->id, 'status' => 'pendiente']);
    }

    public function test_reassignment_closes_pending_visits_without_silent_transfer(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');
        $visit = $this->postVisit($mario, $c, $customer, today($c->timezone)->addDay()->toDateString());

        [$juan] = $this->extraCollector($c, $b);
        app(PortfolioAssignmentService::class)->assign($c, $customer, $juan, $this->platformAdmin());

        // Historial preservado; la visita pendiente NO se transfiere silenciosamente.
        $this->assertDatabaseHas('cobros_visits', ['id' => $visit->id, 'collector_user_id' => $mario->id, 'status' => 'reprogramada']);
        $this->assertDatabaseMissing('cobros_visits', ['customer_id' => $customer->id, 'collector_user_id' => $juan->id, 'status' => 'pendiente']);
        // Juan puede programar su propia visita nueva desde cero.
        $this->postVisit($juan, $c, $customer, today($c->timezone)->addDays(2)->toDateString());
        $this->assertDatabaseHas('cobros_visits', ['customer_id' => $customer->id, 'collector_user_id' => $juan->id, 'status' => 'pendiente']);
    }

    public function test_permissions_and_read_only_financial_snapshots(): void
    {
        [$c, $b, $mario, $customer] = $this->contextWithCustomer('Mario');
        $today = today($c->timezone)->toDateString();
        $visit = $this->postVisit($mario, $c, $customer, $today);

        // Sin cobros.cobrar: prohibido gestionar.
        [$c3, $b3, $noPerm] = $this->context(['cobros.acceder']);
        $this->actingAs($noPerm)->withSession(['active_company_id' => $c3->id])
            ->get(route('cobros.visits.today'))->assertForbidden();

        // Invitado prohibido.
        $this->get(route('cobros.visits.today'))->assertForbidden();

        // Supervisor consulta agenda (solo lectura): sin patch exitoso sobre visita ajena.
        [$sup] = $this->extraCollector($c, $b, ['cobros.acceder', 'cobros.supervisar']);
        $this->actingAs($sup)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), ['status' => 'visitado'])
            ->assertForbidden();
        $this->assertDatabaseHas('cobros_visits', ['id' => $visit->id, 'status' => 'pendiente']);

        // Nada financiero fue tocado.
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('cobros_assignments', 1);
    }

    // ------------------------------------------------------------------

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Visitas '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'V'.random_int(1, 99), 'is_active' => true]);
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

    /** @return array{0: Company, 1: Branch, 2: User, 3: Customer} */
    private function contextWithCustomer(string $who): array
    {
        [$c, $b, $u] = $this->context();
        [$customer] = $this->extraCustomer($c, $b, $u, $who);

        return [$c, $b, $u, $customer];
    }

    private function extraCustomer(Company $c, Branch $b, User $u, string $who = 'Cliente'): array
    {
        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => $who.' '.uniqid(), 'is_active' => true]);
        app(PortfolioAssignmentService::class)->assign($c, $customer, $u, $this->platformAdmin());

        return [$customer];
    }

    private function extraCollector(Company $c, Branch $b, array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        [$c2, $b2, $user] = $this->context($permissions);
        // Mover el usuario/branch/suscripciones al contexto de la empresa real de la prueba.
        $user->companies()->wherePivot('company_id', $c2->id)->detach();
        $user->companies()->attach($c->id, ['role_id' => Role::create(['company_id' => $c->id, 'name' => 'Rol '.uniqid(), 'is_active' => true])->id]);
        $user->branches()->wherePivot('branch_id', $b2->id)->detach();
        $user->branches()->attach($b->id);
        $role = $user->roleInCompany($c);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $i => $name) {
            if (in_array($name, $permissions)) {
                $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
            }
        }

        return [$user->fresh()];
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    private function postVisit(User $u, Company $c, Customer $customer, string $date, array $extra = []): \App\Models\CobrosVisit
    {
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), ['scheduled_for' => $date] + $extra)
            ->assertSessionHasNoErrors();

        return \App\Models\CobrosVisit::query()
            ->where('company_id', $c->id)->where('customer_id', $customer->id)
            ->where('collector_user_id', $u->id)->orderByDesc('id')->firstOrFail();
    }
}
