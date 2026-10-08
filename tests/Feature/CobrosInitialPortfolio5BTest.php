<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosInitialPortfolioMigration;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloque 5B — "AGREGAR CLIENTE" dentro de la migración inicial 5A.
 *
 * Cubre: alta con migración abierta, bloqueo con cerrada, tenant/permiso,
 * duplicado por identificación, GPS opcional, frecuencia+próxima fecha,
 * precisión monetaria, historial declarado separado, source/migración/actor
 * permanentes y cero alteración de pagos/clientes previos.
 */
class CobrosInitialPortfolio5BTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_migration_creates_client_with_permanent_source_actor_and_destinations(): void
    {
        [$c, $owner] = $this->context();
        $this->open($c, $owner);
        $paymentsBefore = \App\Models\AccountReceivablePayment::count();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio.client'))
            ->assertOk()
            ->assertSee('INFORMACIÓN DECLARADA AL INGRESAR A COBROS')
            ->assertSee('Ubicación pendiente')
            ->assertSee('GUARDAR Y AGREGAR SIGUIENTE')
            ->assertSee('GUARDAR Y VOLVER A CARTERA');

        $payload = $this->payload(['action' => 'volver']);
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $payload)
            ->assertRedirect(route('cobros.initial-portfolio'));

        $record = CobrosInitialPortfolioRecord::sole();
        $migration = CobrosInitialPortfolioMigration::sole();
        $this->assertSame($migration->id, $record->migration_id);
        $this->assertSame($c->id, $record->company_id);
        $this->assertSame(CobrosInitialPortfolioRecord::SOURCE_INITIAL, $record->source);
        $this->assertSame(CobrosInitialPortfolioRecord::VERIFICATION_DECLARED, $record->verification);
        $this->assertSame($owner->id, $record->migrated_by_user_id);
        $this->assertNotNull($record->migrated_at);
        $this->assertFalse($record->customer_reused);
        $this->assertSame('quincenal', $record->frequency);
        $this->assertSame('2026-10-07', $record->next_charge_date->toDateString());

        $customer = Customer::query()->where('identification', $payload['identification'])->first();
        $this->assertNotNull($customer);
        $this->assertSame($c->id, $customer->company_id);
        $this->assertSame('01', $customer->identification_type);
        $this->assertSame($record->customer_id, $customer->id);

        // Cero alteración de pagos/clientes previos.
        $this->assertSame($paymentsBefore, \App\Models\AccountReceivablePayment::count());
        $this->assertDatabaseCount('cobros_receipts', 0);

        // Botón "siguiente" regresa al formulario.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['action' => 'siguiente']))
            ->assertRedirect(route('cobros.initial-portfolio.client'));
    }

    public function test_closed_or_missing_migration_blocks_both_endpoints(): void
    {
        [$c, $owner] = $this->context();
        $this->open($c, $owner);
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio.client'))->assertForbidden();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);

        // Sin migración iniciada tampoco se puede cargar (URL manipulada).
        [$c2, $owner2] = $this->context();
        $this->actingAs($owner2)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.initial-portfolio.client'))->assertForbidden();
        $this->actingAs($owner2)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload())->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Helpers

    public function test_tenant_permission_and_duplicate_identification(): void
    {
        [$c, $owner] = $this->context();
        $this->open($c, $owner);

        // Sin permiso de acceso (rol sin cobros.acceder) => 403 en ambos endpoints.
        [$c2, $sinPermiso] = $this->context(['cobros.portal_dueno']);
        $this->actingAs($sinPermiso)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.initial-portfolio.client'))->assertForbidden();
        $this->actingAs($sinPermiso)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload())->assertForbidden();

        // Identificación existente en OTRO tenant: no se reutiliza ni duplica.
        [$fc, $fu] = $this->context();
        $foreign = Customer::create(['company_id' => $fc->id, 'customer_type' => 'individual', 'identification' => '309999999', 'name' => 'Ajeno', 'is_active' => true]);
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['identification' => '309999999']))
            ->assertSessionHasErrors('identification');
        $this->assertSame($fc->id, $foreign->fresh()->company_id);
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);

        // Mismo tenant: reutiliza el cliente y bloquea un segundo registro.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['identification' => '301111111']))
            ->assertRedirect(route('cobros.initial-portfolio'));
        $this->assertSame(1, Customer::where('identification', '301111111')->where('company_id', $c->id)->count());

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['identification' => '301111111', 'name' => 'Otro Nombre']))
            ->assertSessionHasErrors('identification');
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 1);
        $this->assertSame(1, Customer::where('identification', '301111111')->where('company_id', $c->id)->count());
        $this->assertSame('Cliente Migrado', substr(Customer::where('identification', '301111111')->first()->name, 0, 15));
    }

    public function test_gps_is_optional_and_frequency_next_date_are_strict(): void
    {
        [$c, $owner] = $this->context();
        $this->open($c, $owner);

        // Sin GPS => alta permitida (Ubicación pendiente, no bloquea).
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['action' => 'siguiente']))
            ->assertRedirect(route('cobros.initial-portfolio.client'));
        $customer = Customer::latest('id')->first();
        $this->assertNull($customer->latitude);
        $this->assertNull($customer->longitude);

        // Con GPS => se guarda en el Customer existente.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['latitude' => '9.931', 'longitude' => '-84.082']))
            ->assertRedirect(route('cobros.initial-portfolio'));
        $withGps = Customer::latest('id')->first();
        $this->assertSame('9.93100000', (string) $withGps->latitude);

        // Frecuencia inválida y próxima fecha ausente => errores, sin registro.
        $records = CobrosInitialPortfolioRecord::count();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['frequency' => 'semanal_cualquiera']))
            ->assertSessionHasErrors('frequency');
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['next_charge_date' => '']))
            ->assertSessionHasErrors('next_charge_date');
        $this->assertSame($records, CobrosInitialPortfolioRecord::count());

        // La fecha guardada es literalmente la declarada (sin asumir días 1/15).
        $record = CobrosInitialPortfolioRecord::latest('id')->first();
        $this->assertSame('quincenal', $record->frequency);
        $this->assertSame('2026-10-07', $record->next_charge_date->toDateString());
    }

    public function test_money_precision_declared_history_and_no_finance_changes(): void
    {
        [$c, $owner] = $this->context();
        $this->open($c, $owner);

        $branch = $c->branches()->first();
        $old = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Preexistente', 'is_active' => true]);
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $branch->id, 'user_id' => $owner->id, 'customer_id' => $old->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $branch->id, 'customer_id' => $old->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => '777.0000', 'balance_due' => '777.0000', 'status' => 'pending', 'currency_code' => 'CRC']);

        // Monto con 5 decimales => rechazado (BCMath, sin redondeo silencioso).
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload(['balance' => '10.12345']))
            ->assertSessionHasErrors('balance');

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), $this->payload([
                'original_amount' => '2000', 'balance' => '1500.1234', 'installment' => '100.50',
                'prior_credits' => '3', 'declared_behavior' => 'malo',
                'historical_note' => 'Pagaba tarde pero siempre saldaba.',
            ]))->assertRedirect(route('cobros.initial-portfolio'));

        $record = CobrosInitialPortfolioRecord::sole();
        $this->assertSame('2000.0000', (string) $record->original_amount);
        $this->assertSame('1500.1234', (string) $record->balance);
        $this->assertSame('100.5000', (string) $record->installment);
        // Historial declarado separado del historial verificado.
        $this->assertSame('informacion_declarada', $record->verification);
        $this->assertSame(3, $record->prior_credits);
        $this->assertSame('malo', $record->declared_behavior);
        $this->assertSame('Pagaba tarde pero siempre saldaba.', $record->historical_note);
        $this->assertSame(CobrosInitialPortfolioRecord::SOURCE_INITIAL, $record->source);

        // Cero alteración de CxC/pagos/recibos existentes.
        $this->assertSame('777.0000', $account->fresh()->balance_due);
        // 2 = CxC preexistente 777 + nueva CxC declarada 1500.1234 del registro migrado.
        $this->assertDatabaseCount('accounts_receivable', 2);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    // ------------------------------------------------------------------
    // Helpers

    private function open(Company $c, User $owner): void
    {
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'identification_type' => 'cedula',
            'identification' => '1'.random_int(100000000, 999999999),
            'name' => 'Cliente Migrado '.uniqid(),
            'phone' => '8888 8888',
            'balance' => '1500.1234',
            'installment' => '100.50',
            'frequency' => 'quincenal',
            'next_charge_date' => '2026-10-07',
            'declared_behavior' => 'regular',
            'action' => 'volver',
        ], $overrides);
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Prestamista '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'B'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Prestamista '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        return [$company->fresh(), $user];
    }
}
