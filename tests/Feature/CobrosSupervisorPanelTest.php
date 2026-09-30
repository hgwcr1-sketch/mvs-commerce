<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 4A — panel operativo del supervisor CobrOS (solo lectura).
 *
 * Reglas certificadas aquí: cartera por cobrador, agenda del día, cobrado,
 * recibos, anulaciones, auditoría y filtro por sucursal; permisos y tenant
 * respetados; sin acciones financieras ni escrituras nuevas.
 */
class CobrosSupervisorPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_shows_collectors_agenda_receipts_annulments_and_audit(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar', 'cobros.anular']);
        [$collector, $customer, $account] = $this->collectorWithAccount($c, $b, '12000.0000');
        $this->pay($c, $account, $collector, '4000.00');
        $this->visit($c, $b, $customer, $collector, CobrosVisit::STATUS_NOT_FOUND, today($c->timezone)->toDateString());
        $this->visit($c, $b, $customer, $collector, CobrosVisit::STATUS_PROMISE, today($c->timezone)->subDay()->toDateString());

        // Anulación real para tener reverso y auditoría de anulación.
        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            ['reason' => 'Cobro equivocado para el panel', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index'))
            ->assertOk()
            ->assertSee('Panel del supervisor')
            ->assertSee($collector['name'])
            ->assertSee('Cartera por cobrador')
            ->assertSee('Agenda de hoy')
            ->assertSee('Recibos y anulaciones de hoy')
            ->assertSee('Cobro registrado')
            ->assertSee('Cobro anulado')
            ->assertSee('No localizados hoy')
            ->assertSee('Promesas vencidas')
            // Cartera del cobrador: saldo devuelto tras el reverso y cobro del día contabilizado.
            ->assertSee('Clientes asignados')
            ->assertSee('12,000.00')    // saldo restaurado por la anulación
            ->assertSee('4,000.00')     // cobrado hoy (el pago original sigue registrado)
            ->assertSee('Recibos emitidos (1)')
            ->assertSee('Cobros anulados (1)')
            ->assertSee('Ver auditoría completa');
    }

    public function test_branch_filter_scopes_metrics_and_unknown_branch_is_404(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar']);
        $other = Branch::create(['company_id' => $c->id, 'name' => 'Sucursal Norte '.uniqid(), 'code' => 'N'.random_int(1, 99), 'is_active' => true]);
        // El supervisor conoce ambas sucursales: así se prueba el alcance de las cifras.
        $supervisor->branches()->attach($other->id);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '9000.0000');
        $this->pay($c, $account, $collector, '3000.00');

        $sessions = ['active_company_id' => $c->id];

        // Sucursal del cobro: sí contabiliza.
        $this->actingAs($supervisor)->withSession($sessions)
            ->get(route('cobros.supervisor.index', ['branch_id' => $b->id]))
            ->assertOk()->assertSee('3,000.00');

        // Otra sucursal de la misma empresa: cobro no atribuible.
        $this->actingAs($supervisor)->withSession($sessions)
            ->get(route('cobros.supervisor.index', ['branch_id' => $other->id]))
            ->assertOk()->assertSee('0.00')->assertDontSee('3,000.00');

        // Sucursal inexistente o ajena a la empresa: 404 seguro.
        $this->actingAs($supervisor)->withSession($sessions)
            ->get(route('cobros.supervisor.index', ['branch_id' => 999999]))->assertNotFound();
        [$foreign] = $this->context(['cobros.acceder', 'cobros.supervisar']);
        $foreignBranch = Branch::where('company_id', $foreign->id)->firstOrFail();
        $this->actingAs($supervisor)->withSession($sessions)
            ->get(route('cobros.supervisor.index', ['branch_id' => $foreignBranch->id]))->assertNotFound();
    }

    public function test_branch_filter_respects_the_supervisor_scope(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar']);
        $other = Branch::create(['company_id' => $c->id, 'name' => 'Sucursal Sur '.uniqid(), 'code' => 'S'.random_int(1, 99), 'is_active' => true]);
        // El supervisor solo está vinculado a la sucursal $b.
        $supervisor->branches()->detach();
        $supervisor->branches()->attach($b->id);

        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index', ['branch_id' => $b->id]))->assertOk();
        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index', ['branch_id' => $other->id]))->assertNotFound();
    }

    public function test_panel_requires_the_supervisor_permission(): void
    {
        [$c, $b, $collector] = $this->context(['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index'))->assertForbidden();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.audit'))->assertForbidden();
        unset($b);
    }

    public function test_supervisor_has_no_owner_functions(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar']);

        foreach (['owner.dashboard', 'owner.collectors', 'owner.assignments', 'owner.audit'] as $route) {
            $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
                ->get(route('cobros.'.$route))->assertForbidden();
        }
        unset($b);
    }

    public function test_panel_is_read_only_and_tenant_isolated(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar', 'cobros.anular']);
        [$collector, $customer, $account] = $this->collectorWithAccount($c, $b, '5000.0000');
        $this->pay($c, $account, $collector, '1000.00');

        // Otra empresa con datos que jamás deben aparecer.
        [$fc, $fb] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        [$foreignCollector, , $foreignAccount] = $this->collectorWithAccount($fc, $fb, '700.0000');
        $this->pay($fc, $foreignAccount, $foreignCollector, '700.00');

        $before = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index'))->assertOk();

        $writes = array_values(array_filter(
            DB::getQueryLog(),
            fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q['query'])
        ));
        DB::disableQueryLog();

        $this->assertSame([], $writes, 'El panel del supervisor no ejecuta escrituras.');
        $this->assertSame($before, $this->snapshot());

        // Aislamiento: nada de la otra empresa en pantalla.
        $response->assertSee($collector['name'])
            ->assertDontSee($foreignCollector['name']);
        $content = $response->getContent();
        $this->assertStringNotContainsString($fc->trade_name, $content);
        $this->assertStringNotContainsString($fb->name, $content);
    }

    public function test_panel_shows_empty_states_without_data(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar']);

        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index'))
            ->assertOk()
            ->assertSee('No hay cobradores registrados')
            ->assertSee('Sin incidencias de este tipo hoy')
            ->assertSee('Sin promesas vencidas')
            ->assertSee('Sin eventos de cobro registrados')
            ->assertSee('Recibos emitidos (0)')
            ->assertSee('Cobros anulados (0)');
        unset($b);
    }

    public function test_panel_links_stay_inside_cobros(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar']);

        $content = $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML($content);
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $this->assertTrue(str_starts_with($href, '#') || str_starts_with($href, url('/cobros')), $href);
        }
        $this->assertStringNotContainsString('action="'.url('/logout').'"', $content);
        unset($b);
    }

    /** Snapshot de tablas financieras y operativas que el panel no debe tocar. */
    private function snapshot(): array
    {
        return [
            'accounts' => AccountReceivable::query()->orderBy('id')->get(['id', 'balance_due', 'status'])->toArray(),
            'payments' => AccountReceivablePayment::query()->orderBy('id')->get(['id', 'amount', 'cash_session_id'])->toArray(),
            'receipts' => CobrosReceipt::query()->orderBy('id')->get(['id', 'receipt_number', 'annulled_at'])->toArray(),
            'assignments' => CobrosAssignment::query()->orderBy('id')->get(['id', 'collector_user_id'])->toArray(),
            'visits' => CobrosVisit::query()->orderBy('id')->get(['id', 'status'])->toArray(),
            'cash' => DB::table('cash_sessions')->count() + DB::table('cash_movements')->count(),
        ];
    }

    private function pay(Company $c, AccountReceivable $account, User $collector, string $amount): void
    {
        $method = PaymentMethod::query()->where('company_id', $c->id)->where('code', 'cash')->firstOrFail();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            [
                'amount' => $amount, 'payment_method_id' => $method->id, 'reference' => null,
                'notes' => null, 'client_token' => (string) Str::uuid(), 'confirm' => '1',
            ]
        )->assertRedirect();
    }

    private function visit(Company $c, Branch $b, Customer $customer, User $collector, string $status, string $date): void
    {
        CobrosVisit::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id,
            'collector_user_id' => $collector->id, 'scheduled_for' => $date, 'status' => $status,
            'promise_date' => $status === CobrosVisit::STATUS_PROMISE ? $date : null,
        ]);
    }

    /** @return array{0: User, 1: Customer, 2: AccountReceivable} */
    private function collectorWithAccount(Company $c, Branch $b, string $balance): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($c->id, ['role_id' => $role->id]);
        $user->branches()->attach($b->id);

        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create([
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $user->id, 'assigned_by' => $user->id, 'assigned_at' => now(),
        ]);
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC']);

        return [$user, $customer, $account];
    }

    /**
     * @return array{0: Company, 1: Branch, 2: User}
     */
    private function context(array $permissions = ['cobros.acceder', 'cobros.supervisar'], ?User $user = null): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'S'.random_int(1, 99), 'is_active' => true]);
        $user ??= User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Supervisor '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company->fresh(), $branch, $user];
    }
}
