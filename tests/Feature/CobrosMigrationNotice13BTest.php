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
 * 13-1B — bandeja del prestamista: filtros por cobrador/ruta/estado, cotejo
 * numérico con el sistema anterior, «Marcar como revisada» idempotente con
 * trazabilidad y acceso a mecanismos de corrección existentes. Sin bloquear
 * operaciones; reutiliza la infraestructura 13-1A (cobros_migration_notices).
 */
class CobrosMigrationNotice13BTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_filters_by_collector_route_and_status(): void
    {
        [$company, , $collectorA, , $routeA, $owner, $collectorB, $routeB] = $this->context();
        $session = ['active_company_id' => $company->id];

        $this->seedNotice($company, $routeA, $collectorA, CobrosMigrationNotice::STATUS_PENDING, 'A-pend');
        $this->seedNotice($company, $routeB, $collectorB, CobrosMigrationNotice::STATUS_REVIEWED, 'B-rev');
        $this->seedNotice($company, $routeA, $collectorB, CobrosMigrationNotice::STATUS_PENDING, 'AB-pend');

        // Filtro por cobrador A => solo su aviso.
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.migrations.notices', ['collector' => $collectorA->id]))
            ->assertOk()->assertSee('A-pend')->assertDontSee('B-rev')->assertDontSee('AB-pend');

        // Filtro por ruta B => solo el aviso de esa ruta.
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.migrations.notices', ['route' => $routeB->id]))
            ->assertOk()->assertSee('B-rev')->assertDontSee('A-pend');

        // Filtro por estado revisada => solo B.
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.migrations.notices', ['status' => 'reviewed']))
            ->assertOk()->assertSee('B-rev')->assertDontSee('A-pend')->assertDontSee('AB-pend');

        // Filtro por estado pendiente => A y AB.
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.migrations.notices', ['status' => 'pending']))
            ->assertOk()->assertSee('A-pend')->assertSee('AB-pend')->assertDontSee('B-rev');

        // Estado inválido se ignora (muestra todo).
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.migrations.notices', ['status' => 'otro']))
            ->assertOk()->assertSee('A-pend')->assertSee('B-rev')->assertSee('AB-pend');
    }

    public function test_review_is_idempotent_and_records_user_and_date(): void
    {
        [$company, , $collector, , $route, $owner, , , $secondOwner] = $this->context();
        $session = ['active_company_id' => $company->id];
        $notice = $this->seedNotice($company, $route, $collector, CobrosMigrationNotice::STATUS_PENDING, 'rev-1');

        $this->actingAs($owner)->withSession($session)
            ->post(route('cobros.owner.migrations.notices.review', $notice->id))
            ->assertRedirect(route('cobros.owner.migrations.notices'));

        $fresh = $notice->fresh();
        $this->assertSame(CobrosMigrationNotice::STATUS_REVIEWED, $fresh->status);
        $this->assertSame($owner->id, (int) $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
        $firstReviewedAt = $fresh->reviewed_at;

        // Segundo dueño reenvía la revisión => idempotente: conserva la primera trazabilidad.
        $this->actingAs($secondOwner)->withSession($session)
            ->post(route('cobros.owner.migrations.notices.review', $notice->id))
            ->assertRedirect(route('cobros.owner.migrations.notices'));
        $fresh = $notice->fresh();
        $this->assertSame($owner->id, (int) $fresh->reviewed_by);
        $this->assertTrue($firstReviewedAt->equalTo($fresh->reviewed_at));
        $this->assertDatabaseCount('cobros_migration_notices', 1);
    }

    public function test_credit_import_notice_shows_summary_and_owner_does_not_block_operations(): void
    {
        [$company, , $collector, , $route, $owner] = $this->context();
        $session = ['active_company_id' => $company->id];

        $content = implode("\n", [
            'identificacion_tipo,identificacion,nombre,telefono,monto_original,saldo_pendiente,cuota,frecuencia,fecha_primer_vencimiento,referencia_externa,comportamiento,notas,ruta',
            'cedula,5'.random_int(100000000, 999999999).',Cotejo Uno,88881111,30000,15000,1500,quincenal,2026-11-10,CT-1,bueno,,'.$route->name,
            'cedula,5'.random_int(100000000, 999999999).',Cotejo Dos,88882222,20000,10000,1000,mensual,2026-11-10,CT-2,bueno,,'.$route->name,
        ]);
        $file = UploadedFile::fake()->createWithContent('cotejo.csv', $content);

        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.credits.import.preview'), ['credit_file' => $file])
            ->assertOk()->assertSee('Listas: 2');
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.credits.import.confirm'), ['confirm' => '1'])
            ->assertOk()->assertSee('Importadas: 2');

        // La bandeja muestra el cotejo numérico para comparar con el sistema anterior.
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.migrations.notices'))
            ->assertOk()
            ->assertSee('Importados: 2')
            ->assertSee('50,000.00')
            ->assertSee('25,000.00');

        // La revisión pendiente NO bloquea: el cobrador puede operar cartera y el cliente existe.
        $this->actingAs($collector)->withSession($session)
            ->get(route('cobros.collector.index'))
            ->assertOk();
        $this->assertSame(2, AccountReceivable::forCompany($company->id)->count());
        $this->assertSame(1, CobrosMigrationNotice::where('company_id', $company->id)->where('status', 'pending')->count());
    }

    public function test_customer_import_notice_links_to_existing_correction_view(): void
    {
        [$company, , $collector, , $route, $owner] = $this->context();
        $session = ['active_company_id' => $company->id];

        $csv = implode("\n", [
            'tipo_identificacion,identificacion,nombre,telefono,direccion,ruta,latitud,longitud',
            '01,6'.random_int(100000000, 999999999).',Cotejo Cliente,88881111,Alajuela,'.$route->name.',9.93,-84.07',
        ]);
        $file = UploadedFile::fake()->createWithContent('clientes.csv', $csv);

        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.customers.import.preview'), ['customer_file' => $file])
            ->assertOk()->assertSee('Cotejo Cliente');
        $run = CustomerImportRun::where('company_id', $company->id)->sole();
        $this->actingAs($collector)->withSession($session)
            ->post(route('cobros.collector.customers.import.confirm'), ['run_id' => $run->id])
            ->assertRedirect(route('cobros.collector.customers.import.status', $run->id));

        $notice = CobrosMigrationNotice::where('company_id', $company->id)->sole();
        $this->assertSame($run->id, (int) $notice->source_id);
        $this->assertSame(1, (int) data_get($notice->summary, 'created'));

        // El prestamista ve el resumen y el enlace al mecanismo de corrección existente
        // (vista de detalle de la ejecución, que ya ofrece reanudar/revisar filas).
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.migrations.notices'))
            ->assertOk()
            ->assertSee('Creados: 1')
            ->assertSee('Ver detalle de la importación')
            ->assertSee(route('cobros.owner.customers.import.status', $run->id), false);

        // El enlace funciona y muestra la ejecución (corrección sobre el motor existente).
        $this->actingAs($owner)->withSession($session)
            ->get(route('cobros.owner.customers.import.status', $run->id))
            ->assertOk()->assertSee('Cotejo Cliente');

        $this->assertSame(1, Customer::where('company_id', $company->id)->count());
    }

    public function test_notices_are_isolated_and_require_owner_permission(): void
    {
        [$companyA, , $collectorA, , $routeA, $ownerA] = $this->context();
        $sessionA = ['active_company_id' => $companyA->id];
        $notice = $this->seedNotice($companyA, $routeA, $collectorA, CobrosMigrationNotice::STATUS_PENDING, 'secret-amounts-99999');

        // Otro dueño (otra empresa) no ve ni revisa avisos ajenos.
        $this->flushSession();
        $companyB = Company::create([
            'trade_name' => 'OtherC '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica',
            'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $ownerBRole = $this->roleWith($companyB, ['cobros.acceder', 'cobros.portal_dueno']);
        $otherOwner = User::factory()->create(['is_active' => true]);
        $otherOwner->companies()->attach($companyB->id, ['role_id' => $ownerBRole->id]);
        $sessionB = ['active_company_id' => $companyB->id];
        $this->actingAs($otherOwner)->withSession($sessionB)
            ->get(route('cobros.owner.migrations.notices'))
            ->assertOk()->assertDontSee('secret-amounts-99999');
        $this->actingAs($otherOwner)->withSession($sessionB)
            ->post(route('cobros.owner.migrations.notices.review', $notice->id))
            ->assertNotFound();
        $this->assertSame(CobrosMigrationNotice::STATUS_PENDING, $notice->fresh()->status);

        // Un cobrador sin portal_dueno no accede a la bandeja.
        $this->actingAs($collectorA)->withSession($sessionA)
            ->get(route('cobros.owner.migrations.notices'))->assertForbidden();
        $this->actingAs($collectorA)->withSession($sessionA)
            ->post(route('cobros.owner.migrations.notices.review', $notice->id))->assertForbidden();
        $this->assertSame(CobrosMigrationNotice::STATUS_PENDING, $notice->fresh()->status);
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: Role, 4: CobrosRoute, 5: User, 6: User, 7: CobrosRoute, 8: User} */
    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'NotC '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica',
            'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $role = $this->roleWith($company, ['cobros.acceder', 'cobros.cobrar', 'clientes.crear']);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'customers']);

        $routeA = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta A '.uniqid(), 'active' => true]);
        CobrosRouteUser::create([
            'company_id' => $company->id, 'cobros_route_id' => $routeA->id,
            'user_id' => $user->id, 'active' => true,
        ]);

        $ownerRole = $this->roleWith($company, ['cobros.acceder', 'cobros.portal_dueno']);
        $owner = User::factory()->create(['is_active' => true]);
        $owner->companies()->attach($company->id, ['role_id' => $ownerRole->id]);

        $collectorBRole = $this->roleWith($company, ['cobros.acceder', 'cobros.cobrar', 'clientes.crear']);
        $collectorB = User::factory()->create(['is_active' => true]);
        $collectorB->companies()->attach($company->id, ['role_id' => $collectorBRole->id]);
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta B '.uniqid(), 'active' => true]);
        CobrosRouteUser::create([
            'company_id' => $company->id, 'cobros_route_id' => $routeB->id,
            'user_id' => $collectorB->id, 'active' => true,
        ]);

        $secondOwnerRole = $this->roleWith($company, ['cobros.acceder', 'cobros.portal_dueno']);
        $secondOwner = User::factory()->create(['is_active' => true]);
        $secondOwner->companies()->attach($company->id, ['role_id' => $secondOwnerRole->id]);

        return [$company->fresh(), $branch, $user, $role, $routeA, $owner, $collectorB, $routeB, $secondOwner];
    }

    private function roleWith(Company $company, array $permissions): Role
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }

        return $role;
    }

    private function seedNotice(Company $company, CobrosRoute $route, User $collector, string $status, string $marker): CobrosMigrationNotice
    {
        return CobrosMigrationNotice::create([
            'company_id' => $company->id,
            'cobros_route_id' => $route->id,
            'collector_user_id' => $collector->id,
            'source' => CobrosMigrationNotice::SOURCE_CREDIT_IMPORT,
            'details' => $marker.' crédito(s) importados por el cobrador.',
            'summary' => ['imported' => 1, 'original_total' => '1000', 'balance_total' => '500'],
            'status' => $status,
            'reviewed_by' => $status === CobrosMigrationNotice::STATUS_REVIEWED ? $collector->id : null,
            'reviewed_at' => $status === CobrosMigrationNotice::STATUS_REVIEWED ? now() : null,
        ]);
    }
}
