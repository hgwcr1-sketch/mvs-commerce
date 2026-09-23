<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Cobros\CollectorManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosCollectorPortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_sees_only_own_portfolio_customers(): void
    {
        [$c, $b, $mario] = $this->context();
        [$c2, $b2, $juan] = $this->context();
        [$marioCustomer] = $this->customerWithAccount($c, $b, $mario, '15000.5000');
        [$juanCustomer] = $this->customerWithAccount($c2, $b2, $juan, '9000.0000');

        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertSee($marioCustomer->name)
            ->assertDontSee($juanCustomer->name)
            ->assertViewHas('customers', fn ($rows) => $rows->getCollection()->modelKeys() === [$marioCustomer->id]);

        $this->actingAs($juan)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertSee($juanCustomer->name)
            ->assertDontSee($marioCustomer->name);
    }

    public function test_unassigned_customer_is_hidden(): void
    {
        [$c, $b, $u] = $this->context();
        $loose = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Sin asignar '.uniqid(), 'is_active' => true]);
        $this->account($c, $b, $u, '5000.0000', 10, 'pending', $loose->id);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertDontSee($loose->name)
            ->assertViewHas('customers', fn ($rows) => $rows->getCollection()->isEmpty());
    }

    public function test_cross_company_customer_is_hidden(): void
    {
        [$c, $b, $u] = $this->context();
        [$foreign, $foreignBranch] = $this->context();
        $foreignCustomer = Customer::create(['company_id' => $foreign->id, 'customer_type' => 'individual', 'name' => 'Ajenо '.uniqid(), 'is_active' => true]);
        $sale = Sale::create(['company_id' => $foreign->id, 'branch_id' => $foreignBranch->id, 'user_id' => $u->id, 'customer_id' => $foreignCustomer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        AccountReceivable::create(['company_id' => $foreign->id, 'branch_id' => $foreignBranch->id, 'customer_id' => $foreignCustomer->id, 'sale_id' => $sale->id, 'issued_at' => today(), 'due_date' => today($foreign->timezone)->addDays(7), 'original_amount' => '7000.0000', 'balance_due' => '7000.0000', 'status' => 'pending', 'currency_code' => 'CRC']);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertDontSee($foreignCustomer->name)
            ->assertViewHas('customers', fn ($rows) => $rows->getCollection()->isEmpty());
    }

    public function test_detail_idor_returns_404_for_foreign_unassigned_or_cross_company(): void
    {
        [$c, $b, $mario] = $this->context();
        [$c2, $b2, $juan] = $this->context();
        [$marioCustomer] = $this->customerWithAccount($c, $b, $mario, '1000.0000');
        [$juanCustomer] = $this->customerWithAccount($c2, $b2, $juan, '1000.0000');
        $unassigned = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Otro '.uniqid(), 'is_active' => true]);

        // Cliente de Juan visto por Mario (cross-company): 404.
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $juanCustomer))->assertNotFound();

        // Cliente de la empresa pero sin asignar a Mario: 404.
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $unassigned))->assertNotFound();

        // Cliente propio: 200.
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $marioCustomer))->assertOk();

        // collector_id del request jamás amplía autoridad.
        $this->actingAs($mario)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', ['customer' => $juanCustomer->id, 'collector_id' => $juan->id]))
            ->assertNotFound();
    }

    public function test_search_does_not_expand_portfolio_and_matches_real_fields(): void
    {
        [$c, $b, $u] = $this->context();
        [$byName] = $this->customerWithAccount($c, $b, $u, '100.0000', ['name' => 'Mario Solano '.uniqid()]);
        [$byId] = $this->customerWithAccount($c, $b, $u, '200.0000', ['identification' => '1'.uniqid()]);
        [$byPhone] = $this->customerWithAccount($c, $b, $u, '300.0000', ['mobile' => '+506 8888 '.random_int(1000, 9999)]);
        $outsider = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Fuera '.uniqid(), 'is_active' => true]);
        $this->account($c, $b, $u, '400.0000', 5, 'pending', $outsider->id);

        $nameTerm = substr($byName->name, 0, 8);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio', ['q' => $nameTerm]))
            ->assertOk()
            ->assertSee($byName->name)
            ->assertDontSee($byId->name)
            ->assertDontSee($outsider->name);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio', ['q' => $byId->identification]))
            ->assertOk()->assertViewHas('customers', fn ($rows) => $rows->getCollection()->modelKeys() === [$byId->id]);

        $digits = preg_replace('/\D/', '', $byPhone->mobile);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio', ['q' => substr($digits, -4)]))
            ->assertOk()->assertViewHas('customers', fn ($rows) => $rows->getCollection()->modelKeys() === [$byPhone->id]);
    }

    public function test_totals_counts_branches_and_due_dates_are_correct(): void
    {
        [$c, $b, $u] = $this->context();
        $b2 = Branch::create(['company_id' => $c->id, 'name' => 'Segunda', 'code' => 'S', 'is_active' => true]);
        [$customer] = $this->customerWithAccount($c, $b, $u, '1000.1000', ['due_in_days' => -2]);
        $this->account($c, $b2, $u, '250.2500', 9, 'partial', $customer->id);
        $customer->refresh();

        $view = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertViewHas('totalBalance', '1250.3500')
            ->assertViewHas('customers', fn ($rows) => $rows->getCollection()->first()->portfolio_open_count === 2);

        $first = $view->viewData('customers')->getCollection()->first();
        $this->assertSame('1250.3500', (string) $first->portfolio_balance);
        $this->assertTrue(bccomp((string) $first->portfolio_balance, '1250.3500', 4) === 0);
        $this->assertContains($b->name, $first->portfolio_branches->all());
        $this->assertContains($b2->name, $first->portfolio_branches->all());
    }

    public function test_paid_cancelled_and_zero_balance_are_excluded(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer] = $this->customerWithAccount($c, $b, $u, '500.0000');
        $this->account($c, $b, $u, '999.0000', 5, 'paid', $customer->id);
        $this->account($c, $b, $u, '888.0000', 5, 'cancelled', $customer->id);
        $this->account($c, $b, $u, '0.0000', 5, 'pending', $customer->id);
        $customer->refresh();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertViewHas('totalBalance', '500.0000')
            ->assertViewHas('customers', fn ($rows) => $rows->getCollection()->first()->portfolio_open_count === 1);
    }

    public function test_detail_shows_accounts_and_reliable_payments_read_only(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        $register = \App\Models\CashRegister::create(['company_id' => $c->id, 'branch_id' => $b->id, 'code' => uniqid(), 'name' => 'Caja', 'is_active' => true]);
        $session = \App\Models\CashSession::create(['company_id' => $c->id, 'branch_id' => $b->id, 'cash_register_id' => $register->id, 'session_number' => uniqid(), 'opened_by' => $u->id, 'status' => 'closed', 'opening_amount' => 0, 'tolerance_snapshot' => 0, 'blind_closing_snapshot' => true, 'opened_at' => now()]);
        $method = PaymentMethod::create(['company_id' => $c->id, 'code' => uniqid(), 'name' => 'Efectivo cobros', 'type' => 'cash', 'is_active' => true, 'affects_cash' => true]);
        AccountReceivablePayment::create(['account_receivable_id' => $account->id, 'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'user_id' => $u->id, 'cash_session_id' => $session->id, 'payment_method_id' => $method->id, 'amount' => '150.0000', 'affects_cash_snapshot' => true, 'cash_effect_amount' => '150.0000', 'paid_at' => now()->subDay()]);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()
            ->assertSee($account->sale->sale_number)
            ->assertSee('150.00');
    }

    public function test_data_snapshots_remain_intact_after_browsing(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '777.7777');
        $assignment = CobrosAssignment::query()->forCompany($c->id)->where('customer_id', $customer->id)->first();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))->assertOk();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))->assertOk();

        $this->assertDatabaseHas('accounts_receivable', ['id' => $account->id, 'balance_due' => '777.7777', 'status' => 'pending']);
        $this->assertDatabaseHas('cobros_assignments', ['id' => $assignment->id, 'customer_id' => $customer->id, 'collector_user_id' => $u->id]);
        $this->assertDatabaseCount("accounts_receivable_payments", 0);
        $this->assertDatabaseCount('cobros_assignment_logs', 1); // solo la asignación original
    }

    public function test_navigation_and_permissions_are_enforced(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer] = $this->customerWithAccount($c, $b, $u, '10.0000');

        // Sin permiso cobros.cobrar: prohibido.
        [$c3, $b3, $noPerm] = $this->context(['cobros.acceder']);
        $this->actingAs($noPerm)->withSession(['active_company_id' => $c3->id])
            ->get(route('cobros.collector.portfolio'))->assertForbidden();
        $this->actingAs($noPerm)->withSession(['active_company_id' => $c3->id])
            ->get(route('cobros.collector.portfolio.show', $customer))->assertForbidden();

        // Invitado: el middleware de CobrOS responde 403 (contrato actual del producto).
        $this->get(route('cobros.collector.portfolio'))->assertForbidden();

        // Cobrador autenticado ve enlace Mi cartera y la ruta del dashboard redirige al listado real.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))->assertOk()->assertSee('Mi cartera');
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'C'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        return [$company->fresh(), $branch, $user];
    }

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $c, Branch $b, User $u, string $balance, array $customerData = []): array
    {
        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual',
            'name' => $customerData['name'] ?? 'Cliente '.uniqid(),
            'identification' => $customerData['identification'] ?? null,
            'mobile' => $customerData['mobile'] ?? null,
            'is_active' => true]);
        app(\App\Services\Cobros\PortfolioAssignmentService::class)->assign($c, $customer, $u, $admin = User::factory()->create(["is_platform_admin" => true, "is_active" => true]));

        $account = $this->account($c, $b, $u, $balance, $customerData['due_in_days'] ?? 7, 'pending', $customer->id);

        return [$customer, $account];
    }

    private function account(Company $c, Branch $b, User $u, string $balance, int $days, string $status = 'pending', ?int $customerId = null): AccountReceivable
    {
        $customerId ??= Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true])->id;
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customerId, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);

        return AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customerId, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays($days), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => $status, 'currency_code' => 'CRC']);
    }
}
