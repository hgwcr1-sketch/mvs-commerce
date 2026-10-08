<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosInitialPortfolioMigration;
use App\Models\CobrosInitialPortfolioRecord;
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
 * Bloque 6 — configuración OPERATIVA de día/frecuencia de visita del cobrador.
 *
 * Separa estrictamente fecha financiera (AccountReceivable.due_date) y fecha
 * operativa de visita: guardar agenda jamás modifica CxC, saldos ni crédito.
 * Aislamiento: company + collector + cartera/ruta activa (sin IDOR).
 */
class CobrosVisitScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_frequencies_diario_semanal_quincenal_mensual_persist(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithCustomer();

        $cases = [
            ['visit_frequency' => 'diario'],
            ['visit_frequency' => 'semanal', 'visit_weekday' => 2, 'next_visit_date' => '2026-10-13'],
            ['visit_frequency' => 'quincenal', 'next_visit_date' => '2026-10-07'],
            ['visit_frequency' => 'mensual', 'visit_month_day' => 15, 'next_visit_date' => '2026-11-15'],
        ];

        foreach ($cases as $payload) {
            $this->actingAs($u)->withSession(['active_company_id' => $c->id])
                ->post(route('cobros.collector.customer.visit-schedule', $customer), $payload)
                ->assertRedirect(route('cobros.collector.portfolio.show', $customer));

            $assignment = CobrosAssignment::query()->forCompany($c->id)
                ->where('customer_id', $customer->id)->where('collector_user_id', $u->id)->firstOrFail();
            $this->assertSame($payload['visit_frequency'], $assignment->visit_frequency);
            $this->assertSame($payload['visit_weekday'] ?? null, $assignment->visit_weekday);
            $this->assertSame($payload['visit_month_day'] ?? null, $assignment->visit_month_day);
            $this->assertSame(
                $payload['next_visit_date'] ?? null,
                $assignment->next_visit_date?->toDateString()
            );
        }
    }

    public function test_validations_for_weekday_month_day_and_quincenal_anchor(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithCustomer();
        $post = fn (array $payload) => $this->actingAs($u)
            ->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customer.visit-schedule', $customer), $payload);

        $post([])->assertSessionHasErrors('visit_frequency');
        $post(['visit_frequency' => 'trimestral'])->assertSessionHasErrors('visit_frequency');

        $post(['visit_frequency' => 'semanal'])->assertSessionHasErrors('visit_weekday');
        $post(['visit_frequency' => 'semanal', 'visit_weekday' => 7])->assertSessionHasErrors('visit_weekday');

        $post(['visit_frequency' => 'mensual'])->assertSessionHasErrors('visit_month_day');
        $post(['visit_frequency' => 'mensual', 'visit_month_day' => 32])->assertSessionHasErrors('visit_month_day');

        $post(['visit_frequency' => 'quincenal'])->assertSessionHasErrors('next_visit_date');

        $this->assertNull(
            CobrosAssignment::query()->forCompany($c->id)->where('customer_id', $customer->id)->firstOrFail()->visit_frequency
        );
    }

    public function test_next_visit_date_persists_and_show_renders_block(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithCustomer();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customer.visit-schedule', $customer), [
                'visit_frequency' => 'mensual', 'visit_month_day' => 5, 'next_visit_date' => '2026-11-05',
            ])->assertRedirect();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()
            ->assertSee('Día de cobro / visita')
            ->assertSee('value="2026-11-05"', false)
            ->assertSee('GUARDAR DÍA DE VISITA')
            ->assertViewHas('visitSchedule', fn ($a) => $a !== null && $a->visit_frequency === 'mensual');
    }

    public function test_isolation_company_collector_and_portfolio_without_idor(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithCustomer();
        [$c2, $b2, $foreign] = $this->collectorWithCustomer();

        // Cliente ajeno (otra empresa/cartera): 404, sin escribir nada.
        $this->actingAs($foreign)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.collector.customer.visit-schedule', $customer), ['visit_frequency' => 'diario'])
            ->assertNotFound();

        // Misma empresa pero cliente fuera de la cartera del cobrador: 404.
        $other = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Otro '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $other->companies()->attach($c->id, ['role_id' => $role->id]);
        $other->branches()->attach($b->id);

        $this->actingAs($other)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customer.visit-schedule', $customer), ['visit_frequency' => 'diario'])
            ->assertNotFound();

        $this->assertNull(CobrosAssignment::query()->forCompany($c->id)
            ->where('customer_id', $customer->id)->where('collector_user_id', $other->id)->first()?->visit_frequency);
    }

    public function test_saving_schedule_never_touches_account_receivable_due_date_or_balance(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithCustomer();
        $account = AccountReceivable::query()->where('customer_id', $customer->id)->firstOrFail();

        $before = [
            'due_date' => $account->due_date->toDateString(),
            'balance_due' => (string) $account->balance_due,
            'original_amount' => (string) $account->original_amount,
        ];

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customer.visit-schedule', $customer), [
                'visit_frequency' => 'semanal', 'visit_weekday' => 2, 'next_visit_date' => '2026-10-13',
            ])->assertRedirect();

        $account->refresh();
        $this->assertSame($before['due_date'], $account->due_date->toDateString());
        $this->assertSame($before['balance_due'], (string) $account->balance_due);
        $this->assertSame($before['original_amount'], (string) $account->original_amount);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    public function test_backfill_from_5b_only_when_unconfigured_and_never_overwrites(): void
    {
        [$c, $b, $u, $customer] = $this->collectorWithCustomer();
        $migration = CobrosInitialPortfolioMigration::create(['company_id' => $c->id, 'status' => 'en_progreso']);
        CobrosInitialPortfolioRecord::create([
            'migration_id' => $migration->id,
            'company_id' => $c->id,
            'customer_id' => $customer->id,
            'identification_type' => 'cedula',
            'balance' => '1500.1234',
            'installment' => '100.5000',
            'frequency' => 'quincenal',
            'next_charge_date' => '2026-10-07',
            'declared_behavior' => 'regular',
            'migrated_by_user_id' => $u->id,
            'migrated_at' => now(),
        ]);

        // Sin configuración operativa => backfill perezoso desde 5A/5B al ver la ficha.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))->assertOk();

        $assignment = CobrosAssignment::query()->forCompany($c->id)
            ->where('customer_id', $customer->id)->where('collector_user_id', $u->id)->firstOrFail();
        $this->assertSame('quincenal', $assignment->visit_frequency);
        $this->assertSame('2026-10-07', $assignment->next_visit_date?->toDateString());

        // Configuración existente JAMÁS se sobrescribe (aunque 5A/5B diga otra cosa).
        $assignment->update(['visit_frequency' => 'mensual', 'visit_month_day' => 3, 'next_visit_date' => '2026-11-03']);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))->assertOk();

        $assignment->refresh();
        $this->assertSame('mensual', $assignment->visit_frequency);
        $this->assertSame(3, (int) $assignment->visit_month_day);
        $this->assertSame('2026-11-03', $assignment->next_visit_date?->toDateString());

        // El registro 5A/5B queda intacto (append-only).
        $this->assertSame('quincenal', CobrosInitialPortfolioRecord::query()
            ->where('customer_id', $customer->id)->firstOrFail()->frequency);
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: Customer} */
    private function collectorWithCustomer(): array
    {
        $company = Company::create(['trade_name' => 'Agenda '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'A'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $user->id,
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        AccountReceivable::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'sale_id' => $sale->id, 'issued_at' => today()->subMonth(),
            'due_date' => today($company->timezone)->addDays(7),
            'original_amount' => '1000.0000', 'balance_due' => '1000.0000',
            'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        return [$company->fresh(), $branch, $user, $customer];
    }
}
