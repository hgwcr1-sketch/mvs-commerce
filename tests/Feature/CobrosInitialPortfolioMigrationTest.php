<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosInitialPortfolioEvent;
use App\Models\CobrosInitialPortfolioMigration;
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
 * Bloque 5A — control del período de migración inicial ("Cargar mi cartera actual").
 *
 * Reglas certificadas aquí:
 * - estado inicial disponible, iniciar/continuar mientras abierta;
 * - finalizar con confirmación fuerte => bloqueada y la opción desaparece;
 * - el prestamista NO reabre; solo Platform Admin/MVS con motivo auditable;
 * - tenant + permisos aislados y preservación total de datos financieros.
 */
class CobrosInitialPortfolioMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_state_is_available_with_clear_button_and_explanation(): void
    {
        [$c, $owner] = $this->context();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio'))
            ->assertOk()
            ->assertSee('COMENZAR A CARGAR MI CARTERA')
            ->assertSee('antes de CobrOS')
            ->assertSee('Disponible')
            ->assertDontSee('FINALIZAR MIGRACIÓN')
            // Navegación operativa oculta durante la migración.
            ->assertDontSee('Cierre del día', false)
            ->assertDontSee('cobros-more-panel', false);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertSee('CARGAR MI CARTERA ACTUAL');

        $this->assertDatabaseCount('cobros_initial_portfolio_migrations', 0);

        // El prestamista no carga cartera inicial: su pantalla es el resumen (403).
        [$c2, $prestamista] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.cobrar']);
        $this->actingAs($prestamista)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.initial-portfolio'))
            ->assertForbidden();
    }

    public function test_owner_can_start_and_continue_while_open_and_others_cannot(): void
    {
        [$c, $owner] = $this->context();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))
            ->assertRedirect(route('cobros.initial-portfolio.client'));

        $migration = CobrosInitialPortfolioMigration::sole();
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS, $migration->status);
        $this->assertSame($owner->id, $migration->started_by_user_id);
        $this->assertNotNull($migration->started_at);

        // Continuar: idempotente, sigue abierta y sin filas duplicadas.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))
            ->assertRedirect(route('cobros.initial-portfolio.client'));
        $this->assertDatabaseCount('cobros_initial_portfolio_migrations', 1);
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS, $migration->fresh()->status);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio'))
            ->assertOk()
            ->assertSee('En progreso')
            ->assertSee('AGREGAR CLIENTE')
            ->assertSee('FINALIZAR MIGRACIÓN');

        // El prestamista no inicia ni finaliza la carga del cobrador.
        [$c2, $prestamista] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.cobrar']);
        $this->actingAs($prestamista)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.initial-portfolio.start'))->assertForbidden();
        $this->assertDatabaseCount('cobros_initial_portfolio_migrations', 1);
    }

    public function test_finalize_requires_strong_confirmation_and_hides_the_option(): void
    {
        [$c, $owner] = $this->context();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();

        // Confirmación débil: rechazada y sigue abierta.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'finalizar'])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(
            CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS,
            CobrosInitialPortfolioMigration::sole()->status
        );

        // Confirmación fuerte: bloqueada.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])
            ->assertRedirect(route('cobros.collector.index'));

        $migration = CobrosInitialPortfolioMigration::sole();
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_FINALIZED, $migration->status);
        $this->assertSame($owner->id, $migration->finalized_by_user_id);
        $this->assertNotNull($migration->finalized_at);

        // La opción desaparece para prestamista y cobradores.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio'))
            ->assertOk()
            ->assertSee('Finalizada')
            ->assertDontSee('CARGAR MI CARTERA ACTUAL')
            ->assertDontSee('FINALIZAR MIGRACIÓN');

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()->assertDontSee('CARGAR MI CARTERA ACTUAL');

        // Cerrada: el prestamista no puede iniciar de nuevo.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertForbidden();
    }

    public function test_owner_cannot_reopen_a_finalized_migration(): void
    {
        [$c, $owner] = $this->context();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('platform.initial-portfolio.reopen', [$c, $owner]), ['reason' => 'Corrección solicitada por el prestamista'])
            ->assertForbidden();

        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_FINALIZED, CobrosInitialPortfolioMigration::sole()->status);
    }

    public function test_platform_admin_can_reopen_with_auditable_reason(): void
    {
        [$c, $owner] = $this->context();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        // Sin motivo no se reabre.
        $this->actingAs($admin)
            ->post(route('platform.initial-portfolio.reopen', [$c, $owner]), ['reason' => 'abc'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_FINALIZED, CobrosInitialPortfolioMigration::sole()->status);

        // Reapertura excepcional auditable: quién, cuándo, motivo.
        $this->actingAs($admin)
            ->post(route('platform.initial-portfolio.reopen', [$c, $owner]), ['reason' => 'Corrección de cartera inicial solicitada'])
            ->assertRedirect(route('platform.companies.show', $c));

        $migration = CobrosInitialPortfolioMigration::sole();
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS, $migration->status);
        $this->assertSame($admin->id, $migration->reopened_by_user_id);
        $this->assertNotNull($migration->reopened_at);
        $this->assertSame('Corrección de cartera inicial solicitada', $migration->reopen_reason);
        $this->assertSame(1, $migration->reopen_count);

        // La opción vuelve a estar disponible para el prestamista.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()->assertSee('CARGAR MI CARTERA ACTUAL');
    }

    public function test_tenant_isolation_and_financial_data_are_preserved(): void
    {
        [$c, $owner] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $owner, '1500.0000');

        $before = [
            'customers' => Customer::count(),
            'accounts' => AccountReceivable::count(),
            'payments' => \App\Models\AccountReceivablePayment::count(),
            'receipts' => CobrosReceipt::count(),
            'assignments' => CobrosAssignment::count(),
        ];

        // Ciclo completo: iniciar -> finalizar -> reapertura de Platform Admin.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $this->actingAs($admin)
            ->post(route('platform.initial-portfolio.reopen', [$c, $owner]), ['reason' => 'Reapertura autorizada por MVS'])
            ->assertRedirect();

        // Preservación financiera: nada de clientes, CxC, pagos ni recibos cambia.
        $this->assertSame($before, [
            'customers' => Customer::count(),
            'accounts' => AccountReceivable::count(),
            'payments' => \App\Models\AccountReceivablePayment::count(),
            'receipts' => CobrosReceipt::count(),
            'assignments' => CobrosAssignment::count(),
        ]);
        $this->assertSame('1500.0000', $account->fresh()->balance_due);
        $this->assertSame($customer->fresh()->name, $customer->name);

        // Aislamiento tenant: otra empresa empieza en "disponible" y no toca la fila ajena.
        [$c2, $owner2] = $this->context();
        $this->actingAs($owner2)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.initial-portfolio'))
            ->assertOk()->assertSee('Disponible');

        $this->assertDatabaseCount('cobros_initial_portfolio_migrations', 1);
        $this->assertSame($c->id, CobrosInitialPortfolioMigration::sole()->company_id);
    }

    /** FIX 5A: cerrar → reabrir → cerrar conserva AMBOS cierres y la reapertura. */
    public function test_close_reopen_close_keeps_full_append_only_chronology(): void
    {
        [$c, $owner] = $this->context();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();

        $firstClose = CobrosInitialPortfolioMigration::sole();
        $firstCloseAt = $firstClose->finalized_at;
        $this->assertNotNull($firstCloseAt);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $this->actingAs($admin)
            ->post(route('platform.initial-portfolio.reopen', [$c, $owner]), ['reason' => 'Corrección autorizada por MVS'])
            ->assertRedirect();

        // La reapertura NO destruye el cierre anterior (actor/fecha intactos).
        $afterReopen = CobrosInitialPortfolioMigration::sole();
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS, $afterReopen->status);
        $this->assertNotNull($afterReopen->finalized_at);
        $this->assertSame($owner->id, $afterReopen->finalized_by_user_id);
        $this->assertSame($firstCloseAt->toDateTimeString(), $afterReopen->finalized_at->toDateTimeString());
        $this->assertSame($admin->id, $afterReopen->reopened_by_user_id);
        $this->assertNotNull($afterReopen->reopened_at);
        $this->assertSame('Corrección autorizada por MVS', $afterReopen->reopen_reason);
        $this->assertSame(1, $afterReopen->reopen_count);

        // Segundo cierre: agrega un nuevo cierre sin borrar el primero.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])
            ->assertRedirect();

        $secondClose = CobrosInitialPortfolioMigration::sole();
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_FINALIZED, $secondClose->status);
        $this->assertGreaterThanOrEqual($firstCloseAt->timestamp, $secondClose->finalized_at->timestamp);
        $this->assertSame($owner->id, $secondClose->finalized_by_user_id);

        // Cronología append-only completa: apertura → cierre → reapertura → nuevo cierre.
        $events = CobrosInitialPortfolioEvent::forCompany($c->id)->orderBy('id')->get();
        $this->assertCount(4, $events);
        $this->assertSame(
            [CobrosInitialPortfolioEvent::TYPE_OPEN, CobrosInitialPortfolioEvent::TYPE_CLOSE, CobrosInitialPortfolioEvent::TYPE_REOPEN, CobrosInitialPortfolioEvent::TYPE_CLOSE],
            $events->pluck('type')->all()
        );
        $this->assertSame([$owner->id, $owner->id, $admin->id, $owner->id], $events->pluck('actor_user_id')->all());
        $this->assertSame([null, null, 'Corrección autorizada por MVS', null], $events->pluck('reason')->all());
        foreach ($events as $event) {
            $this->assertNotNull($event->occurred_at, 'Cada evento conserva su fecha.');
            $this->assertNotNull($event->actor_user_id, 'Cada evento conserva su actor.');
            $this->assertDatabaseCount('cobros_initial_portfolio_events', 4);
        }
        $this->assertTrue($events->pluck('id')->all() === $events->pluck('id')->sort()->values()->all(), 'Solo append: ids crecientes.');
    }

    // ------------------------------------------------------------------
    // Helpers

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Prestamista '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'M'.random_int(1, 99), 'is_active' => true]);
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

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $c, User $u, string $balance): array
    {
        $branch = $c->branches()->first();
        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create(['company_id' => $c->id, 'customer_id' => $customer->id, 'collector_user_id' => $u->id, 'assigned_by' => $u->id, 'assigned_at' => now()]);
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $branch->id, 'user_id' => $u->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC']);

        return [$customer, $account];
    }
}
