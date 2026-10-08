<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosMigrationNotice;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteUser;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerImportRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 13-1A — importación DIRECTA del cobrador sobre sus rutas (sin permiso de
 * dueño ni aprobación previa), con aviso «Migración pendiente de revisar»
 * para el prestamista. Reutiliza los motores 12B-1/12B-2.
 */
class CobrosCollectorImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_import_routes_require_collector_permission(): void
    {
        [$company, , $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        $session = ['active_company_id' => $company->id];

        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.collector.customers.import'))->assertForbidden();
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.collector.credits.import'))->assertForbidden();
        $this->actingAs($owner)->withSession($session)
            ->post(route('cobros.collector.credits.import.preview'))->assertForbidden();
    }

    public function test_collector_imports_credits_of_own_route_immediately_and_owner_sees_notice(): void
    {
        [$company, , $collector, , $route, $owner] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        $session = ['active_company_id' => $company->id];

        $content = implode("\n", [
            'identificacion_tipo,identificacion,nombre,telefono,monto_original,saldo_pendiente,cuota,frecuencia,fecha_primer_vencimiento,referencia_externa,comportamiento,notas,ruta',
            'cedula,2'.random_int(100000000, 999999999).',Cliente Cobrador,88881111,30000,15000,1500,quincenal,2026-11-10,CI-1,bueno,,'.$route->name,
        ]);
        $file = UploadedFile::fake()->createWithContent('creditos.csv', $content);

        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.credits.import.preview'), ['credit_file' => $file])
            ->assertOk()->assertSee('Listas: 1');

        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()->assertSee('Importadas: 1');

        $this->assertSame(1, CobrosInitialPortfolioRecord::where('company_id', $company->id)->whereNull('migration_id')->count());
        $this->assertSame(1, AccountReceivable::forCompany($company->id)->where('external_reference', 'CI-1')->count());

        $notice = CobrosMigrationNotice::where('company_id', $company->id)->sole();
        $this->assertSame($collector->id, $notice->collector_user_id);
        $this->assertSame($route->id, $notice->cobros_route_id);
        $this->assertSame('credit_import', $notice->source);
        $this->assertSame('pending', $notice->status);

        // Idempotencia: reimportar no duplica registros ni CxC.
        $again = UploadedFile::fake()->createWithContent('creditos.csv', $content);
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.credits.import.preview'), ['credit_file' => $again])
            ->assertOk()->assertSee('Duplicadas (no se duplican): 1');
        $this->assertSame(1, CobrosInitialPortfolioRecord::where('company_id', $company->id)->count());

        // El prestamista revisa y marca el aviso.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.migrations.notices'))
            ->assertOk()->assertSee('Migración pendiente de revisar')
            ->assertSee($route->name);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.migrations.notices.review', $notice->id))
            ->assertRedirect(route('cobros.owner.migrations.notices'));
        $this->assertSame('reviewed', $notice->fresh()->status);
    }

    public function test_credit_import_rejects_missing_or_foreign_route_but_keeps_valid_rows(): void
    {
        [$company, , $collector, , $route] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        $session = ['active_company_id' => $company->id];
        $foreignRoute = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Ajena', 'active' => true]);

        $content = implode("\n", [
            'identificacion_tipo,identificacion,nombre,telefono,monto_original,saldo_pendiente,cuota,frecuencia,fecha_primer_vencimiento,referencia_externa,comportamiento,notas,ruta',
            'cedula,3'.random_int(100000000, 999999999).',Sin Ruta,88881111,10000,5000,500,mensual,2026-11-10,CI-NR,bueno,,',
            'cedula,3'.random_int(100000000, 999999999).',Ruta Ajena,88882222,10000,5000,500,mensual,2026-11-10,CI-RA,bueno,,'.$foreignRoute->name,
            'cedula,3'.random_int(100000000, 999999999).',Buena,88883333,10000,5000,500,mensual,2026-11-10,CI-OK,bueno,,'.$route->name,
        ]);
        $file = UploadedFile::fake()->createWithContent('creditos.csv', $content);

        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.credits.import.preview'), ['credit_file' => $file])
            ->assertOk()->assertSee('Listas: 1');
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()->assertSee('Importadas: 1')->assertSee('Inválidas: 2');

        $this->assertSame(1, AccountReceivable::forCompany($company->id)->count());
        $this->assertSame(0, AccountReceivable::forCompany($company->id)->where('external_reference', 'CI-NR')->count());
        $this->assertSame(0, AccountReceivable::forCompany($company->id)->where('external_reference', 'CI-RA')->count());
    }

    public function test_collector_imports_customers_of_own_route_with_notice_and_isolation(): void
    {
        [$company, , $collector, , $route] = $this->context(['cobros.acceder', 'cobros.cobrar', 'clientes.crear']);
        $session = ['active_company_id' => $company->id];
        $foreignRoute = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Ajena', 'active' => true]);

        $csv = implode("\n", [
            'tipo_identificacion,identificacion,nombre,telefono,direccion,ruta,latitud,longitud',
            '01,4'.random_int(100000000, 999999999).',María Núñez,88881111,Alajuela,'.$route->name.',9.93,-84.07',
            '01,4'.random_int(100000000, 999999999).',Sin Ruta,88882222,,,',
            '01,4'.random_int(100000000, 999999999).',Ruta Ajena,88883333,,'.$foreignRoute->name.',,',
        ]);
        $file = UploadedFile::fake()->createWithContent('clientes.csv', $csv);

        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.customers.import.preview'), ['customer_file' => $file])
            ->assertOk()->assertSee('María Núñez');

        $run = CustomerImportRun::where('company_id', $company->id)->sole();
        $this->assertSame('cobros_ruta', $run->variant);
        $this->assertSame(1, $run->new_count);
        $this->assertSame(2, $run->error_count);

        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.customers.import.confirm'), ['run_id' => $run->id])
            ->assertRedirect(route('cobros.collector.customers.import.status', $run->id));

        $customer = Customer::where('company_id', $company->id)->where('identification_type', '01')->firstOrFail();
        $this->assertSame('María Núñez', $customer->name);
        $this->assertSame(1, Customer::where('company_id', $company->id)->count());

        $notice = CobrosMigrationNotice::where('company_id', $company->id)->sole();
        $this->assertSame('customer_import', $notice->source);
        $this->assertSame($run->id, $notice->source_id);
        $this->assertSame($route->id, $notice->cobros_route_id);
        $this->assertSame('pending', $notice->status);

        // Aislamiento: el aviso no es visible para otra empresa.
        // flushSession evita que el saving hook de CobrosRoute compare la empresa
        // previa (active_company_id aún presente) al crear la ruta de la nueva.
        $this->flushSession();
        [$other, , , , , $otherOwner] = $this->context([]);
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $other->id])
            ->get(route('cobros.owner.migrations.notices'))
            ->assertOk()->assertDontSee('María Núñez')->assertDontSee($route->name);
    }

    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'ImportC '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica',
            'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'customers']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte '.uniqid(), 'active' => true]);
        CobrosRouteUser::create([
            'company_id' => $company->id, 'cobros_route_id' => $route->id,
            'user_id' => $user->id, 'active' => true,
        ]);

        // Prestamista de la MISMA empresa (para la bandeja de avisos).
        $ownerRole = Role::create(['company_id' => $company->id, 'name' => 'Dueño '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.portal_dueno'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $ownerRole->permissions()->syncWithoutDetaching($permission);
        }
        $owner = User::factory()->create(['is_active' => true]);
        $owner->companies()->attach($company->id, ['role_id' => $ownerRole->id]);

        return [$company->fresh(), $branch, $user, $role, $route, $owner];
    }
}
