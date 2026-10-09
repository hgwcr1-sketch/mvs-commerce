<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BLOQUE 14B fase 1 — aislamiento por ruta activa en clientes, créditos,
 * visitas, cobros y recibos. Nunca modifica la pertenencia original.
 */
class CobrosRouteIsolation14BTest extends TestCase
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
        $company = Company::create(['trade_name' => 'Aislamiento '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        return [$company, $this->member($company, ['cobros.acceder', 'cobros.cobrar'])];
    }

    private function routeFor(Company $company, User $collector, string $name): CobrosRoute
    {
        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => $name, 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company,
            User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), now()->addMonth());

        return $route;
    }

    /** Cliente con asignación + CxC con saldo (visible en cartera). @return array{0: Customer, 1: AccountReceivable} */
    private function assignmentWithDebt(Company $company, CobrosRoute $route, User $collector, string $name): array
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => $name, 'customer_type' => 'individual', 'is_active' => true]);
        CobrosAssignment::create(['company_id' => $company->id, 'cobros_route_id' => $route->id,
            'customer_id' => $customer->id, 'collector_user_id' => $collector->id,
            'assigned_by' => $collector->id, 'assigned_at' => now()]);
        $branch = Branch::firstOrCreate(['company_id' => $company->id, 'code' => 'CARTERA'], ['name' => 'Sucursal Cartera', 'is_active' => true]);
        $account = AccountReceivable::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'cobros_route_id' => $route->id,
            'issued_at' => today($company->timezone),
            'due_date' => today($company->timezone)->addMonth(),
            'original_amount' => '5000.0000',
            'balance_due' => '5000.0000',
            'status' => AccountReceivable::STATUS_PENDING,
            'currency_code' => 'CRC',
        ]);

        return [$customer, $account];
    }

    private function receiptFor(Company $company, ?CobrosRoute $route, User $collector, Customer $customer, AccountReceivable $account, string $number): CobrosReceipt
    {
        $branch = Branch::firstOrCreate(['company_id' => $company->id, 'code' => 'CARTERA'], ['name' => 'Sucursal Cartera', 'is_active' => true]);
        $method = PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'EFECT'],
            ['name' => 'Efectivo', 'type' => 'cash', 'is_system' => false, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false]
        );

        return CobrosReceipt::create([
            'company_id' => $company->id,
            'cobros_route_id' => $route?->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'account_receivable_id' => $account->id,
            'collector_user_id' => $collector->id,
            'payment_method_id' => $method->id,
            'amount' => '1000.0000',
            'balance_before' => '5000.0000',
            'balance_after' => '4000.0000',
            'currency_code' => 'CRC',
            'client_token' => Str::random(64),
            'payload_hash' => hash('sha256', $number),
            'receipt_number' => $number,
            'paid_at' => now(),
        ]);
    }

    public function test_payment_entry_blocked_for_other_route_account_via_direct_url(): void
    {
        [$company, $collector] = $this->context();
        $routeA = $this->routeFor($company, $collector, 'Ruta A');
        $routeB = $this->routeFor($company, $collector, 'Ruta B');
        [, $accountA] = $this->assignmentWithDebt($company, $routeA, $collector, 'Cliente A '.uniqid());
        [$customerB, $accountB] = $this->assignmentWithDebt($company, $routeB, $collector, 'Cliente B '.uniqid());

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $routeA->id]);

        // Pantalla de cobro dentro de la ruta activa: regresión (200).
        $this->get(route('cobros.collector.payments.create', $accountA))->assertOk();

        // URL directa / petición manipulada hacia cuenta de otra ruta: 404 (respuesta segura).
        $this->get(route('cobros.collector.payments.create', $accountB))->assertNotFound();
        $this->post(route('cobros.collector.payments.store', $accountB), [
            'amount' => '100',
            'payment_method_id' => 999999,
            'client_token' => (string) Str::uuid(),
        ])->assertNotFound();

        // La pertenencia original nunca cambia.
        $this->assertSame($routeB->id, (int) CobrosAssignment::query()->where('customer_id', $customerB->id)->value('cobros_route_id'));
    }

    public function test_visit_history_and_manage_blocked_for_other_route_customer(): void
    {
        [$company, $collector] = $this->context();
        $routeA = $this->routeFor($company, $collector, 'Ruta A');
        $routeB = $this->routeFor($company, $collector, 'Ruta B');
        [$customerA] = $this->assignmentWithDebt($company, $routeA, $collector, 'Cliente A '.uniqid());
        [$customerB] = $this->assignmentWithDebt($company, $routeB, $collector, 'Cliente B '.uniqid());

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $routeA->id]);
        $this->get(route('cobros.visits.customer', $customerA))->assertOk();
        $this->get(route('cobros.visits.customer', $customerB))->assertNotFound();
        $this->post(route('cobros.visits.manage', $customerB), ['status' => 'visitado'])->assertNotFound();

        // Cambio de ruta sin cerrar sesión: el alcance cambia, los registros no.
        $this->post(route('cobros.routes.store'), ['route_id' => $routeB->id])
            ->assertRedirect(route('cobros.collector.portfolio'));
        $this->assertAuthenticatedAs($collector);
        $this->get(route('cobros.visits.customer', $customerB))->assertOk();
        $this->get(route('cobros.visits.customer', $customerA))->assertNotFound();
        $this->assertSame($routeA->id, (int) CobrosAssignment::query()->where('customer_id', $customerA->id)->value('cobros_route_id'));
    }

    public function test_receipt_history_and_direct_view_respect_active_route(): void
    {
        [$company, $collector] = $this->context();
        $routeA = $this->routeFor($company, $collector, 'Ruta A');
        $routeB = $this->routeFor($company, $collector, 'Ruta B');
        [$customerA, $accountA] = $this->assignmentWithDebt($company, $routeA, $collector, 'Cliente A '.uniqid());
        [$customerB, $accountB] = $this->assignmentWithDebt($company, $routeB, $collector, 'Cliente B '.uniqid());
        $numA = 'RC-A-'.uniqid();
        $numB = 'RC-B-'.uniqid();
        $numLegacy = 'RC-L-'.uniqid();
        $this->receiptFor($company, $routeA, $collector, $customerA, $accountA, $numA);
        $this->receiptFor($company, $routeB, $collector, $customerB, $accountB, $numB);
        // Recibo legítimo sin ruta registrada: sigue visible en cualquier ruta del cobrador.
        $legacy = $this->receiptFor($company, null, $collector, $customerB, $accountB, $numLegacy);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $routeB->id]);
        $this->get(route('cobros.collector.payments.history'))
            ->assertOk()->assertSee($numB)->assertSee($legacy->receipt_number)->assertDontSee($numA);

        $this->get(route('cobros.collector.payments.receipt', $numA))->assertNotFound();
        $this->get(route('cobros.collector.payments.receipt', $numB))->assertOk();
    }

    public function test_payment_routes_require_cobros_cobrar_permission(): void
    {
        $company = Company::create(['trade_name' => 'Perm '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);
        $viewer = $this->member($company, ['cobros.acceder']);
        $route = $this->routeFor($company, $viewer, 'Ruta Perm');
        [, $account] = $this->assignmentWithDebt($company, $route, $viewer, 'Cliente Perm '.uniqid());

        $this->actingAs($viewer)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.payments.create', $account))->assertForbidden();
        $this->get(route('cobros.collector.payments.history'))->assertForbidden();
        $this->post(route('cobros.visits.manage', $account->customer), ['status' => 'visitado'])->assertForbidden();
    }
}
