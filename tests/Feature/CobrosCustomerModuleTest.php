<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cobros\CollectorManagementService;
use App\Services\Cobros\PortfolioAssignmentService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6B — módulo CLIENTES de CobrOS:
 *  - listado + búsqueda del dueño con saldo y cobrador;
 *  - creación/edición por el dueño y detalle con CxC, pagos y recibos;
 *  - "Nuevo cliente" del cobrador con asignación automática a su cartera;
 *  - drill-down del dueño por cobrador;
 *  - aislamiento por empresa y por permisos.
 */
class CobrosCustomerModuleTest extends TestCase
{
    use RefreshDatabase;

    private function ownerContext(): array
    {
        return $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.asignar'], 'Dueño');
    }

    private function collectorContext(): array
    {
        return $this->context(['cobros.acceder', 'cobros.cobrar'], 'Cobrador');
    }

    private function context(array $permissions, string $roleName): array
    {
        $c = Company::create(['trade_name' => 'Clientes '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $b = Branch::create(['company_id' => $c->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $u = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => $roleName, 'is_active' => true]);
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

    private function makeCollector(Company $c): User
    {
        $service = app(CollectorManagementService::class);
        $actor = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $result = $service->createCollector($c, [
            'name' => 'Cobrador '.uniqid(),
            'email' => 'cob'.uniqid().'@test.test',
            'password' => 'password123',
        ], $actor, true);
        $result['user']->update(['must_change_password' => false]);

        return $result['user'];
    }

    private function makeCustomer(Company $c, array $data = []): Customer
    {
        return Customer::create($data + [
            'company_id' => $c->id,
            'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeOpenReceivable(Company $c, Branch $b, Customer $customer, string $balance): AccountReceivable
    {
        $sale = Sale::create([
            'company_id' => $c->id,
            'branch_id' => $b->id,
            'user_id' => User::factory()->create()->id,
            'customer_id' => $customer->id,
            'sale_number' => 'CLI-'.uniqid(),
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return AccountReceivable::create([
            'company_id' => $c->id,
            'branch_id' => $b->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issued_at' => today(),
            'due_date' => today()->addDays(10),
            'original_amount' => $balance,
            'balance_due' => $balance,
            'status' => 'pending',
            'currency_code' => 'CRC',
        ]);
    }

    public function test_owner_sees_clientes_module_in_nav_and_can_search(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $this->makeCustomer($c, ['name' => 'Alfa Uno']);
        $this->makeCustomer($c, ['name' => 'Beta Dos']);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.customers'))
            ->assertOk()
            ->assertSee('Clientes')
            ->assertSee(route('cobros.owner.customers.create'), false)
            ->assertSee('Alfa Uno')
            ->assertSee('Beta Dos')
            ->assertSee('Sin asignar');

        $this->get(route('cobros.owner.customers').'?q=Beta')
            ->assertOk()
            ->assertSee('Beta Dos')
            ->assertDontSee('Alfa Uno');

        $this->get(route('cobros.owner.customers.create'))
            ->assertOk()
            ->assertSee('Nuevo cliente');
    }

    public function test_owner_can_create_edit_and_open_customer_detail(): void
    {
        [$c, $b, $owner] = $this->ownerContext();

        $payload = [
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '123456789',
            'name' => 'Nuevo Cliente MVS',
            'phone' => '88888888',
            'email' => 'nuevo@test.test',
            'address' => 'San José, Costa Rica',
            'notes' => 'Alta desde CobrOS',
        ];

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.customers.store'), $payload)
            ->assertRedirect();

        $customer = Customer::query()->where('name', 'Nuevo Cliente MVS')->firstOrFail();
        $this->assertSame($c->id, (int) $customer->company_id);
        $this->assertNotNull($customer->public_code);

        $this->get(route('cobros.owner.customers.show', $customer))
            ->assertOk()
            ->assertSee('Nuevo Cliente MVS')
            ->assertSee('Saldo total')
            ->assertSee('Cuentas por cobrar abiertas')
            ->assertSee('Historial de cobros');

        $this->get(route('cobros.owner.customers.edit', $customer))
            ->assertOk()
            ->assertSee('Editar cliente');

        $this->put(route('cobros.owner.customers.update', $customer), [
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '123456789',
            'name' => 'Cliente Renombrado',
            'phone' => '88888888',
            'email' => 'nuevo@test.test',
            'address' => 'San José',
            'notes' => 'Actualizado desde CobrOS',
        ])->assertRedirect(route('cobros.owner.customers.show', $customer));

        $fresh = $customer->fresh();
        $this->assertSame('Cliente Renombrado', $fresh->name);
        // CobrOS nunca escribe crédito ni nivel de precio.
        $this->assertSame('normal', $fresh->price_level);
    }

    public function test_owner_customer_detail_shows_open_balance(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $customer = $this->makeCustomer($c, ['name' => 'Con Saldo']);
        $this->makeOpenReceivable($c, $b, $customer, '1500.00');

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.customers'))
            ->assertOk()
            ->assertSee('Con Saldo')
            ->assertSee('1,500.00');

        $this->get(route('cobros.owner.customers.show', $customer))
            ->assertOk()
            ->assertSee('1,500.00');
    }

    public function test_customer_module_is_isolated_by_company(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        [$c2, $b2, $owner2] = $this->context(['cobros.acceder', 'cobros.portal_dueno'], 'Dueño ajeno');
        $foreign = $this->makeCustomer($c2, ['name' => 'Ajeno Total']);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.customers'))
            ->assertOk()
            ->assertDontSee('Ajeno Total');

        $this->get(route('cobros.owner.customers.show', $foreign))->assertNotFound();
        $this->get(route('cobros.owner.customers.edit', $foreign))->assertNotFound();
        $this->put(route('cobros.owner.customers.update', $foreign), [
            'customer_type' => 'individual',
            'name' => 'Intento Ajeno',
        ])->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $foreign->id, 'name' => 'Ajeno Total']);
    }

    public function test_collector_creates_customer_and_is_auto_assigned(): void
    {
        [$c, $b, $collector] = $this->collectorContext();

        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.customers.create'))
            ->assertOk()
            ->assertSee('Nuevo cliente')
            ->assertSee('GUARDAR UBICACIÓN ACTUAL')
            ->assertDontSee('Monto / límite de crédito');

        // company_id del request se descarta: solo manda la empresa de la sesión.
        $this->post(route('cobros.collector.customers.store'), [
            'customer_type' => 'individual',
            'name' => 'Cliente Cobrador',
            'identification' => '333222111',
            'phone' => '88887777',
            'company_id' => 999999,
        ])->assertRedirect();

        $customer = Customer::query()->where('name', 'Cliente Cobrador')->firstOrFail();
        $this->assertSame($c->id, (int) $customer->company_id);
        $this->assertDatabaseHas('cobros_assignments', [
            'company_id' => $c->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $collector->id,
        ]);
        $this->assertSame('0.00', (string) $customer->credit_limit);
        $this->assertNull($customer->latitude);
        $this->assertNull($customer->longitude);

        $this->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()
            ->assertSee('Cliente Cobrador');
    }

    public function test_collector_gps_is_optional_and_saved_without_validation_metadata(): void
    {
        [$c, $b, $collector] = $this->collectorContext();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customers.store'), [
                'customer_type' => 'individual',
                'name' => 'Cliente con GPS',
                'latitude' => '9.93250000',
                'longitude' => '-84.08000000',
            ])->assertRedirect();

        $customer = Customer::query()->where('name', 'Cliente con GPS')->firstOrFail();
        $this->assertSame($c->id, (int) $customer->company_id);
        $this->assertSame('9.93250000', (string) $customer->latitude);
        $this->assertSame('-84.08000000', (string) $customer->longitude);
        $this->assertNull($customer->location_validated_at);
        $this->assertNull($customer->location_validated_by);
    }

    public function test_owner_can_enable_credit_assignment_for_collector_customer_creation(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        [$otherCompany] = $this->context(['cobros.acceder', 'cobros.portal_dueno'], 'Otro dueño');

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.customers'))
            ->assertOk()
            ->assertSee('Permitir que los cobradores asignen crédito al crear clientes')
            ->assertDontSee('checked');

        $this->patch(route('cobros.owner.customers.credit-setting'), [
            'allow_collector_credit_on_customer_create' => '1',
            'collector_customer_credit_limit_max' => '50000.00',
        ])->assertRedirect();

        $this->assertTrue($c->fresh()->allow_collector_credit_on_customer_create);
        $this->assertSame('50000.00', (string) $c->fresh()->collector_customer_credit_limit_max);
        $this->assertFalse($otherCompany->fresh()->allow_collector_credit_on_customer_create);

        $this->patch(route('cobros.owner.customers.credit-setting'), [
            'allow_collector_credit_on_customer_create' => '1',
            'collector_customer_credit_limit_max' => '0',
        ])->assertSessionHasErrors('collector_customer_credit_limit_max');
        $this->assertSame('50000.00', (string) $c->fresh()->collector_customer_credit_limit_max);

        [$collectorCompany, $branch, $collector] = $this->collectorContext();
        $collectorCompany->update([
            'allow_collector_credit_on_customer_create' => true,
            'collector_customer_credit_limit_max' => '50000.00',
        ]);
        $this->actingAs($collector)->withSession(['active_company_id' => $collectorCompany->id])
            ->get(route('cobros.collector.customers.create'))
            ->assertOk()
            ->assertSee('Monto / límite de crédito')
            ->assertSee('Máximo autorizado: ₡50000.00')
            ->assertSee('Plazo de crédito');

        $this->post(route('cobros.collector.customers.store'), [
            'customer_type' => 'individual',
            'name' => 'Crédito sin plazo',
            'credit_limit' => '1250.75',
        ])->assertSessionHasErrors('credit_days');
        $this->assertDatabaseMissing('customers', ['name' => 'Crédito sin plazo']);

        $this->post(route('cobros.collector.customers.store'), [
            'customer_type' => 'individual',
            'name' => 'Supera máximo',
            'credit_limit' => '50000.01',
            'credit_days' => '30',
        ])->assertSessionHasErrors('credit_limit');
        $this->assertDatabaseMissing('customers', ['name' => 'Supera máximo']);

        $this->post(route('cobros.collector.customers.store'), [
            'customer_type' => 'individual',
            'name' => 'Cliente con crédito',
            'credit_limit' => '50000.00',
            'credit_days' => '30',
            'company_id' => $otherCompany->id,
        ])->assertRedirect();

        $customer = Customer::query()->where('name', 'Cliente con crédito')->firstOrFail();
        $this->assertSame($collectorCompany->id, (int) $customer->company_id);
        $this->assertSame('50000.00', (string) $customer->credit_limit);
        $this->assertSame(30, (int) $customer->credit_days);
    }

    public function test_credit_fields_are_rejected_when_setting_is_disabled(): void
    {
        [$c, $b, $collector] = $this->collectorContext();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customers.store'), [
                'customer_type' => 'individual',
                'name' => 'No autorizar crédito',
                'credit_limit' => '10000.00',
                'credit_days' => '30',
            ])->assertSessionHasErrors(['credit_limit', 'credit_days']);

        $this->assertDatabaseMissing('customers', ['name' => 'No autorizar crédito']);
    }

    public function test_collector_cannot_reach_owner_customer_module(): void
    {
        [$c, $b, $collector] = $this->collectorContext();
        $customer = $this->makeCustomer($c, ['name' => 'Solo del dueño']);

        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.customers'))->assertForbidden();
        $this->get(route('cobros.owner.customers.create'))->assertForbidden();
        $this->post(route('cobros.owner.customers.store'), ['customer_type' => 'individual', 'name' => 'X'])->assertForbidden();
        $this->get(route('cobros.owner.customers.show', $customer))->assertForbidden();
        $this->get(route('cobros.owner.collectors.show', $collector->id))->assertForbidden();
    }

    public function test_owner_drills_down_into_collector_portfolio(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $collector = $this->makeCollector($c);
        $customer = $this->makeCustomer($c, ['name' => 'Cliente Cartera']);
        $this->makeOpenReceivable($c, $b, $customer, '750.00');

        app(PortfolioAssignmentService::class)->assign($c, $customer, $collector, $owner, 'prueba drill-down');

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.collectors.show', $collector->id))
            ->assertOk()
            ->assertSee($collector->name)
            ->assertSee('Cliente Cartera')
            ->assertSee('750.00')
            ->assertSee('Saldo pendiente total');

        // Miembro de la empresa sin permiso de cobro: 404 (no es cobrador).
        $plain = User::factory()->create(['is_active' => true]);
        $plainRole = Role::create(['company_id' => $c->id, 'name' => 'Sin cobro', 'is_active' => true]);
        $plain->companies()->attach($c->id, ['role_id' => $plainRole->id]);
        $this->get(route('cobros.owner.collectors.show', $plain->id))->assertNotFound();

        // Usuario de otra empresa: 404.
        [$c2, $b2, $owner2] = $this->context(['cobros.acceder', 'cobros.portal_dueno'], 'Dueño ajeno');
        $this->get(route('cobros.owner.collectors.show', $owner2->id))->assertNotFound();
    }

    public function test_validation_rejects_missing_name_and_duplicate_identification(): void
    {
        [$c, $b, $owner] = $this->ownerContext();
        $this->makeCustomer($c, ['identification' => '999888777', 'name' => 'Duplicado Base']);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.customers.store'), ['customer_type' => 'individual'])
            ->assertSessionHasErrors(['name']);

        $this->post(route('cobros.owner.customers.store'), [
            'customer_type' => 'individual',
            'name' => 'Otro Cliente',
            'identification' => '999888777',
        ])->assertSessionHasErrors('identification');

        // El cobrador hereda las mismas reglas de unicidad por empresa.
        [$c2, $b2, $collector] = $this->collectorContext();
        $this->makeCustomer($c2, ['identification' => '555444333', 'name' => 'Base Cobrador']);
        $this->actingAs($collector)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.collector.customers.store'), [
                'customer_type' => 'individual',
                'name' => 'Repetido',
                'identification' => '555444333',
            ])->assertSessionHasErrors('identification');
    }
}
