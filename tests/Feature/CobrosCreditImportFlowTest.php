<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 12B-2 — flujo HTTP de importación de créditos históricos (portal dueño):
 * plantilla, vista previa sin escritura, confirmación explícita, idempotencia
 * en reimportación, múltiples CxC por cliente y denegación al cobrador.
 */
class CobrosCreditImportFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User, 3: CobrosRoute} */
    private function context(): array
    {
        $this->session(['active_company_id' => 0, 'active_cobros_route_id' => null]);

        $company = Company::create(['trade_name' => 'ImpFlujo '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'F'.random_int(10, 99), 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        $owner = $this->userIn($company, ['cobros.acceder', 'cobros.portal_dueno']);
        $collector = $this->userIn($company, ['cobros.acceder', 'cobros.cobrar']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Flujo '.uniqid(), 'active' => true]);
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

    private function csv(array $rows): UploadedFile
    {
        $header = 'identificacion_tipo,identificacion,nombre,telefono,monto_original,saldo_pendiente,cuota,frecuencia,fecha_primer_vencimiento,referencia_externa,comportamiento,notas,ruta';
        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent('creditos.csv', implode("\n", $lines));
    }

    /** @param  array<string, mixed>  $overrides */
    private function actingOwner(Company $company, User $owner, CobrosRoute $route, array $overrides = []): static
    {
        return $this->actingAs($owner)->withSession(array_merge([
            'active_company_id' => $company->id,
            'active_cobros_route_id' => $route->id,
        ], $overrides));
    }

    public function test_template_preview_and_confirm_flow_creates_independent_receivables(): void
    {
        [$company, $owner, , $route] = $this->context();

        // 1) Plantilla descargable Xlsx.
        $this->actingOwner($company, $owner, $route)
            ->get(route('cobros.owner.credits.import.template'))
            ->assertOk()
            ->assertDownload('plantilla_creditos_historicos.xlsx');

        $id = '2'.random_int(100000000, 999999999);
        $file = $this->csv([
            ['cedula', $id, 'Cliente Flujo', '88888888', '50000', '35000', '3500', 'quincenal', '2026-11-05', 'FL-0001', 'bueno', '', ''],
            ['cedula', $id, 'Cliente Flujo', '88888888', '20000', '12000', '2000', 'mensual', '2026-12-05', 'FL-0002', 'bueno', '', ''],
            ['cedula', '2'.random_int(100000000, 999999999), 'Cliente Malo', '88880000', '100', '0', '100', 'mensual', '2026-12-05', 'FL-BAD', 'bueno', '', ''],
        ]);

        // 2) Vista previa SIN escritura: 2 listas + 1 inválida.
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.preview'), ['credit_file' => $file])
            ->assertOk()
            ->assertSee('Listas: 2')
            ->assertSee('Inválida')
            ->assertSee('FL-0001');
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);

        // 3) Confirmación explícita => registros + CxC independientes por fila.
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()
            ->assertSee('Importadas: 2');
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 2);
        $this->assertDatabaseCount('accounts_receivable', 2);
        $this->assertSame(2, CobrosInitialPortfolioRecord::query()->where('company_id', $company->id)
            ->whereNull('migration_id')->count());
        $this->assertSame($id, CobrosInitialPortfolioRecord::query()
            ->where('external_reference', 'FL-0001')->first()->customer->identification);
        $this->assertSame('35000.0000', (string) \App\Models\AccountReceivable::forCompany($company->id)
            ->where('external_reference', 'FL-0001')->sole()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    public function test_reimporting_the_same_file_is_idempotent_and_keeps_conflicts(): void
    {
        [$company, $owner, , $route] = $this->context();

        $content = implode("\n", [
            'identificacion_tipo,identificacion,nombre,telefono,monto_original,saldo_pendiente,cuota,frecuencia,fecha_primer_vencimiento,referencia_externa,comportamiento,notas,ruta',
            'cedula,2'.random_int(100000000, 999999999).',Cliente Reimport,88881111,30000,15000,1500,quincenal,2026-11-10,FL-RI-1,regular,,',
            'cedula,2'.random_int(100000000, 999999999).',Cliente Conflicto,88882222,30000,15000,1500,quincenal,2026-11-10,FL-RC-1,regular,,',
        ]);
        $file = fn () => UploadedFile::fake()->createWithContent('creditos.csv', $content);

        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.preview'), ['credit_file' => $file()])
            ->assertOk()->assertSee('Listas: 2');
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()->assertSee('Importadas: 2');
        $this->assertDatabaseCount('accounts_receivable', 2);

        // Reimportar el MISMO archivo => todo duplicado, sin nuevos registros.
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.preview'), ['credit_file' => $file()])
            ->assertOk()->assertSee('Duplicadas (no se duplican): 2');
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()->assertSee('Ya existían (omitidas): 2');
        $this->assertDatabaseCount('accounts_receivable', 2);
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 2);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    public function test_collector_cannot_access_credit_import_and_preview_requires_file(): void
    {
        [$company, , $collector, $route] = $this->context();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.credits.import'))->assertForbidden();

        [$company2, $owner2, , $route2] = $this->context();
        $this->actingOwner($company2, $owner2, $route2)
            ->post(route('cobros.owner.credits.import.preview'), [])
            ->assertSessionHasErrors('credit_file');
        unset($route);
    }

    public function test_optional_route_column_authorizes_only_members(): void
    {
        [$company, $owner, , $route] = $this->context();

        // Ruta inexistente en la vista previa queda como fila lista (se valida al confirmar).
        $file = $this->csv([
            ['cedula', '2'.random_int(100000000, 999999999), 'Cliente Ruta', '88883333', '10000', '8000', '800', 'mensual', '2026-11-15', 'FL-RT-1', 'bueno', '', $route->name],
        ]);

        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.preview'), ['credit_file' => $file])
            ->assertOk()->assertSee('Listas: 1');

        // Confirmar: la ruta del archivo (miembro activo) autoriza el ancla de la CxC.
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()->assertSee('Importadas: 1');
        $this->assertSame($route->id, \App\Models\AccountReceivable::forCompany($company->id)
            ->where('external_reference', 'FL-RT-1')->sole()->cobros_route_id);

        // Ruta inexistente => conflicto de fila al confirmar, sin escribir.
        $bad = $this->csv([
            ['cedula', '2'.random_int(100000000, 999999999), 'Cliente Ruta Mal', '88884444', '10000', '8000', '800', 'mensual', '2026-11-15', 'FL-RT-2', 'bueno', '', 'Ruta Inexistente '.uniqid()],
        ]);
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.preview'), ['credit_file' => $bad])
            ->assertOk()->assertSee('Listas: 1');
        $this->actingOwner($company, $owner, $route)
            ->post(route('cobros.owner.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()->assertSee('Inválidas: 1');
        $this->assertNull(\App\Models\AccountReceivable::forCompany($company->id)
            ->where('external_reference', 'FL-RT-2')->first());
    }
}
