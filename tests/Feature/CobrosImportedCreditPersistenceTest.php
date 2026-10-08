<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosInitialPortfolioMigration;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\InitialPortfolioRecordService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 12B — persistencia de créditos históricos importados por el PRESTAMISTA:
 * - Importa SIN migración 5A/5B abierta (solo con cobros.portal_dueno).
 * - Cobrador sin autorización => 403; empresa activa debe coincidir.
 * - Varios créditos por cliente => UNA CxC independiente por referencia.
 * - Idempotencia por (empresa, external_reference); datos distintos => conflicto.
 * - Conserva monto original, saldo, cuota, frecuencia y vencimiento.
 * - Sin pagos/recibos ficticios; sin tocar cobros_credits; 5B intacto.
 */
class CobrosImportedCreditPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User, 3: CobrosRoute} */
    private function context(): array
    {
        // El guard de tenant exige que la empresa activa en sesión coincida al
        // crear datos: al abrir una segunda empresa se limpia la sesión previa.
        $this->session(['active_company_id' => 0, 'active_cobros_route_id' => null]);

        $company = Company::create(['trade_name' => 'ImpCred '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'I'.random_int(10, 99), 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        $owner = $this->userIn($company, ['cobros.portal_dueno']);
        $collector = $this->userIn($company, ['cobros.acceder', 'cobros.cobrar']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Import '.uniqid(), 'active' => true]);
        $route->users()->attach($owner->id, ['active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

        return [$company, $owner, $collector, $route];
    }

    private function userIn(Company $company, array $permissionNames): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissionNames as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function sessionFor(Company $company, CobrosRoute $route): void
    {
        $this->session(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'identification_type' => 'cedula',
            'identification' => '2'.random_int(100000000, 999999999),
            'name' => 'Cliente Importado '.uniqid(),
            'phone' => '8888 8888',
            'original_amount' => '50000',
            'balance' => '35000',
            'installment' => '3500',
            'frequency' => 'quincenal',
            'next_charge_date' => '2026-11-05',
            'declared_behavior' => 'bueno',
            'external_reference' => 'EXT-'.strtoupper(substr(uniqid(), -8)),
        ], $overrides);
    }

    /** @param  array<string, mixed>  $data */
    private function import(Company $company, User $actor, array $data): CobrosInitialPortfolioRecord
    {
        return app(InitialPortfolioRecordService::class)->importCredit($company, $actor, $data);
    }

    public function test_owner_imports_without_any_open_5a_5b_migration(): void
    {
        [$company, $owner, , $route] = $this->context();
        $this->sessionFor($company, $route);

        $this->assertSame(0, CobrosInitialPortfolioMigration::count());

        $record = $this->import($company, $owner, $this->payload());

        $this->assertSame(0, CobrosInitialPortfolioMigration::count());
        $this->assertNull($record->migration_id);
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseHas('cobros_initial_portfolio_records', [
            'company_id' => $company->id,
            'id' => $record->id,
            'migration_id' => null,
        ]);
    }

    public function test_collector_without_owner_permission_cannot_import(): void
    {
        [$company, , $collector, $route] = $this->context();
        $this->sessionFor($company, $route);

        try {
            $this->import($company, $collector, $this->payload());
            $this->fail('Se esperaba 403 para el cobrador sin portal_dueno.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    public function test_import_requires_the_active_company_session(): void
    {
        [$companyA, $ownerA, , $routeA] = $this->context();
        [$companyB, , , $routeB] = $this->context();

        // Sesión apuntando a A pero se intenta importar en B => 403.
        $this->sessionFor($companyA, $routeA);

        try {
            $this->import($companyB, $ownerA, $this->payload());
            $this->fail('Se esperaba 403 por empresa activa distinta.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);
        unset($routeB);
    }

    public function test_multiple_imported_credits_per_customer_get_independent_receivables(): void
    {
        [$company, $owner, , $route] = $this->context();
        $this->sessionFor($company, $route);

        $first = $this->payload(['balance' => '35000', 'original_amount' => '50000']);
        $second = $this->payload([
            'identification' => $first['identification'],
            'name' => $first['name'],
            'balance' => '12000',
            'original_amount' => '20000',
            'installment' => '2000',
            'frequency' => 'mensual',
            'next_charge_date' => '2026-12-05',
        ]);

        $recordA = $this->import($company, $owner, $first);
        $recordB = $this->import($company, $owner, $second);

        $this->assertSame($recordA->customer_id, $recordB->customer_id);
        $this->assertNotSame($recordA->external_reference, $recordB->external_reference);

        $this->assertDatabaseCount('cobros_initial_portfolio_records', 2);
        $this->assertDatabaseCount('accounts_receivable', 2);

        $accounts = AccountReceivable::forCompany($company->id)->orderBy('id')->get();
        $this->assertCount(2, $accounts);
        $this->assertSame(['35000.0000', '12000.0000'], $accounts->pluck('balance_due')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['50000.0000', '20000.0000'], $accounts->pluck('original_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame([$first['external_reference'], $second['external_reference']], $accounts->pluck('external_reference')->all());

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_reimport_same_reference_and_same_data_is_idempotent(): void
    {
        [$company, $owner, , $route] = $this->context();
        $this->sessionFor($company, $route);

        $payload = $this->payload();
        $original = $this->import($company, $owner, $payload);
        $retry = $this->import($company, $owner, $payload);

        $this->assertSame($original->id, $retry->id);
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 1);
        $this->assertDatabaseCount('accounts_receivable', 1);
        // El prestamista sin derecho de cobrar NO genera cartera asignada.
        $this->assertDatabaseCount('cobros_assignments', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    public function test_assignment_is_reused_for_collector_rights_and_never_steals_portfolio(): void
    {
        [$company, $owner, $collector, $route] = $this->context();
        $this->sessionFor($company, $route);

        // El prestamista además con derecho de cobrar puede cargar cartera.
        $owner->roleInCompany($company)->permissions()->attach(
            Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'cobros.cobrar', 'module' => 'CobrOS', 'is_active' => true])
        );

        // Cliente NUEVO importado => asignado por el servicio existente.
        $fresh = $this->payload();
        $record = $this->import($company, $owner, $fresh);
        $this->assertDatabaseHas('cobros_assignments', [
            'company_id' => $company->id,
            'customer_id' => $record->customer_id,
            'collector_user_id' => $owner->id,
            'cobros_route_id' => $route->id,
        ]);

        // Cliente YA asignado a OTRO cobrador: importar su crédito no lo roba.
        $taken = $this->payload();
        $takenRecord = $this->import($company, $owner, $taken);
        \App\Models\CobrosAssignment::where('customer_id', $takenRecord->customer_id)->update(['collector_user_id' => $collector->id]);

        $this->import($company, $owner, array_merge($taken, ['external_reference' => 'EXT-'.strtoupper(substr(uniqid(), -8)), 'balance' => '10000']));
        // Reimport con otra referencia para el MISMO cliente no reasigna.
        $this->assertSame(
            $collector->id,
            (int) \App\Models\CobrosAssignment::where('customer_id', $takenRecord->customer_id)->first()->collector_user_id
        );
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 3);
        $this->assertDatabaseCount('accounts_receivable', 3);
    }

    public function test_reimport_same_reference_with_different_data_conflicts_without_overwriting(): void
    {
        [$company, $owner, , $route] = $this->context();
        $this->sessionFor($company, $route);

        $payload = $this->payload();
        $original = $this->import($company, $owner, $payload);

        try {
            $this->import($company, $owner, array_merge($payload, ['balance' => '36000']));
            $this->fail('Se esperaba conflicto por referencia reutilizada con datos distintos.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('external_reference', $e->errors());
        }

        $this->assertDatabaseCount('cobros_initial_portfolio_records', 1);
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertSame('35000.0000', (string) $original->fresh()->balance);
        $this->assertSame('35000.0000', (string) AccountReceivable::sole()->balance_due);
    }

    public function test_same_external_reference_and_identification_across_companies_are_isolated(): void
    {
        [$companyA, $ownerA, , $routeA] = $this->context();
        [$companyB, $ownerB, , $routeB] = $this->context();

        $shared = 'EXT-SHARED-'.strtoupper(substr(uniqid(), -8));
        $payloadA = $this->payload(['external_reference' => $shared]);
        $payloadB = $this->payload(['external_reference' => $shared]);

        $this->sessionFor($companyA, $routeA);
        $this->import($companyA, $ownerA, $payloadA);

        $this->sessionFor($companyB, $routeB);
        $this->import($companyB, $ownerB, $payloadB);

        $this->assertDatabaseCount('cobros_initial_portfolio_records', 2);
        $this->assertDatabaseCount('accounts_receivable', 2);

        $this->sessionFor($companyA, $routeA);
        $this->assertSame(1, AccountReceivable::forCompany($companyA->id)->where('external_reference', $shared)->count());

        // Identificación registrada en OTRO tenant no se importa ni se toca.
        try {
            $this->import($companyA, $ownerA, array_merge($payloadA, ['external_reference' => 'EXT-OTHER-'.uniqid(), 'identification' => $payloadB['identification']]));
            $this->fail('Se esperaba rechazo de identificación ajena al tenant.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identification', $e->errors());
        }
    }

    public function test_financial_fields_are_preserved_for_partially_paid_credit(): void
    {
        [$company, $owner, , $route] = $this->context();
        $this->sessionFor($company, $route);

        $payload = $this->payload([
            'original_amount' => '50000.50',
            'balance' => '35000.25',
            'installment' => '3500.10',
            'frequency' => 'semanal',
            'next_charge_date' => '2026-10-20',
        ]);

        $record = $this->import($company, $owner, $payload);

        $this->assertSame('50000.5000', (string) $record->original_amount);
        $this->assertSame('35000.2500', (string) $record->balance);
        $this->assertSame('3500.1000', (string) $record->installment);
        $this->assertSame('semanal', $record->frequency);
        $this->assertSame('2026-10-20', $record->next_charge_date->toDateString());
        $this->assertSame($payload['external_reference'], $record->external_reference);

        $account = AccountReceivable::sole();
        $this->assertSame('50000.5000', (string) $account->original_amount);
        $this->assertSame('35000.2500', (string) $account->balance_due);
        $this->assertSame('partial', $account->status);
        $this->assertSame('2026-10-20', $account->due_date->toDateString());
        $this->assertSame($payload['external_reference'], $account->external_reference);
        $this->assertSame($route->id, $account->cobros_route_id);
        $this->assertNull($account->sale_id);

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('cobros_credits', 0);
    }

    public function test_import_validation_rejects_missing_or_inconsistent_rows(): void
    {
        [$company, $owner, , $route] = $this->context();
        $this->sessionFor($company, $route);

        try {
            $this->import($company, $owner, $this->payload(['external_reference' => '']));
            $this->fail('Se esperaba rechazo por referencia vacía.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('external_reference', $e->errors());
        }

        try {
            $this->import($company, $owner, $this->payload(['original_amount' => '1000', 'balance' => '35000']));
            $this->fail('Se esperaba rechazo por monto original menor que el saldo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('original_amount', $e->errors());
        }

        try {
            $this->import($company, $owner, $this->payload(['balance' => '0']));
            $this->fail('Se esperaba rechazo por saldo en cero.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('balance', $e->errors());
        }

        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }
}
