<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cobros\CobrosPaymentGate;
use App\Services\Cobros\PortfolioAssignmentService;
use App\Services\CompanyLicenseService;
use App\Support\Cobros\PaymentGateDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 3A — Gate de cobro: autorización centralizada para INICIAR un cobro.
 * Esta fase NO registra pagos, NO cambia saldos, NO genera recibos y NO toca caja.
 */
class CobrosPaymentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_collector_with_assigned_customer_and_balance_may_start(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '25000.5000');

        $this->assertTrue(app(CobrosPaymentGate::class)->canStart($u, $c, $account));

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('Saldo pendiente')
            ->assertSee('25,000.50')
            ->assertSee('REVISAR COBRO')
            ->assertSee('VOLVER')
            // Lenguaje cotidiano, sin jerga contable ni otros módulos.
            ->assertDontSee('balance_due')
            ->assertDontSee('AccountReceivable')
            ->assertDontSee('MVS Commerce')
            ->assertDontSee('Punto de venta')
            ->assertDontSee('Inventario')
            ->assertDontSee('Sesión de caja');
    }

    public function test_without_cobrar_permission_is_forbidden(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        $u->companies()->updateExistingPivot($c->id, ['role_id' => $this->roleWithout($c, 'cobros.cobrar')->id]);

        $this->assertFalse(app(CobrosPaymentGate::class)->canStart($u, $c->fresh(), $account));

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertForbidden();
    }

    public function test_inactive_user_is_blocked_on_gate_and_route(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        $u->update(['is_active' => false]);

        $decision = app(CobrosPaymentGate::class)->evaluate($u->fresh(), $c, $account);
        $this->assertTrue($decision->isDeniedBecause(PaymentGateDecision::PERMISSION_DENIED));

        $this->actingAs($u->fresh())->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertForbidden();
    }

    public function test_disabled_cobros_module_blocks_the_gate(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000');
        $c->modules()->where('module_key', 'cobros')->update(['is_enabled' => false]);

        $this->assertFalse(app(CobrosPaymentGate::class)->canStart($u, $c->fresh(), $account));
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertForbidden();
    }

    public function test_customer_not_assigned_to_the_collector_is_blocked(): void
    {
        [$c, $b, $u] = $this->context();
        $other = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Sin asignar '.uniqid(), 'is_active' => true]);
        $account = $this->account($c, $b, $u, '5000.0000', $other->id);

        $decision = app(CobrosPaymentGate::class)->evaluate($u, $c, $account);
        $this->assertTrue($decision->isDeniedBecause(PaymentGateDecision::CUSTOMER_NOT_ASSIGNED));
        $this->assertSame(404, $decision->httpStatus());

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertNotFound();
    }

    public function test_account_from_another_company_returns_safe_404(): void
    {
        [$c, $b, $u] = $this->context();
        [$foreign, $foreignBranch, $foreignUser] = $this->context();
        [, $foreignAccount] = $this->customerWithAccount($foreign, $foreignBranch, $foreignUser, '7000.0000');

        $decision = app(CobrosPaymentGate::class)->evaluate($u, $c, $foreignAccount);
        $this->assertTrue($decision->isDeniedBecause(PaymentGateDecision::OTHER_COMPANY));
        $this->assertSame(404, $decision->httpStatus());

        // Misma respuesta segura exista o no el recurso: nunca se revela existencia.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $foreignAccount))->assertNotFound();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', 999999))->assertNotFound();
    }

    public function test_branch_outside_collector_scope_is_blocked(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '3000.0000');
        $u->branches()->detach($b);

        $decision = app(CobrosPaymentGate::class)->evaluate($u, $c, $account);
        $this->assertTrue($decision->isDeniedBecause(PaymentGateDecision::BRANCH_NOT_AUTHORIZED));

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertForbidden();
    }

    public function test_paid_cancelled_and_zero_balance_accounts_are_blocked(): void
    {
        [$c, $b, $u] = $this->context();
        [, $paid] = $this->customerWithAccount($c, $b, $u, '100.0000', 'paid');
        [, $cancelled] = $this->customerWithAccount($c, $b, $u, '100.0000', 'cancelled');
        [, $zero] = $this->customerWithAccount($c, $b, $u, '0.0000');

        $gate = app(CobrosPaymentGate::class);
        foreach ([$paid, $cancelled] as $account) {
            $this->assertTrue($gate->evaluate($u, $c, $account)->isDeniedBecause(PaymentGateDecision::ACCOUNT_CLOSED));
        }
        $this->assertTrue($gate->evaluate($u, $c, $zero)->isDeniedBecause(PaymentGateDecision::NO_BALANCE));

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $paid))->assertForbidden();
    }

    public function test_owner_or_supervisor_without_cobrar_cannot_collect(): void
    {
        foreach (['cobros.portal_dueno', 'cobros.supervisar'] as $superPermission) {
            [$c, $b, $u] = $this->context(['cobros.acceder', $superPermission]);
            [, $account] = $this->customerWithAccount($c, $b, $u, '4000.0000');

            $this->assertTrue($u->hasPermission($superPermission, $c));
            $this->assertFalse($u->hasPermission('cobros.cobrar', $c));
            $this->assertFalse(app(CobrosPaymentGate::class)->canStart($u, $c, $account));

            $this->actingAs($u)->withSession(['active_company_id' => $c->id])
                ->get(route('cobros.collector.payments.create', $account))->assertForbidden();
        }
    }

    public function test_owner_or_supervisor_with_cobrar_keeps_operational_rules(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.cobrar']);
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '4000.0000');

        // Con permiso + cartera + sucursal, el dueño sí llega a la pantalla previa.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertOk()->assertSee($customer->name);

        // Sin cartera asignada, el permiso NO amplía autoridad.
        app(PortfolioAssignmentService::class)->unassign($c, $customer, $u, 'Prueba 3A');
        $this->assertTrue(app(CobrosPaymentGate::class)
            ->evaluate($u, $c, $account)
            ->isDeniedBecause(PaymentGateDecision::CUSTOMER_NOT_ASSIGNED));
    }

    public function test_direct_url_manipulation_never_grants_authority(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        $this->actingAs($u)->withSession(['active_company_id' => $c->id]);

        $this->get(route('cobros.collector.payments.create', [
            'account' => $account->id, 'collector_id' => $u->id, 'company_id' => $c->id,
        ]))->assertOk();

        $loose = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Ajeno '.uniqid(), 'is_active' => true]);
        $foreignAccount = $this->account($c, $b, $u, '100.0000', $loose->id);
        $this->get(route('cobros.collector.payments.create', [
            'account' => $foreignAccount->id, 'collector_id' => $u->id, 'customer_id' => $customer->id,
        ]))->assertNotFound();

        $this->get(route('cobros.collector.payments.create', ['account' => $account, 'collector_id' => 999999]))->assertOk();
    }

    public function test_visit_of_another_collector_does_not_unlock_the_gate(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '5000.0000');
        [, , $other] = $this->extraCollector($c);
        CobrosVisit::create([
            'company_id' => $c->id, 'customer_id' => $customer->id, 'collector_user_id' => $other->id,
            'scheduled_for' => today($c->timezone)->toDateString(), 'status' => 'pendiente',
        ]);

        // La agenda del otro cobrador no ofrece COBRAR (cliente fuera de su cartera).
        $this->actingAs($other)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->assertOk()->assertDontSee('COBRAR');
        $this->assertFalse(app(CobrosPaymentGate::class)->canStart($other, $c, $account));

        // El cobrador asignado sí ve el botón en su propia visita autorizada.
        CobrosVisit::create([
            'company_id' => $c->id, 'customer_id' => $customer->id, 'collector_user_id' => $u->id,
            'scheduled_for' => today($c->timezone)->toDateString(), 'status' => 'pendiente',
        ]);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->assertOk()->assertSee('COBRAR')
            ->assertSee(route('cobros.collector.payments.create', $account), false);
    }

    public function test_charge_button_appears_only_when_the_gate_allows_it(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '5000.0000');
        [, $paid] = $this->customerWithAccount($c, $b, $u, '100.0000', 'paid');

        $chargeable = app(CobrosPaymentGate::class)->chargeableAccountIds($u, $c, [$customer->id, $paid->customer_id]);
        $this->assertSame([$customer->id => $account->id], $chargeable);

        $content = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))->assertOk()
            ->assertSee('COBRAR')
            ->assertSee(route('cobros.collector.payments.create', $account), false)
            ->getContent();
        // Cliente con cuenta pagada/cancelada: sin botón de cobro.
        $this->assertStringNotContainsString(route('cobros.collector.payments.create', $paid), $content);

        // Sin permiso, el Gate no entrega ninguna cuenta cobrable.
        $u->companies()->updateExistingPivot($c->id, ['role_id' => $this->roleWithout($c, 'cobros.cobrar')->id]);
        $this->assertSame([], app(CobrosPaymentGate::class)
            ->chargeableAccountIds($u->fresh(), $c->fresh(), [$customer->id]));
    }

    public function test_preview_screen_never_creates_payments_receipts_or_cash_movements(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '8888.1234');
        $assignment = CobrosAssignment::query()->forCompany($c->id)->first();

        $before = $account->fresh();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertOk();

        $after = $account->fresh();
        $this->assertSame($before->balance_due, $after->balance_due);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->original_amount, $after->original_amount);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('cobros_assignments', [
            'id' => $assignment->id, 'customer_id' => $customer->id, 'collector_user_id' => $u->id,
        ]);
    }

    public function test_gate_service_itself_never_writes_to_the_database(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '500.0000');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $gate = app(CobrosPaymentGate::class);
        $gate->evaluate($u, $c, $account);
        $gate->evaluate($u, $c, $account->fresh());
        $gate->chargeableAccountIds($u, $c, [$customer->id]);
        $writes = array_values(array_filter(
            DB::getQueryLog(),
            fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q['query'])
        ));
        DB::disableQueryLog();
        $this->assertSame([], $writes);
    }

    public function test_license_company_and_explicit_permission_are_required(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '100.0000');
        $gate = app(CobrosPaymentGate::class);
        $license = $c->license()->firstOrFail();
        $license->update(['expires_at' => now()->subDay(), 'grace_until' => null]);
        $this->assertFalse($gate->canStart($u, $c, $account));
        $license->update(['expires_at' => now()->addMonth(), 'status' => 'suspended']);
        $this->assertFalse($gate->canStart($u, $c, $account));
        $license->update(['status' => 'active']);
        $c->update(['is_active' => false]);
        $this->assertFalse($gate->canStart($u, $c, $account));
        $c->update(['is_active' => true]);
        $role = $this->roleWithout($c, 'cobros.cobrar');
        $role->update(['is_super_admin' => true]);
        $u->companies()->updateExistingPivot($c->id, ['role_id' => $role->id]);
        $this->assertFalse($gate->canStart($u, $c, $account));
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', $account))->assertForbidden();
    }

    public function test_reused_gate_rechecks_assignment_and_visit_url_is_scoped(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '100.0000');
        [, , $other] = $this->extraCollector($c);
        $visit = CobrosVisit::create([
            'company_id' => $c->id, 'customer_id' => $customer->id, 'collector_user_id' => $other->id,
            'scheduled_for' => today()->toDateString(), 'status' => 'pendiente',
        ]);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.create', ['account' => $account, 'visit' => $visit->id]))->assertNotFound();
        $gate = app(CobrosPaymentGate::class);
        $this->assertTrue($gate->canStart($u, $c, $account));
        app(PortfolioAssignmentService::class)->unassign($c, $customer, $u, 'Revocada');
        $this->assertFalse($gate->canStart($u, $c, $account));
    }

    public function test_guest_and_post_cannot_start_or_record_a_payment(): void
    {
        [$c, $b, $u] = $this->context();
        [, $account] = $this->customerWithAccount($c, $b, $u, '100.0000');
        $url = route('cobros.collector.payments.create', $account);
        $this->get($url)->assertRedirect(route('cobros.login'));
        $before = $account->fresh()->getRawOriginal();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post($url, ['amount' => '100'])->assertStatus(405);
        $this->assertSame($before, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'C'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        return [$company->fresh(), $branch, $user];
    }

    private function roleWithout(Company $company, string $denied): Role
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Sin cobrar '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.supervisar', 'cobros.portal_dueno', 'cobros.asignar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $role->permissions()->detach(
            Permission::query()->where('name', $denied)->value('id')
        );

        return $role;
    }

    private function extraCollector(Company $company): array
    {
        $other = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Otro cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $other->companies()->attach($company->id, ['role_id' => $role->id]);
        $other->branches()->attach($company->branches()->first()->id);

        return [$company, $company->branches()->first(), $other];
    }

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $c, Branch $b, User $u, string $balance, string $status = 'pending'): array
    {
        $customer = Customer::create([
            'company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true,
        ]);
        // Asignación preexistente: permite probar permisos revocados sin que el
        // servicio de alta rechace la fixture antes de llegar al gate.
        CobrosAssignment::create([
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $u->id, 'assigned_by' => $u->id, 'assigned_at' => now(),
        ]);

        return [$customer, $this->account($c, $b, $u, $balance, $customer->id, $status)];
    }

    private function account(Company $c, Branch $b, User $u, string $balance, int $customerId, string $status = 'pending'): AccountReceivable
    {
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customerId, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);

        return AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customerId, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => $status, 'currency_code' => 'CRC']);
    }
}
