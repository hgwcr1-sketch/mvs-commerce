<?php

namespace Tests\Feature;

use App\Models\CobrosInitialPortfolioCorrectionRequest;
use App\Models\CobrosInitialPortfolioEvent;
use App\Models\CobrosInitialPortfolioMigration;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosInitialPortfolioSnapshot;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arquitectura aprobada: cartera inicial POR COBRADOR.
 * Cubre: independencia por cobrador, primer ingreso/finalizar→portal,
 * foto de apertura, revisión posterior sin gate, congelamiento + correcciones,
 * reapertura solo prestamista (solo agrega) y múltiples cierres conservados.
 */
class CobrosInitialPortfolioPerCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_are_independent_per_collector(): void
    {
        [$c, $a] = $this->collector();
        $b = $this->userIn($c, ['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->actingAs($b)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio'))->assertOk()->assertSee('Disponible');

        $this->assertSame(1, CobrosInitialPortfolioMigration::count());
        $this->assertSame($a->id, CobrosInitialPortfolioMigration::sole()->collector_user_id);

        // B no puede finalizar lo que no tiene y no toca el ciclo de A.
        $this->actingAs($b)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])
            ->assertForbidden();
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS, CobrosInitialPortfolioMigration::sole()->status);
    }

    public function test_first_entry_redirects_and_finalize_opens_portal_with_snapshot(): void
    {
        [$c, $a] = $this->collector();

        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.initial-portfolio'));

        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect(route('cobros.initial-portfolio.client'));

        // Mientras no finalice, /cobros es su pantalla principal.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.initial-portfolio'));

        $this->addClient($c, $a, ['balance' => '1500.0000', 'installment' => '100.0000']);

        // Finalizar: portal inmediato, SIN aprobación del prestamista.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])
            ->assertRedirect(route('cobros.collector.index'));

        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))->assertOk();

        // Foto de apertura inmutable con totales deterministas + evento de cierre.
        $snapshot = CobrosInitialPortfolioSnapshot::sole();
        $this->assertSame($a->id, $snapshot->collector_user_id);
        $this->assertSame(1, $snapshot->payload['clients_count']);
        $this->assertSame('1500.0000', $snapshot->payload['total_balance']);
        $this->assertSame('100.0000', $snapshot->payload['total_installment']);
        $this->assertNotNull($snapshot->captured_at);

        $this->assertSame(
            [CobrosInitialPortfolioEvent::TYPE_OPEN, CobrosInitialPortfolioEvent::TYPE_CLOSE],
            CobrosInitialPortfolioEvent::orderBy('id')->pluck('type')->all()
        );
    }

    public function test_prestamista_review_is_administrative_and_does_not_block_work(): void
    {
        [$c, $a] = $this->collector();
        $owner = $this->prestamista($c);
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->addClient($c, $a);
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();

        // Resumen del prestamista: nombre, clientes, saldo, fecha y estado.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio.summary'))
            ->assertOk()
            ->assertSee($a->name)
            ->assertSee('Pendiente de revisión');

        $record = CobrosInitialPortfolioRecord::sole();
        $before = $record->getRawOriginal();

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.review'), ['collector_user_id' => $a->id])
            ->assertRedirect(route('cobros.initial-portfolio.summary'));

        $migration = CobrosInitialPortfolioMigration::sole();
        $this->assertSame($owner->id, $migration->reviewed_by_user_id);
        $this->assertNotNull($migration->reviewed_at);
        $this->assertContains(CobrosInitialPortfolioEvent::TYPE_REVIEW, CobrosInitialPortfolioEvent::pluck('type')->all());

        // La revisión NO altera datos ni bloquea al cobrador.
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio'))->assertOk();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio.summary'))->assertOk()->assertSee('Revisada');
    }

    public function test_correction_workflow_keeps_original_until_prestamista_approval(): void
    {
        [$c, $a] = $this->collector();
        $owner = $this->prestamista($c);
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->addClient($c, $a, ['balance' => '1500.0000']);
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();

        $record = CobrosInitialPortfolioRecord::sole();

        // Congelado: no existe edición directa del dato inicial.
        $patch = $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->patch('/cobros/mi-cartera-inicial/registros/'.$record->id, ['balance' => '9999']);
        $this->assertContains($patch->getStatusCode(), [404, 405], 'No existe edición directa de datos congelados.');

        // Solicitud: NO modifica el valor.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.correction.request', $record), [
                'field' => 'balance', 'requested_value' => '1450.0000', 'reason' => 'Digitación inicial errónea',
            ])->assertRedirect(route('cobros.initial-portfolio'));

        $this->assertSame('1500.0000', (string) $record->fresh()->balance);
        $correction = CobrosInitialPortfolioCorrectionRequest::sole();
        $this->assertSame('1500.0000', $correction->original_value);
        $this->assertSame('pendiente', $correction->status);

        // El cobrador no resuelve su propia solicitud.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.correction.resolve', $correction), ['decision' => 'aprobar'])
            ->assertForbidden();

        // Aprobación: aplica el nuevo valor conservando el original + evento.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.correction.resolve', $correction), ['decision' => 'aprobar'])
            ->assertRedirect(route('cobros.initial-portfolio.summary'));

        $correction = $correction->fresh();
        $this->assertSame('aprobada', $correction->status);
        $this->assertSame('1500.0000', $correction->original_value);
        $this->assertSame($owner->id, $correction->reviewed_by_user_id);
        $this->assertNotNull($correction->applied_at);
        $this->assertSame('1450.0000', (string) $record->fresh()->balance);

        $event = CobrosInitialPortfolioEvent::where('type', CobrosInitialPortfolioEvent::TYPE_CORRECTION)->sole();
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertStringContainsString('1500.0000', $event->reason);

        // Resuelta: no se puede aprobar dos veces.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.correction.resolve', $correction), ['decision' => 'aprobar'])
            ->assertStatus(409);
    }

    public function test_reopen_is_prestamista_only_adds_clients_and_keeps_history(): void
    {
        [$c, $a] = $this->collector();
        $owner = $this->prestamista($c);
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->addClient($c, $a, ['balance' => '800.0000']);
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();
        $original = CobrosInitialPortfolioRecord::sole();

        // El cobrador jamás se auto-reabre.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.reopen.owner'), [
                'collector_user_id' => $a->id, 'reason' => 'Quiero agregar un cliente',
            ])->assertForbidden();

        // Prestamista reabre con motivo obligatorio.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.reopen.owner'), ['collector_user_id' => $a->id, 'reason' => 'ab'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.reopen.owner'), [
                'collector_user_id' => $a->id, 'reason' => 'Cliente olvidado pendiente',
            ])->assertRedirect(route('cobros.initial-portfolio.summary'));

        $migration = CobrosInitialPortfolioMigration::sole();
        $this->assertSame(CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS, $migration->status);
        $this->assertSame($owner->id, $migration->reopened_by_user_id);
        $this->assertSame(1, $migration->reopen_count);

        // Reapertura autorizada: ese cobrador VUELVE al modo migración.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.initial-portfolio'));
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.initial-portfolio'))
            ->assertOk()->assertSee('En progreso')->assertDontSee('cobros-more-panel', false);

        // Solo AGREGA: el registro congelado sigue intacto y se carga otro cliente.
        $this->assertSame('800.0000', (string) $original->fresh()->balance);
        $this->addClient($c, $a, ['balance' => '300.0000']);
        $this->assertSame(2, CobrosInitialPortfolioRecord::count());

        // Segundo cierre: conserva ambas fotos y todos los eventos + portal inmediato.
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
        $this->assertSame(2, CobrosInitialPortfolioSnapshot::count());
        $types = CobrosInitialPortfolioEvent::orderBy('id')->pluck('type')->all();
        $this->assertSame(
            [CobrosInitialPortfolioEvent::TYPE_OPEN, CobrosInitialPortfolioEvent::TYPE_CLOSE, CobrosInitialPortfolioEvent::TYPE_REOPEN, CobrosInitialPortfolioEvent::TYPE_CLOSE],
            $types
        );
        $this->assertSame('1100.0000', CobrosInitialPortfolioSnapshot::latest('id')->first()->payload['total_balance']);
    }

    public function test_idor_on_correction_request_is_blocked_without_modifying_anything(): void
    {
        [$c, $a] = $this->collector();
        $b = $this->userIn($c, ['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.start'))->assertRedirect();
        $this->addClient($c, $a, ['balance' => '1500.0000']);
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.finalize'), ['confirm' => 'FINALIZAR MIGRACIÓN'])->assertRedirect();

        $record = CobrosInitialPortfolioRecord::sole();
        $this->actingAs($a)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.correction.request', $record), [
                'field' => 'balance', 'requested_value' => '1450.0000', 'reason' => 'Digitación inicial errónea',
            ])->assertRedirect(route('cobros.initial-portfolio'));

        $correction = CobrosInitialPortfolioCorrectionRequest::sole();
        $correctionBefore = $correction->getRawOriginal();
        $recordBefore = $record->fresh()->getRawOriginal();
        $financialBefore = [
            'accounts' => \App\Models\AccountReceivable::count(),
            'payments' => \App\Models\AccountReceivablePayment::count(),
            'receipts' => \App\Models\CobrosReceipt::count(),
        ];

        // Cobrador B (misma empresa) usando el ID de la solicitud de A: 403/404.
        $resolve = $this->actingAs($b)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.correction.resolve', $correction), ['decision' => 'aprobar']);
        $this->assertContains($resolve->getStatusCode(), [403, 404]);

        $request = $this->actingAs($b)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.correction.request', $record), [
                'field' => 'balance', 'requested_value' => '1.0000', 'reason' => 'Intento ajeno',
            ]);
        $this->assertContains($request->getStatusCode(), [403, 404]);

        // Cross-company: prestamista de OTRA empresa golpea el endpoint con el ID ajeno => 404.
        [$c2] = $this->collector();
        $otherOwner = $this->prestamista($c2);
        $cross = $this->actingAs($otherOwner)->withSession(['active_company_id' => $c2->id])
            ->post(route('cobros.initial-portfolio.correction.resolve', $correction), ['decision' => 'rechazar']);
        $this->assertContains($cross->getStatusCode(), [403, 404]);

        // Absolutamente nada cambió: original, solicitado, estado, actor y financieros.
        $this->assertSame($correctionBefore, $correction->fresh()->getRawOriginal());
        $this->assertSame('pendiente', $correction->fresh()->status);
        $this->assertSame('1500.0000', $correction->fresh()->original_value);
        $this->assertSame('1450.0000', $correction->fresh()->requested_value);
        $this->assertSame($a->id, $correction->fresh()->requested_by_user_id);
        $this->assertSame($recordBefore, $record->fresh()->getRawOriginal());
        $this->assertSame('1500.0000', (string) $record->fresh()->balance);
        $this->assertSame($financialBefore, [
            'accounts' => \App\Models\AccountReceivable::count(),
            'payments' => \App\Models\AccountReceivablePayment::count(),
            'receipts' => \App\Models\CobrosReceipt::count(),
        ]);
        $this->assertSame(1, CobrosInitialPortfolioCorrectionRequest::count());
        $this->assertNotContains(CobrosInitialPortfolioEvent::TYPE_CORRECTION, CobrosInitialPortfolioEvent::pluck('type')->all());
    }

    // ------------------------------------------------------------------
    // Helpers

    private function addClient(Company $c, User $collector, array $overrides = []): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.initial-portfolio.client.store'), array_merge([
                'identification_type' => 'cedula',
                'identification' => '1'.random_int(100000000, 999999999),
                'name' => 'Cliente '.uniqid(),
                'phone' => '8888 8888',
                'balance' => '500.0000',
                'installment' => '50.0000',
                'frequency' => 'semanal',
                'next_charge_date' => '2026-10-10',
                'declared_behavior' => 'bueno',
                'action' => 'volver',
            ], $overrides))
            ->assertRedirect(route('cobros.initial-portfolio'));
    }

    private function collector(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = $this->company();
        $user = $this->userIn($company, $permissions);
        // Cobrador NUEVO incorporado al flujo: enrolamiento persistido (fila 'disponible').
        app(\App\Services\Cobros\InitialPortfolioMigrationService::class)->enroll($company, $user);

        return [$company, $user];
    }

    private function userIn(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($company->branches()->first()->id);

        return $user;
    }

    private function prestamista(Company $company): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Prestamista '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.portal_dueno', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($company->branches()->first()->id);

        return $user;
    }

    private function company(): Company
    {
        $company = Company::create(['trade_name' => 'Prestamista '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'P'.random_int(1, 99), 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        return $company->fresh();
    }
}
