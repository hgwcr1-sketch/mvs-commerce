<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosRoute;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cobros\AgendaService;
use App\Services\CompanyLicenseService;
use App\Services\Cobros\CollectorLicenseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloque 6 — Agenda automática del cobrador.
 *
 * Recurrencia operativa determinista/idempotente bajo demanda + filtros
 * HOY/MAÑANA/ESTA SEMANA/TODOS. La CxC (due_date/saldo) jamás se modifica.
 */
class CobrosAgendaTest extends TestCase
{
    use RefreshDatabase;

    private function tz(Company $c): string
    {
        return $c->timezone ?: config('app.timezone');
    }

    public function test_recurrence_next_dates_diario_semanal_quincenal_mensual_clamp(): void
    {
        $make = fn (array $data) => new CobrosAssignment($data);

        // diario: anterior + 1 día calendario.
        $a = $make(['visit_frequency' => 'diario', 'next_visit_date' => '2026-10-05']);
        $this->assertSame('2026-10-06', app(AgendaService::class)->nextAfter($a));

        // semanal: +7 respetando el weekday configurado.
        $a = $make(['visit_frequency' => 'semanal', 'visit_weekday' => 2, 'next_visit_date' => '2026-10-06']); // martes
        $this->assertSame('2026-10-13', app(AgendaService::class)->nextAfter($a));

        // quincenal: FECHA ANCLA + 15 días (nunca asume 1/15).
        $a = $make(['visit_frequency' => 'quincenal', 'next_visit_date' => '2026-10-07']);
        $this->assertSame('2026-10-22', app(AgendaService::class)->nextAfter($a));

        // mensual: mismo día; clamp a último día válido si el mes no lo contiene.
        $a = $make(['visit_frequency' => 'mensual', 'visit_month_day' => 31, 'next_visit_date' => '2026-01-31']);
        $this->assertSame('2026-02-28', app(AgendaService::class)->nextAfter($a));

        // Ocurrencias: quincenal desde ancla y mensual con clamp dentro del rango.
        $a = $make(['visit_frequency' => 'quincenal', 'next_visit_date' => '2026-10-07']);
        $this->assertSame(['2026-10-07', '2026-10-22'], app(AgendaService::class)->occurrencesFor($a, '2026-10-01', '2026-10-31')->all());
        $a = $make(['visit_frequency' => 'mensual', 'visit_month_day' => 31]);
        $this->assertContains('2026-02-28', app(AgendaService::class)->occurrencesFor($a, '2026-02-01', '2026-02-28')->all());
        // semanal: solo el weekday configurado.
        $a = $make(['visit_frequency' => 'semanal', 'visit_weekday' => 2]);
        $dates = app(AgendaService::class)->occurrencesFor($a, '2026-10-05', '2026-10-11')->all();
        $this->assertSame(['2026-10-06'], $dates);
    }

    public function test_agenda_tabs_hoy_manana_semana_todos(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithScheduledCustomer([
            'visit_frequency' => 'diario',
            'next_visit_date' => CarbonImmutable::today('America/Costa_Rica')->toDateString(),
        ]);

        $agent = $this->actingAs($u)->withSession(['active_company_id' => $c->id]);

        $resp = $agent->get(route('cobros.visits.today'))->assertOk()
            ->assertSee('HOY')->assertSee('MAÑANA')->assertSee('ESTA SEMANA')->assertSee('TODOS');
        $hoy = $resp->viewData('items');
        $this->assertCount(1, $hoy);
        $this->assertSame(today($this->tz($c))->toDateString(), $hoy[0]['date']);
        $this->assertSame($customer->id, $hoy[0]['customer']->id);

        $manana = $this->get(route('cobros.visits.today', ['tab' => 'manana']))->assertOk()->viewData('items');
        $this->assertCount(1, $manana);
        $this->assertSame(today($this->tz($c))->addDay()->toDateString(), $manana[0]['date']);

        $semana = $this->get(route('cobros.visits.today', ['tab' => 'semana']))->assertOk()->viewData('items');
        $todayRef = today($this->tz($c));
        $expectedSemana = (int) $todayRef->copy()->diffInDays($todayRef->copy()->endOfWeek()) + 1;
        $this->assertCount($expectedSemana, $semana); // diario: hoy..domingo
        $this->assertSame($todayRef->copy()->endOfWeek()->toDateString(), end($semana)['date']);

        $todos = $this->get(route('cobros.visits.today', ['tab' => 'todos']))->assertOk()->viewData('items');
        $this->assertCount(31, $todos); // horizonte acotado de 30 días
        $this->assertSame(today($this->tz($c))->addDays(30)->toDateString(), end($todos)['date']);
    }

