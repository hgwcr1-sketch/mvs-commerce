<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Sales\AccountsReceivableService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CobrosOwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'America/Costa_Rica'));
    }

    public function test_owner_access_without_branch_and_empty_portfolio(): void
    {
        [$c, $b, $u] = $this->context();
        $u->branches()->detach();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertOk()
            ->assertSee('Todas las sucursales')->assertSee('No hay cuentas con saldo')
            ->assertDontSee('MVS Commerce')->assertDontSee('Volver a MVS Commerce')
            ->assertDontSee('Registrar pago')->assertDontSee('Abonar')
            ->assertViewHas('branchId', null)
            ->assertViewHas('metrics', ['total_due' => '0.0000', 'total_overdue' => '0.0000', 'collected_today' => '0.0000', 'customers_with_balance' => 0, 'upcoming_count' => 0, 'overdue_count' => 0]);
        $this->get(route('cobros.index'))->assertSee('Consultar cartera');
        $this->post(route('cobros.owner.dashboard'))->assertStatus(405);
    }

    public function test_collector_cannot_see_owner_metrics_or_link(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertForbidden();
        $this->get(route('cobros.index'))->assertOk()->assertDontSee('Consultar cartera')->assertDontSee('Total por cobrar');
    }

    public function test_module_and_base_permission_remain_required(): void
    {
        [$c, $b, $u] = $this->context(['cobros.portal_dueno']);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertForbidden();
        [$other, $branch, $owner] = $this->context();
        $other->modules()->where('module_key', 'cobros')->update(['is_enabled' => false]);
        $this->actingAs($owner)->withSession(['active_company_id' => $other->id])
            ->get(route('cobros.owner.dashboard'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('cobros.owner.dashboard'))->assertRedirect(route('login'));
    }

    public function test_metrics_dates_payments_and_tenant_isolation_are_read_only(): void
    {
        [$c, $b, $u] = $this->context();
        $b2 = Branch::create(['company_id' => $c->id, 'name' => 'Segunda', 'code' => 'S', 'is_active' => true]);
        $first = $this->account($c, $b, $u, '100.1234', -1);
        $partial = $this->account($c, $b, $u, '20.1111', 0, 'partial', $first->customer_id);
        $partial->update(['original_amount' => '30.1111']);
        $next = $this->account($c, $b2, $u, '30.2222', 5);
        $this->account($c, $b2, $u, '40.3333', 6, 'overdue', $next->customer_id);
        $this->account($c, $b, $u, '999.0000', -3, 'paid');
        $this->account($c, $b, $u, '888.0000', -2, 'cancelled');
        $this->account($c, $b, $u, '0.0000', -1);
        $start = CarbonImmutable::today($c->timezone)->setTimezone(config('app.timezone'));
        $this->payment($first, $u, '7.1234', $start);
        $this->payment($next, $u, '2.0001', $start->addDay()->subSecond());
        $this->payment($first, $u, '90', $start->subSecond());
        $this->payment($first, $u, '80', $start->addDay());
        [$other, $foreignBranch, $foreignUser] = $this->context();
        $foreign = $this->account($other, $foreignBranch, $foreignUser, '9000', -1);
        $this->payment($foreign, $foreignUser, '900', $start);
        $foreign->customer->update(['name' => 'CLIENTE EMPRESA AJENA']);
        $before = $this->snapshot();
        $this->mock(AccountsReceivableService::class, function ($mock) {
            $mock->shouldNotReceive('pay');
            $mock->shouldNotReceive('createForSale');
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->actingAs($u)->withSession(['active_company_id' => $c->id, 'active_branch_id' => $b->id])
            ->get(route('cobros.owner.dashboard'))->assertOk()
            ->assertDontSee('CLIENTE EMPRESA AJENA')
            ->assertViewHas('metrics', ['total_due' => '190.7900', 'total_overdue' => '100.1234', 'collected_today' => '9.1235', 'customers_with_balance' => 2, 'upcoming_count' => 2, 'overdue_count' => 1]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q['query']))));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(4, $response->viewData('accounts')->total());
        $this->assertSame($first->id, $response->viewData('accounts')->first()->id);
        $this->assertSame('Vencida', $response->viewData('accounts')->first()->portfolio_status);
        $this->assertSame('Parcial', $response->viewData('accounts')->getCollection()->firstWhere('id', $partial->id)->portfolio_status);
        foreach ($response->viewData('accounts') as $account) {
            $this->assertSame($c->id, $account->company_id);
        }
        $this->get(route('cobros.owner.dashboard', ['branch_id' => $b2->id]))->assertOk()
            ->assertSessionHas('active_branch_id', $b->id)
            ->assertViewHas('metrics', ['total_due' => '70.5555', 'total_overdue' => '0.0000', 'collected_today' => '2.0001', 'customers_with_balance' => 1, 'upcoming_count' => 1, 'overdue_count' => 0])
            ->assertViewHas('accounts', fn ($rows) => $rows->total() === 2 && $rows->every(fn ($a) => $a->branch_id === $b2->id))
            ->assertViewHas('accounts', fn ($rows) => $rows->every(fn ($a) => $a->portfolio_status === 'Pendiente'));
    }

    public function test_foreign_branch_and_unknown_branch_are_rejected(): void
    {
        [$c, $b, $u] = $this->context();
        [$other, $foreignBranch] = $this->context();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard', ['branch_id' => $foreignBranch->id]))->assertNotFound();
        $this->get(route('cobros.owner.dashboard', ['branch_id' => 999999]))->assertNotFound();
        $this->get(route('cobros.owner.dashboard', ['branch_id' => 'invalid']))->assertRedirect()->assertSessionHasErrors('branch_id');
    }

    public function test_pagination_preserves_branch_filter_and_orders_oldest_due_first(): void
    {
        [$c, $b, $u] = $this->context();
        for ($i = 0; $i < 21; $i++) {
            $this->account($c, $b, $u, '0.0001', $i - 10);
        }
        $response = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard', ['branch_id' => $b->id]))->assertOk();
        $rows = $response->viewData('accounts');
        $this->assertSame(21, $rows->total());
        $this->assertCount(20, $rows);
        $this->assertStringContainsString('branch_id='.$b->id, $rows->nextPageUrl());
        $this->assertSame('0.0021', $response->viewData('metrics')['total_due']);
        $this->get($rows->nextPageUrl())->assertOk()->assertViewHas('accounts', fn ($a) => $a->count() === 1);
    }

    public function test_alert_window_respects_company_setting_including_today(): void
    {
        [$c, $b, $u] = $this->context();
        $c->update(['credit_alert_days' => 0]);
        $this->account($c, $b, $u, '1', 0);
        $this->account($c, $b, $u, '2', 1);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertOk()
            ->assertViewHas('metrics', fn ($m) => $m['upcoming_count'] === 1 && $m['overdue_count'] === 0);
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.portal_dueno']): array
    {
        $c = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'credit_alert_days' => 5, 'is_active' => true]);
        $b = Branch::create(['company_id' => $c->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $u = User::factory()->create();
        $role = Role::create(['company_id' => $c->id, 'name' => 'Dueño', 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $u->companies()->attach($c->id, ['role_id' => $role->id]);
        $u->branches()->attach($b->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($c, $admin, ['cobros']);

        return [$c->fresh(), $b, $u];
    }

    private function account(Company $c, Branch $b, User $u, string $balance, int $days, string $status = 'pending', ?int $customerId = null): AccountReceivable
    {
        $customerId ??= Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true])->id;
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customerId, 'sale_number' => 'CXC-'.uniqid(), 'status' => 'completed', 'completed_at' => now()]);

        return AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customerId, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays($days), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => $status, 'currency_code' => 'CRC']);
    }

    private function payment(AccountReceivable $a, User $u, string $amount, $at): void
    {
        $register = CashRegister::create(['company_id' => $a->company_id, 'branch_id' => $a->branch_id, 'code' => uniqid(), 'name' => 'Caja fixture', 'is_active' => true]);
        $session = CashSession::create(['company_id' => $a->company_id, 'branch_id' => $a->branch_id, 'cash_register_id' => $register->id, 'session_number' => uniqid(), 'opened_by' => $u->id, 'status' => 'closed', 'opening_amount' => 0, 'tolerance_snapshot' => 0, 'blind_closing_snapshot' => true, 'opened_at' => now()]);
        $method = PaymentMethod::create(['company_id' => $a->company_id, 'code' => uniqid(), 'name' => 'Transferencia', 'type' => 'bank_transfer', 'is_active' => true, 'affects_cash' => false]);
        AccountReceivablePayment::create(['account_receivable_id' => $a->id, 'company_id' => $a->company_id, 'branch_id' => $a->branch_id, 'customer_id' => $a->customer_id, 'user_id' => $u->id, 'cash_session_id' => $session->id, 'payment_method_id' => $method->id, 'amount' => $amount, 'affects_cash_snapshot' => false, 'cash_effect_amount' => 0, 'paid_at' => $at]);
    }

    private function snapshot(): array
    {
        return [AccountReceivable::orderBy('id')->get()->toArray(), AccountReceivablePayment::orderBy('id')->get()->toArray(), CashSession::orderBy('id')->get()->toArray()];
    }
}
