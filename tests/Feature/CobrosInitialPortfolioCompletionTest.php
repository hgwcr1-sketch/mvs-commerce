<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosAssignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bugs reproducidos manualmente:
 * A) confirmación de cierre exigía "FINALIZAR MIGRACIÓN" con tilde exacta.
 * B) clientes cargados en 5B no aparecían en "Mi cartera" porque 5B creaba
 *    Customer/registro pero NUNCA cobros_assignments (autoridad del portal).
 *
 * Regresiones: confirmación normalizada finaliza; el cliente migrado queda
 * ASIGNADO al cobrador migrador; aparece en CollectorPortfolio tras finalizar;
 * otro cobrador no lo ve; CxC/saldo/datos migrados intactos.
 */
class CobrosInitialPortfolioCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_without_accents_case_and_outer_spaces_finalizes(): void
    {
        [$c, $a] = $this->context();
        $this->open($c, $a);

        // Equivalente normalizado: sin tilde, minúsculas y espacios exteriores.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => '  finalizar migracion  '])
            ->assertRedirect(route('cobros.collector.index'));

        $this->assertSame(
            \App\Models\CobrosInitialPortfolioMigration::STATUS_FINALIZED,
            \App\Models\CobrosInitialPortfolioMigration::sole()->status
        );

        // Confirmación débil SIGUE bloqueada (fuerte, no eliminada).
        [$c2, $b] = $this->context();
        $this->open($c2, $b);
        $this->actingAs($b)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'finalizar'])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(
            \App\Models\CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS,
            \App\Models\CobrosInitialPortfolioMigration::where('company_id', $c2->id)->sole()->status
        );
    }

    public function test_migrated_client_is_assigned_to_migrator_and_appears_in_portfolio_after_finalize(): void
    {
        [$c, $a] = $this->context();
        $this->open($c, $a);
        $record = $this->addClient($c, $a);

        // 1) Cliente migrado => asignación activa al MISMO cobrador migrador.
        $this->assertDatabaseHas('cobros_assignments', [
            'company_id' => $c->id,
            'customer_id' => $record->customer_id,
            'collector_user_id' => $a->id,
        ]);

        // 2) Tras finalizar, aparece inmediatamente en "Mi cartera".
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])
            ->assertRedirect(route('cobros.collector.index'));
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertSee('Cliente Migrado Nuevo');

        // 3) OTRO cobrador de la misma empresa no lo ve.
        $other = $this->userIn($c, ['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($other)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertDontSee('Cliente Migrado Nuevo');
        $this->assertSame(1, CobrosAssignment::count());

        // 4) CxC / saldo / datos migrados permanecen iguales.
        $this->assertSame(0, AccountReceivable::count());
        $fresh = CobrosInitialPortfolioRecord::sole();
        $this->assertSame('1500.1234', (string) $fresh->balance);
        $this->assertSame('100.5000', (string) $fresh->installment);
        $this->assertSame('123456789', $fresh->customer->identification);
    }

    public function test_assign_is_idempotent_when_client_already_assigned_to_migrator(): void
    {
        [$c, $a] = $this->context();
        $this->open($c, $a);

        // Cliente preexistente YA asignado al migrador: 5B no duplica asignación.
        $pre = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'identification' => '111222333', 'name' => 'Preexistente Asignado', 'is_active' => true]);
        CobrosAssignment::create(['company_id' => $c->id, 'customer_id' => $pre->id, 'collector_user_id' => $a->id, 'assigned_by' => $a->id, 'assigned_at' => now()]);

        $this->addClient($c, $a, ['identification' => '111222333']);

        $this->assertSame(1, CobrosAssignment::count());
        $this->assertSame(1, CobrosInitialPortfolioRecord::count());
    }

    // ------------------------------------------------------------------
    // Helpers

    private function addClient(Company $c, User $collector, array $overrides = []): CobrosInitialPortfolioRecord
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), array_merge([
                'identification_type' => 'cedula',
                'identification' => '123456789',
                'name' => 'Cliente Migrado Nuevo',
                'phone' => '8888 8888',
                'balance' => '1500.1234',
                'installment' => '100.50',
                'frequency' => 'quincenal',
                'next_charge_date' => '2026-10-15',
                'declared_behavior' => 'bueno',
                'action' => 'volver',
            ], $overrides))
            ->assertRedirect(route('cobros.initial-portfolio'));

        return CobrosInitialPortfolioRecord::latest('id')->firstOrFail();
    }

    private function open(Company $c, User $collector): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect(route('cobros.initial-portfolio.client'));
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'CR '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'C'.random_int(1, 99), 'is_active' => true]);
        $user = $this->userIn($company, $permissions);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        return [$company->fresh(), $user];
    }

    private function userIn(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Perfil '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($company->branches()->first()->id);

        return $user;
    }
}