    public function test_future_promise_appears_on_its_date_without_duplicates(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithScheduledCustomer([]);
        $today = today($this->tz($c))->toDateString();
        $promiseDate = today($this->tz($c))->addDays(3)->toDateString();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), ['scheduled_for' => $today])
            ->assertRedirect();
        $visit = CobrosVisit::query()->where('customer_id', $customer->id)->orderByDesc('id')->firstOrFail();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $visit), ['status' => 'promesa_pago', 'promise_date' => $promiseDate])
            ->assertRedirect();

        $items = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today', ['tab' => 'todos']))->assertOk()->viewData('items');

        $keys = array_map(fn (array $i) => $i['customer']->id.'|'.$i['date'], $items);
        $this->assertSame($keys, array_unique($keys), 'Sin duplicados cliente+fecha');
        $promiseItems = array_values(array_filter($items, fn (array $i) => $i['date'] === $promiseDate && $i['customer']->id === $customer->id));
        $this->assertCount(1, $promiseItems);
        $this->assertSame('promesa', $promiseItems[0]['kind']);
        $this->assertSame(CobrosVisit::STATUS_PROMISE, $promiseItems[0]['visit']->status);
        // El evento promesa NO aparece también en su fecha original de visita.
        $todayItems = array_filter($items, fn (array $i) => $i['date'] === $today && $i['customer']->id === $customer->id && $i['visit'] !== null);
        $this->assertCount(0, $todayItems);
    }

    public function test_reschedule_keeps_frequency_due_date_and_balance_untouched(): void
    {
        [$c, $b, $u, $customer, $assignment] = $this->collectorWithScheduledCustomer([
            'visit_frequency' => 'semanal',
            'visit_weekday' => 2,
            'next_visit_date' => today('America/Costa_Rica')->toDateString(),
        ]);
        $account = AccountReceivable::query()->where('customer_id', $customer->id)->firstOrFail();
        $before = [
            'due_date' => $account->due_date->toDateString(),
            'balance' => (string) $account->balance_due,
            'freq' => $assignment->visit_frequency,
            'next' => $assignment->next_visit_date->toDateString(),
        ];
        $origin = today($this->tz($c))->toDateString();
        $target = today($this->tz($c))->addDay()->toDateString();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), ['scheduled_for' => $origin])->assertRedirect();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.agenda.reschedule', $customer), [
                'origin_date' => $origin, 'scheduled_for' => $target,
            ])->assertRedirect();

        $account->refresh();
        $assignment->refresh();
        $this->assertSame($before['due_date'], $account->due_date->toDateString());
        $this->assertSame($before['balance'], (string) $account->balance_due);
        $this->assertSame($before['freq'], $assignment->visit_frequency);
        $this->assertSame($before['next'], $assignment->next_visit_date->toDateString());
        $this->assertDatabaseCount('accounts_receivable_payments', 0);

        // Historial conservado: origen reprogramada + destino pendiente.
        $originVisit = CobrosVisit::query()->where('customer_id', $customer->id)->whereDate('scheduled_for', $origin)->firstOrFail();
        $this->assertSame(CobrosVisit::STATUS_RESCHEDULED, $originVisit->status);
        $targetVisit = CobrosVisit::query()->where('customer_id', $customer->id)->whereDate('scheduled_for', $target)->where('status', CobrosVisit::STATUS_PENDING)->firstOrFail();
        $this->assertSame($originVisit->id, (int) $targetVisit->rescheduled_from_visit_id);
    }

    public function test_reschedule_is_idempotent_without_duplicating_visits(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithScheduledCustomer([]);
        $origin = today($this->tz($c))->toDateString();
        $target = today($this->tz($c))->addDays(2)->toDateString();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.store', $customer), ['scheduled_for' => $origin])->assertRedirect();

        $payload = ['origin_date' => $origin, 'scheduled_for' => $target];
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.agenda.reschedule', $customer), $payload)->assertRedirect();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.agenda.reschedule', $customer), $payload)->assertRedirect();

        $this->assertSame(2, CobrosVisit::query()->where('customer_id', $customer->id)->count());
    }

    public function test_rescheduled_base_occurrence_is_suppressed_and_series_keeps_its_anchor(): void
    {
        $tz = 'America/Costa_Rica';
        $base = today($tz)->addDays(6)->toDateString();      // 11/10
        [$c, $b, $u, $customer, $assignment] = $this->collectorWithScheduledCustomer([
            'visit_frequency' => 'semanal',
            'visit_weekday' => (int) CarbonImmutable::parse($base)->dayOfWeek,
            'next_visit_date' => $base,
        ]);
        $account = AccountReceivable::query()->where('customer_id', $customer->id)->firstOrFail();
        $dueDate = $account->due_date->toDateString();
        $balance = (string) $account->balance_due;

        $dates = fn () => array_values(array_map(
            fn (array $i) => $i['date'],
            array_filter($this->actingAs($u)->withSession(['active_company_id' => $c->id])
                ->get(route('cobros.visits.today', ['tab' => 'todos']))->assertOk()->viewData('items'),
                fn (array $i) => $i['customer']->id === $customer->id
            )
        ));

        $this->assertContains($base, $dates());

        // Reprogramar SOLO la ocurrencia base → destino (11/10 → 13/10).
        $target = CarbonImmutable::parse($base)->addDays(2)->toDateString();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.agenda.reschedule', $customer), [
                'origin_date' => $base, 'scheduled_for' => $target,
            ])->assertRedirect();

        $after = $dates();
        $this->assertNotContains($base, $after, 'La ocurrencia base reprogramada ya no es operativa');
        $this->assertContains($target, $after, 'La visita destino sí aparece');
        $this->assertContains(CarbonImmutable::parse($base)->addDays(7)->toDateString(), $after, 'Siguiente de la serie: +7');
        $this->assertContains(CarbonImmutable::parse($base)->addDays(14)->toDateString(), $after, 'Serie: +14');
        $this->assertSame($after, $dates(), 'Repetir la consulta no crea duplicados');
        $this->assertSame($after, array_values(array_unique($after)));

        // La recurrencia base NO se desplaza y nada financiero cambia.
        $assignment->refresh();
        $account->refresh();
        $this->assertSame('semanal', $assignment->visit_frequency);
        $this->assertSame($base, $assignment->next_visit_date->toDateString());
        $this->assertSame($dueDate, $account->due_date->toDateString());
        $this->assertSame($balance, (string) $account->balance_due);

        // Historial conservado: origen reprogramada + destino pendiente encadenado.
        $originVisit = CobrosVisit::query()->where('customer_id', $customer->id)->whereDate('scheduled_for', $base)->firstOrFail();
        $this->assertSame(CobrosVisit::STATUS_RESCHEDULED, $originVisit->status);
        $targetVisit = CobrosVisit::query()->where('customer_id', $customer->id)->whereDate('scheduled_for', $target)->where('status', CobrosVisit::STATUS_PENDING)->firstOrFail();
        $this->assertSame($originVisit->id, (int) $targetVisit->rescheduled_from_visit_id);
    }

    public function test_virtual_occurrence_inside_real_reschedule_window_is_suppressed(): void
    {
        $tz = 'America/Costa_Rica';
        $x = today($tz)->toDateString();                 // origen real (05/10)
        $v = today($tz)->addDays(6)->toDateString();     // virtual de la serie (11/10)
        $y = today($tz)->addDays(8)->toDateString();     // destino real (13/10)

        [$c, $b, $u, $customer, $assignment] = $this->collectorWithScheduledCustomer([
            'visit_frequency' => 'semanal',
            'visit_weekday' => (int) CarbonImmutable::parse($v)->dayOfWeek,
            'next_visit_date' => $v,
        ]);
        // Otro cliente con la MISMA recurrencia pero SIN cadena: jamás se suprime.
        $twin = $this->customerWithSchedule($c, $b, $u, [
            'visit_frequency' => 'semanal',
            'visit_weekday' => (int) CarbonImmutable::parse($v)->dayOfWeek,
            'next_visit_date' => $v,
        ]);
        $account = AccountReceivable::query()->where('customer_id', $customer->id)->firstOrFail();
        $dueDate = $account->due_date->toDateString();
        $balance = (string) $account->balance_due;

        $dates = function () use ($u, $c) {
            $items = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
                ->get(route('cobros.visits.today', ['tab' => 'todos']))->assertOk()->viewData('items');
            $out = [];
            foreach ($items as $i) {
                $out[$i['customer']->id][] = $i['date'];
            }

            return $out;
        };

        $this->assertContains($v, $dates()[$customer->id], 'Virtual 11/10 visible antes de la excepción');

        // Cadena real X→Y (05/10 → 13/10): origen materializado y marcado.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.agenda.reschedule', $customer), [
                'origin_date' => $x, 'scheduled_for' => $y,
            ])->assertRedirect();

        $mine = $dates()[$customer->id];
        $this->assertNotContains($v, $mine, 'X <= V < Y: la virtual 11/10 queda suprimida');
        $this->assertContains($y, $mine, 'El destino 13/10 sí aparece');
        $this->assertContains($x, $mine, 'El origen real 05/10 (historial) se conserva visible');
        $this->assertContains(CarbonImmutable::parse($v)->addDays(7)->toDateString(), $mine, 'Serie posterior a Y intacta (+7)');
        $this->assertContains(CarbonImmutable::parse($v)->addDays(14)->toDateString(), $mine, 'Serie posterior a Y intacta (+14)');
        $this->assertSame($mine, $dates()[$customer->id], 'Repetir la consulta no crea duplicados');
        $this->assertSame($mine, array_values(array_unique($mine)));

        // Aislamiento: el otro cliente con la misma serie NO es suprimido.
        $twinDates = $dates()[$twin->id];
        $this->assertContains($v, $twinDates, 'La cadena de OTRO cliente no suprime recurrencias');

        // Recurrencia base y finanzas intactas.
        $assignment->refresh();
        $account->refresh();
        $this->assertSame('semanal', $assignment->visit_frequency);
        $this->assertSame($v, $assignment->next_visit_date->toDateString());
        $this->assertSame($dueDate, $account->due_date->toDateString());
        $this->assertSame($balance, (string) $account->balance_due);

        // Historial sin tocar: origen reprogramada + destino pendiente encadenado.
        $originVisit = CobrosVisit::query()->where('customer_id', $customer->id)->whereDate('scheduled_for', $x)->firstOrFail();
        $this->assertSame(CobrosVisit::STATUS_RESCHEDULED, $originVisit->status);
        $targetVisit = CobrosVisit::query()->where('customer_id', $customer->id)->whereDate('scheduled_for', $y)->where('status', CobrosVisit::STATUS_PENDING)->firstOrFail();
        $this->assertSame($originVisit->id, (int) $targetVisit->rescheduled_from_visit_id);
        $this->assertSame(2, CobrosVisit::query()->where('customer_id', $customer->id)->count());
    }

    public function test_isolation_by_collector_company_and_active_route(): void
    {
        // RouteA licenciada para el cobrador; routeB existe pero NO está disponible para él.
        [$c, $b, $u, $mineRoute] = $this->collectorWithRoute('Ruta A '.uniqid());
        $otherRoute = CobrosRoute::create(['company_id' => $c->id, 'name' => 'Ruta B '.uniqid(), 'active' => true]);
        // Vinculado a la ruta (regla del modelo) pero SIN licencia/contexto para el selector.
        $otherRoute->users()->attach($u->id, ['active' => true]);

        $mine = $this->customerWithSchedule($c, $b, $u, ['cobros_route_id' => $mineRoute->id]);
        $otherRouteCustomer = $this->customerWithSchedule($c, $b, $u, ['cobros_route_id' => $otherRoute->id]);

        // Cliente de OTRO cobrador en otra empresa (nunca debe aparecer).
        [$c2, $b2, $foreignCollector] = $this->collectorWithRoute('Ruta ajena '.uniqid());
        $foreignCustomer = $this->customerWithSchedule($c2, $b2, $foreignCollector, []);

        // Usuario SIN ruta disponible/contexto (fixtures antes de cualquier request:
        // los hooks tenant exigen que la sesión no apunte a otra empresa).
        [$c3, $b3, $noRouteUser] = $this->collectorWithRoute('SoloPerm '.uniqid(), attachRoute: false);
        $own = $this->customerWithSchedule($c3, $b3, $noRouteUser, []);

        // 1) Ruta activa: solo clientes de la ruta activa del cobrador autenticado.
        $resp = $this->actingAs($u)->withSession([
            'active_company_id' => $c->id,
            'active_cobros_route_id' => $mineRoute->id,
        ])->get(route('cobros.visits.today'))->assertOk();
        $names = array_map(fn (array $i) => $i['customer']->name, $resp->viewData('items'));
        $this->assertContains($mine->name, $names);
        $this->assertNotContains($otherRouteCustomer->name, $names, 'Cliente de otra ruta no aparece');

        // 2) Ruta SIN licencia/contexto: el middleware olvida la selección inválida
        //    y la agenda solo muestra la cartera propia (jamás datos ajenos).
        $resp2 = $this->actingAs($noRouteUser)->withSession([
            'active_company_id' => $c3->id,
            'active_cobros_route_id' => $mineRoute->id, // ruta no disponible para este usuario
        ])->get(route('cobros.visits.today'))->assertOk();
        $names2 = array_map(fn (array $i) => $i['customer']->name, $resp2->viewData('items'));
        $this->assertContains($own->name, $names2);
        $this->assertNotContains($foreignCustomer->name, $names2, 'Sin contexto de ruta jamás se filtran datos ajenos');

        // 3) Cliente de OTRO cobrador nunca aparece en la agenda propia.
        $resp3 = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->assertOk();
        $names3 = array_map(fn (array $i) => $i['customer']->name, $resp3->viewData('items'));
        $this->assertNotContains($foreignCustomer->name, $names3);
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: CobrosRoute} */
    private function collectorWithRoute(string $routeName, bool $attachRoute = true): array
    {
        $company = Company::create(['trade_name' => 'AgendaCo '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'G'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => $routeName, 'active' => true]);
        if ($attachRoute) {
            $route->users()->attach($user->id, ['active' => true]);
            app(CollectorLicenseService::class)->renewForRoute(
                $route, $company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), now()->addMonth()
            );
        }

        return [$company->fresh(), $branch, $user, $route];
    }

    /** Cliente asignado con cuenta abierta y configuración de recurrencia. */
    private function customerWithSchedule(Company $c, Branch $b, User $u, array $assignmentOverrides): Customer
    {
        $schedule = array_merge([
            'visit_frequency' => 'diario',
            'next_visit_date' => CarbonImmutable::today('America/Costa_Rica')->toDateString(),
        ], $assignmentOverrides);

        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create(array_merge([
            'company_id' => $c->id,
            'cobros_route_id' => $assignmentOverrides['cobros_route_id'] ?? null,
            'customer_id' => $customer->id,
            'collector_user_id' => $u->id,
            'assigned_by' => $u->id,
            'assigned_at' => now(),
        ], $schedule));

        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        AccountReceivable::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id,
            'sale_id' => $sale->id, 'issued_at' => today()->subMonth(),
            'due_date' => today($c->timezone)->addDays(7),
            'original_amount' => '1000.0000', 'balance_due' => '1000.0000',
            'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        return $customer;
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: Customer, 4: CobrosAssignment} */
    private function collectorWithScheduledCustomer(array $schedule): array
    {
        $company = Company::create(['trade_name' => 'Agenda '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'H'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        $assignment = CobrosAssignment::create(array_merge([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $user->id,
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ], $schedule));

        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        AccountReceivable::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'sale_id' => $sale->id, 'issued_at' => today()->subMonth(),
            'due_date' => today($company->timezone)->addDays(7),
            'original_amount' => '1000.0000', 'balance_due' => '1000.0000',
            'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        return [$company->fresh(), $branch, $user, $customer, $assignment];
    }
}
