<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
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

/**
 * 5B => CxC operativa: el saldo declarado se convierte en UNA CxC real en la
 * ruta activa, visible en "Mi cartera" con saldo y botón COBRAR. Sin pagos ni
 * recibos ficticios; reintento idempotente; aislamiento por ruta.
 */
class CobrosMigratedCreditCxCeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: CobrosRoute} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'MigrCxC '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'M'.random_int(10, 99), 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        $routeA = CobrosRoute::create(['company_id' => $company->id, 'name' => 'San Ramón', 'active' => true]);
        $routeA->users()->attach($user->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($routeA, $company, $admin, now()->addMonth());

        // Ruta B activa pero SIN licencia: no disponible, sirve para aislamiento.
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Palmares', 'active' => true]);
        $routeB->users()->attach($user->id, ['active' => true]);

        return [$company, $user, $routeA, $routeB];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'identification_type' => 'cedula',
            'identification' => '1'.random_int(100000000, 999999999),
            'name' => 'Cliente Migrado '.uniqid(),
            'phone' => '8888 8888',
            'balance' => '30000',
            'installment' => '1000',
            'frequency' => 'quincenal',
            'next_charge_date' => '2026-10-15',
            'declared_behavior' => 'bueno',
            'action' => 'volver',
        ], $overrides);
    }

    private function open(Company $company, User $collector): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
    }

    public function test_migrated_balance_becomes_single_routed_open_cxc_and_shows_in_portfolio(): void
    {
        [$company, $collector, $routeA, $routeB] = $this->context();
        $this->open($company, $collector);

        $payload = $this->payload();
        // 1 sola ruta disponible (B sin licencia) => el middleware auto-selecciona A.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.initial-portfolio.client.store'), $payload)
            ->assertRedirect(route('cobros.initial-portfolio'));

        $customer = Customer::query()->where('identification', $payload['identification'])->firstOrFail();

        // 1) CxC única con saldo exacto decimal4 y ruta A.
        $this->assertDatabaseCount('accounts_receivable', 1);
        $account = AccountReceivable::sole();
        $this->assertSame('30000.0000', (string) $account->balance_due);
        $this->assertSame('30000.0000', (string) $account->original_amount);
        $this->assertSame($company->id, $account->company_id);
        $this->assertSame($customer->id, $account->customer_id);
        $this->assertSame($routeA->id, $account->cobros_route_id);
        $this->assertSame('pending', $account->status);

        // 2) Assignment con la MISMA ruta.
        $assignment = CobrosAssignment::sole();
        $this->assertSame($customer->id, $assignment->customer_id);
        $this->assertSame($routeA->id, $assignment->cobros_route_id);

        // 3) Mi cartera: saldo visible + 1 CxC + COBRAR + etiqueta Ruta.
        $this->get(route('cobros.collector.portfolio'))->assertOk()
            ->assertSee('30,000.00')
            ->assertSee('Ruta')
            ->assertSee('San Ramón')
            ->assertSee('COBRAR')
            ->assertDontSee('Sucursal(es)');

        // 4) Reintento con la misma identificación: no duplica CxC ni assignment.
        $this->post(route('cobros.initial-portfolio.client.store'), $payload)
            ->assertSessionHasErrors('identification');
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_assignments', 1);

        // 5) Cero Payment/Receipt ficticios.
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);

        // 6) Ruta A (activa) no ve el crédito asignado a B.
        $other = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Palmares '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create(['company_id' => $company->id, 'cobros_route_id' => $routeB->id,
            'customer_id' => $other->id, 'collector_user_id' => $collector->id,
            'assigned_by' => $collector->id, 'assigned_at' => now()]);

        $this->get(route('cobros.collector.portfolio'))->assertOk()
            ->assertSee($customer->name)
            ->assertSee('San Ramón')
            ->assertDontSee('Palmares');
    }
}