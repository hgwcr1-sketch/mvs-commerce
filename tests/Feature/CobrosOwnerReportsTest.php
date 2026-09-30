<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
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
 * Fase 4B — panel avanzado del dueño (solo lectura).
 *
 * Reglas certificadas aquí: métricas por empresa/sucursal, ranking de
 * cobradores por período, antigüedad de vencimientos, custodia pendiente,
 * recibos, anulaciones y auditoría; permisos de dueño, company_id y sucursal
 * respetados; sin escrituras financieras y consistencia con el dashboard 1C.
 */
class CobrosOwnerReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_answers_what_each_collector_recovered_this_month(): void
    {
        [$c, $b, $owner] = $this->context();
        [$collectorA, , $accountA] = $this->collectorWithAccount($c, $b, '9000.0000');
        [$collectorB, , $accountB] = $this->collectorWithAccount($c, $b, '5000.0000');
        $this->pay($c, $accountA, $collectorA, '3000.00');
        $this->pay($c, $accountB, $collectorB, '1000.00');
        // Recuperación fuera del período: mes anterior.
        AccountReceivablePayment::query()->where('user_id', $collectorB->id)
            ->update(['paid_at' => now()->subMonth()]);

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.reports'))
            ->assertOk()
            ->assertSee('Resultados del período')
            ->assertSee('Resultados por cobrador')
            ->assertSee($collectorA->name)
            ->assertSee($collectorB->name)
            ->assertSee('3,000.00');

        // Consistencia: total del período = suma de lo recuperado por cobrador.
        $totals = $response->viewData('totals');
        $collectors = $response->viewData('collectors');
        $this->assertSame('3000.0000', $totals['collected']);
        $sum = '0.0000';
        foreach ($collectors as $row) {
            $sum = bcadd($sum, $row['collected'], 4);
        }
        $this->assertSame($totals['collected'], $sum);
        // El ranking queda ordenado por lo cobrado.
        $this->assertSame($collectorA->name, $collectors[0]['name']);
        $this->assertSame('0.0000', $collectors[1]['collected']);
    }

    public function test_period_and_branch_filters_are_validated_and_scoped(): void
    {
        [$c, $b, $owner] = $this->context();
        $other = Branch::create(['company_id' => $c->id, 'name' => 'Sucursal Este '.uniqid(), 'code' => 'E'.random_int(1, 99), 'is_active' => true]);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '4000.0000');
        $this->pay($c, $account, $collector, '2000.00');
        $sessions = ['active_company_id' => $c->id];

        // Sucursal del cobro: sí contabiliza.
        $this->actingAs($owner)->withSession($sessions)
            ->get(route('cobros.owner.reports', ['branch_id' => $b->id]))
            ->assertOk()->assertSee('2,000.00');

        // Otra sucursal de la empresa: 0 en el período.
        $this->actingAs($owner)->withSession($sessions)
            ->get(route('cobros.owner.reports', ['branch_id' => $other->id]))
            ->assertOk()->assertSee('0.00')->assertDontSee('2,000.00');

        // Sucursal desconocida o de otra empresa: 404 seguro.
        $this->actingAs($owner)->withSession($sessions)
            ->get(route('cobros.owner.reports', ['branch_id' => 999999]))->assertNotFound();
        [$fc] = $this->context();
        $foreignBranch = Branch::where('company_id', $fc->id)->firstOrFail();
        $this->actingAs($owner)->withSession($sessions)
            ->get(route('cobros.owner.reports', ['branch_id' => $foreignBranch->id]))->assertNotFound();

        // Período inválido: desde > hasta o formato incorrecto.
        $this->actingAs($owner)->withSession($sessions)
            ->get(route('cobros.owner.reports', ['from' => '2026-09-10', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');
        $this->actingAs($owner)->withSession($sessions)
            ->get(route('cobros.owner.reports', ['from' => '10/09/2026']))
            ->assertSessionHasErrors('from');

        // Período sin cobros: cifras en cero.
        $this->actingAs($owner)->withSession($sessions)
            ->get(route('cobros.owner.reports', ['from' => '2000-01-01', 'to' => '2000-01-31']))
            ->assertOk()->assertSee('0.00');
    }

    public function test_aging_buckets_split_the_open_portfolio(): void
    {
        [$c, $b, $owner] = $this->context();
        $dueDates = [
            'vigente' => today($c->timezone)->addDays(10)->toDateString(),
            'd30' => today($c->timezone)->subDays(10)->toDateString(),
            'd60' => today($c->timezone)->subDays(45)->toDateString(),
            'd90' => today($c->timezone)->subDays(75)->toDateString(),
            'd90plus' => today($c->timezone)->subDays(120)->toDateString(),
        ];
        foreach ($dueDates as $key => $due) {
            $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.$key.uniqid(), 'is_active' => true]);
            $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
            AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today($c->timezone)->subMonth(), 'due_date' => $due, 'original_amount' => '1000.0000', 'balance_due' => '1000.0000', 'status' => 'pending', 'currency_code' => 'CRC']);
        }

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.reports'))
            ->assertOk()
            ->assertSee('Antigüedad de vencimientos')
            ->assertSee('Más de 90 días');

        $aging = $response->viewData('aging');
        $this->assertSame(1, $aging['Vigente']['count']);
        $this->assertSame(1, $aging['De 1 a 30 días']['count']);
        $this->assertSame(1, $aging['De 31 a 60 días']['count']);
        $this->assertSame(1, $aging['De 61 a 90 días']['count']);
        $this->assertSame(1, $aging['Más de 90 días']['count']);
        foreach ($aging as $bucket) {
            $this->assertSame('1000.0000', $bucket['amount']);
        }
    }

    public function test_custody_pending_is_net_of_annulments(): void
    {
        [$c, $b, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.anular']);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '3000.0000');
        $this->pay($c, $account, $collector, '1000.00');
        $this->pay($c, $account, $collector, '500.00');

        // Se anula el segundo cobro: su reverso descuenta la custodia.
        $receipt = CobrosReceipt::query()->orderByDesc('id')->firstOrFail();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', $receipt->receipt_number),
            ['reason' => 'Cobro duplicado en custodia', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.reports'))
            ->assertOk()
            ->assertSee('Custodia pendiente');

        $custody = $response->viewData('custody');
        $this->assertSame(2, $custody['payments_count']);
        $this->assertSame('1500.0000', $custody['gross']);
        $this->assertSame('500.0000', $custody['reversed']);
        $this->assertSame('1000.0000', $custody['net']);
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_report_counts_receipts_annulments_and_audit_events_of_the_period(): void
    {
        [$c, $b, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.anular']);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '2000.0000');
        $this->pay($c, $account, $collector, '800.00');
        $receipt = CobrosReceipt::query()->orderByDesc('id')->firstOrFail();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', $receipt->receipt_number),
            ['reason' => 'Anulación contabilizada en reporte', 'client_token' => (string) Str::uuid()]
        )->assertRedirect();

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.reports'))
            ->assertOk()
            ->assertSee('Recibos emitidos')
            ->assertSee('Cobros anulados')
            ->assertSee('Eventos de auditoría');

        $totals = $response->viewData('totals');
        $this->assertSame(1, $totals['receipts_count']);
        $this->assertSame('800.0000', $totals['receipts_total']);
        $this->assertSame(1, $totals['reversals_count']);
        $this->assertSame('800.0000', $totals['reversals_total']);
        // Un cobro registrado + una anulación.
        $this->assertSame(2, $totals['audit_events']);
    }

    public function test_report_requires_the_owner_permission(): void
    {
        [$c, $b, $supervisor] = $this->context(['cobros.acceder', 'cobros.supervisar']);
        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.reports'))->assertForbidden();

        [$c2, $b2, $collector] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($collector)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.owner.reports'))->assertForbidden();
        unset($b, $b2);
    }

    public function test_report_is_read_only_and_tenant_isolated(): void
    {
        [$c, $b, $owner] = $this->context();
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '6000.0000');
        $this->pay($c, $account, $collector, '1500.00');

        [$fc, $fb] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        [$foreignCollector, , $foreignAccount] = $this->collectorWithAccount($fc, $fb, '700.0000');
        $this->pay($fc, $foreignAccount, $foreignCollector, '700.00');

        $before = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.reports'))->assertOk();

        $writes = array_values(array_filter(
            DB::getQueryLog(),
            fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q['query'])
        ));
        DB::disableQueryLog();

        $this->assertSame([], $writes, 'El panel del dueño no ejecuta escrituras.');
        $this->assertSame($before, $this->snapshot());

        $content = $response->getContent();
        $response->assertSee($collector->name)->assertDontSee($foreignCollector->name);
        $this->assertStringNotContainsString($fc->trade_name, $content);
        $this->assertStringNotContainsString($fb->name, $content);
        // Enlaces siempre dentro de CobrOS.
        $dom = new \DOMDocument;
        @$dom->loadHTML($content);
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $this->assertTrue(str_starts_with($href, '#') || str_starts_with($href, url('/cobros')), $href);
        }
    }

    public function test_report_is_consistent_with_the_owner_dashboard(): void
    {
        [$c, $b, $owner] = $this->context();
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '7000.0000');
        $this->pay($c, $account, $collector, '2500.00');

        $dashboard = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard', ['branch_id' => $b->id]))->assertOk();
        $report = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.reports', ['branch_id' => $b->id]))->assertOk();

        $dashboardMetrics = $dashboard->viewData('metrics');
        $reportSummary = $report->viewData('summary');
        $this->assertSame($dashboardMetrics['total_due'], $reportSummary['metrics']['total_due']);
        $this->assertSame($dashboardMetrics['total_overdue'], $reportSummary['metrics']['total_overdue']);
        $this->assertSame('4500.0000', $reportSummary['metrics']['total_due']);
    }

    private function snapshot(): array
    {
        return [
            'accounts' => AccountReceivable::query()->orderBy('id')->get(['id', 'balance_due', 'status'])->toArray(),
            'payments' => AccountReceivablePayment::query()->orderBy('id')->get(['id', 'amount', 'cash_session_id', 'paid_at'])->toArray(),
            'receipts' => CobrosReceipt::query()->orderBy('id')->get(['id', 'receipt_number', 'annulled_at'])->toArray(),
            'assignments' => CobrosAssignment::query()->orderBy('id')->get(['id', 'collector_user_id'])->toArray(),
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
    private function context(array $permissions = ['cobros.acceder', 'cobros.portal_dueno'], ?User $user = null): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'D'.random_int(1, 99), 'is_active' => true]);
        $user ??= User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño '.uniqid(), 'is_active' => true]);
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
