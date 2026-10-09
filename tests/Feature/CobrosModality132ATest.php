<?php

namespace Tests\Feature;

use App\Models\CobrosCollectorAuthorityPolicy;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteUser;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosModalityService;
use App\Services\Cobros\CobrosRouteContext;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BLOQUE 13-2A — modalidades automáticas CobrOS.
 * Catálogo central, permisos predefinidos, preservación de roles,
 * aislamiento empresarial y regresión cobrador/dueño.
 */
class CobrosModality132ATest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function company(string $suffix): Company
    {
        $company = Company::create([
            'trade_name' => 'Modality '.$suffix.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications', 'cash']);

        return $company;
    }

    /** Miembro con rol arbitrario (o sin rol cuando $permissions es vacío). */
    private function member(Company $company, array $permissions, ?string $roleName = null): User
    {
        $user = User::factory()->create(['is_active' => true]);

        $role = Role::create([
            'company_id' => $company->id,
            'name' => $roleName ?? ('Rol '.uniqid()),
            'is_active' => true,
        ]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function freshMember(Company $company): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => null]);

        return $user;
    }

    private function modalityService(): CobrosModalityService
    {
        return app(CobrosModalityService::class);
    }

    public function test_catalogo_define_exactamente_las_cinco_modalidades(): void
    {
        $this->assertSame([
            'prestamista',
            'cobrador_vinculado',
            'prestamista_cobrador',
            'cobrador_independiente',
            'cobrador_multiprestamista',
        ], CobrosModalityCatalog::keys());

        $prestamista = CobrosModalityCatalog::permissions(CobrosModalityCatalog::PRESTAMISTA);
        $this->assertContains('cobros.portal_dueno', $prestamista);
        $this->assertNotContains('cobros.cobrar', $prestamista);

        $prestamistaCobrador = CobrosModalityCatalog::permissions(CobrosModalityCatalog::PRESTAMISTA_COBRADOR);
        $this->assertContains('cobros.portal_dueno', $prestamistaCobrador);
        $this->assertContains('cobros.cobrar', $prestamistaCobrador);

        foreach ([CobrosModalityCatalog::COBRADOR_VINCULADO, CobrosModalityCatalog::COBRADOR_INDEPENDIENTE, CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA] as $collector) {
            $permissions = CobrosModalityCatalog::permissions($collector);
            $this->assertContains('cobros.cobrar', $permissions);
            $this->assertNotContains('cobros.portal_dueno', $permissions);
        }

        $this->assertTrue(CobrosModalityCatalog::allowsMultipleLenders(CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA));
        $this->assertFalse(CobrosModalityCatalog::allowsMultipleLenders(CobrosModalityCatalog::COBRADOR_VINCULADO));
        $this->assertTrue(CobrosModalityCatalog::usesRouteContext(CobrosModalityCatalog::PRESTAMISTA_COBRADOR));
        $this->assertFalse(CobrosModalityCatalog::usesRouteContext(CobrosModalityCatalog::PRESTAMISTA));
    }

    public function test_cinco_modalidades_se_asignan_sin_seleccion_manual_de_roles(): void
    {
        $company = $this->company('auto');

        foreach (CobrosModalityCatalog::keys() as $key) {
            $user = $this->freshMember($company);

            $result = $this->modalityService()->assign($company, $user, $key);

            $this->assertTrue($result['role_changed'], $key);
            $this->assertSame($key, $this->modalityService()->currentModality($company, $user));

            foreach (CobrosModalityCatalog::permissions($key) as $permission) {
                $this->assertTrue(
                    $user->fresh()->hasPermission($permission, $company),
                    "{$key} debería conceder {$permission}"
                );
            }

            if (! CobrosModalityCatalog::collects($key)) {
                $this->assertFalse($user->fresh()->hasPermission('cobros.cobrar', $company), $key);
            } elseif (! CobrosModalityCatalog::isAdministrative($key)) {
                $this->assertFalse($user->fresh()->hasPermission('cobros.portal_dueno', $company), $key);
            } else {
                $this->assertTrue($user->fresh()->hasPermission('cobros.portal_dueno', $company), $key);
            }
        }

        // Un solo rol de modalidad por empresa (idempotente, reutilizable).
        $this->assertSame(5, Role::where('company_id', $company->id)->count());
    }

    public function test_prestamista_cobrador_conserva_administracion_y_cobra_con_contexto_de_ruta(): void
    {
        $company = $this->company('pc');
        $user = $this->freshMember($company);

        $this->modalityService()->assign($company, $user, CobrosModalityCatalog::PRESTAMISTA_COBRADOR);
        $user = $user->fresh();

        // Funciones administrativas (aprobación de créditos vía portal del dueño).
        $this->assertTrue($user->hasPermission('cobros.portal_dueno', $company));
        $this->assertTrue($user->hasPermission('cobros.acceder', $company));
        // Delegación de aprobación existente: default seguro sin tocar la política.
        $this->assertTrue(CobrosCollectorAuthorityPolicy::requiresOwner((int) $company->id, (int) $user->id));

        // Cobro exige contexto de ruta: sin ruta asignada no hay rutas disponibles.
        $context = app(CobrosRouteContext::class);
        session(['active_company_id' => $company->id]);
        $this->assertCount(0, $context->available($user));

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($user->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

        $available = $context->available($user);
        $this->assertCount(1, $available);
        $this->assertSame($route->id, $context->select($user, $route->id)->id);
        $this->assertSame($route->id, (int) session('active_cobros_route_id'));
    }

    public function test_cambiar_modalidad_conserva_permisos_y_no_sobrescribe_role_id_innecesario(): void
    {
        $company = $this->company('change');
        $service = $this->modalityService();

        // Miembro con rol personalizado: gana permisos por UNIÓN en rol combinado.
        $cajero = $this->member($company, ['caja.abrir'], 'Cajero');
        $cajeroRoleId = $cajero->companies()->where('companies.id', $company->id)->first()->pivot->role_id;

        $result = $service->assign($company, $cajero, CobrosModalityCatalog::COBRADOR_VINCULADO);
        $this->assertTrue($result['role_changed']);
        $this->assertNotSame($cajeroRoleId, $result['role']->id);
        $this->assertSame('Cajero + Cobrador vinculado', $result['role']->name);
        $this->assertTrue($cajero->fresh()->hasPermission('caja.abrir', $company));
        $this->assertTrue($cajero->fresh()->hasPermission('cobros.cobrar', $company));

        // Cambio de modalidad: jamás quita permisos (acumula unión).
        $service->assign($company, $cajero, CobrosModalityCatalog::PRESTAMISTA_COBRADOR);
        $cajero = $cajero->fresh();
        $this->assertTrue($cajero->hasPermission('caja.abrir', $company));
        $this->assertTrue($cajero->hasPermission('cobros.cobrar', $company));
        $this->assertTrue($cajero->hasPermission('cobros.portal_dueno', $company));

        // Rol que YA cubre la modalidad: role_id intacto (sin sobrescribir).
        $admin = $this->member($company, CobrosModalityCatalog::permissions(CobrosModalityCatalog::PRESTAMISTA_COBRADOR), 'Administracion');
        $adminRoleId = $admin->companies()->where('companies.id', $company->id)->first()->pivot->role_id;
        $result = $service->assign($company, $admin, CobrosModalityCatalog::PRESTAMISTA_COBRADOR);
        $this->assertFalse($result['role_changed']);
        $this->assertSame($adminRoleId, (int) $admin->fresh()->companies()->where('companies.id', $company->id)->first()->pivot->role_id);

        // Rol super admin: nunca se toca role_id.
        $super = $this->member($company, [], 'Super Tenant');
        Role::where('company_id', $company->id)->where('name', 'Super Tenant')->update(['is_super_admin' => true]);
        $superRoleId = $super->companies()->where('companies.id', $company->id)->first()->pivot->role_id;
        $result = $service->assign($company, $super, CobrosModalityCatalog::PRESTAMISTA_COBRADOR);
        $this->assertFalse($result['role_changed']);
        $this->assertSame($superRoleId, (int) $super->fresh()->companies()->where('companies.id', $company->id)->first()->pivot->role_id);
        $this->assertTrue($super->fresh()->hasPermission('cobros.cobrar', $company));
        $this->assertTrue($super->fresh()->hasPermission('cobros.portal_dueno', $company));

        // Cobrador legado (rol Cobrador CobrOS ya cubre la modalidad): role_id intacto.
        $legacy = $this->member($company, ['cobros.acceder', 'cobros.cobrar'], 'Cobrador CobrOS');
        $legacyRoleId = $legacy->companies()->where('companies.id', $company->id)->first()->pivot->role_id;
        $result = $service->assign($company, $legacy, CobrosModalityCatalog::COBRADOR_VINCULADO);
        $this->assertFalse($result['role_changed']);
        $this->assertSame($legacyRoleId, (int) $legacy->fresh()->companies()->where('companies.id', $company->id)->first()->pivot->role_id);
        $this->assertFalse($legacy->fresh()->hasPermission('cobros.portal_dueno', $company));
    }

    public function test_modalidades_se_aislan_por_empresa(): void
    {
        $companyA = $this->company('iso-a');
        $companyB = $this->company('iso-b');
        $service = $this->modalityService();

        $user = $this->freshMember($companyA);
        $user->companies()->attach($companyB->id, ['role_id' => $this->member($companyB, ['caja.abrir'], 'Cajero B')->companies()->first()->pivot->role_id]);

        $service->assign($companyA, $user, CobrosModalityCatalog::PRESTAMISTA);
        $service->assign($companyB, $user, CobrosModalityCatalog::COBRADOR_VINCULADO);

        $this->assertSame(CobrosModalityCatalog::PRESTAMISTA, $service->currentModality($companyA, $user));
        $this->assertSame(CobrosModalityCatalog::COBRADOR_VINCULADO, $service->currentModality($companyB, $user));

        $user = $user->fresh();
        $this->assertTrue($user->hasPermission('cobros.portal_dueno', $companyA));
        $this->assertFalse($user->hasPermission('cobros.portal_dueno', $companyB));
        $this->assertTrue($user->hasPermission('caja.abrir', $companyB));
        $this->assertFalse($user->hasPermission('caja.abrir', $companyA));

        $roleA = $user->roleInCompany($companyA);
        $roleB = $user->roleInCompany($companyB);
        $this->assertNotSame($roleA?->id, $roleB?->id);
        $this->assertSame($companyA->id, (int) $roleA->company_id);
        $this->assertSame($companyB->id, (int) $roleB->company_id);
    }

    public function test_multiprestamista_conserva_membresias_rutas_e_historial(): void
    {
        $companyA = $this->company('multi-a');
        $companyB = $this->company('multi-b');
        $service = $this->modalityService();

        $user = $this->freshMember($companyA);
        $user->companies()->attach($companyB->id, ['role_id' => null]);

        $route = CobrosRoute::create(['company_id' => $companyA->id, 'name' => 'Ruta Multi', 'active' => true]);
        $route->users()->attach($user->id, ['active' => true]);
        $routesBefore = CobrosRouteUser::where('user_id', $user->id)->count();

        $service->assign($companyA, $user, CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA);
        $service->assign($companyB, $user, CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA);

        $user = $user->fresh();
        $this->assertSame(CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA, $service->currentModality($companyA, $user));
        $this->assertSame(CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA, $service->currentModality($companyB, $user));

        // Cada empresa conserva su propia membresía y su propio rol.
        $this->assertSame(2, $user->companies()->count());
        $this->assertNotSame(
            $user->roleInCompany($companyA)?->id,
            $user->roleInCompany($companyB)?->id
        );

        // Rutas e historial intactos tras asignar/cambiar modalidad.
        $this->assertSame($routesBefore, CobrosRouteUser::where('user_id', $user->id)->count());
        $this->assertTrue(CobrosRoute::forCompany($companyA->id)->whereKey($route->id)->exists(), 'ruta');
        $this->assertTrue($user->hasPermission('cobros.cobrar', $companyA), 'cobrarA');
        $this->assertTrue($user->hasPermission('cobros.cobrar', $companyB), 'cobrarB');
    }

    public function test_regresion_roles_de_cobrador_y_dueno_se_conservan(): void
    {
        $company = $this->company('reg');
        $service = $this->modalityService();

        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros'], 'Dueño');
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar'], 'Cobrador CobrOS');
        $other = $this->freshMember($company);

        $ownerRoleId = $owner->companies()->where('companies.id', $company->id)->first()->pivot->role_id;
        $collectorRoleId = $collector->companies()->where('companies.id', $company->id)->first()->pivot->role_id;

        // Aplicar modalidades a un tercero no altera a dueño ni cobrador.
        $service->assign($company, $other, CobrosModalityCatalog::COBRADOR_INDEPENDIENTE);
        $service->assign($company, $this->freshMember($company), CobrosModalityCatalog::PRESTAMISTA);

        $owner = $owner->fresh();
        $collector = $collector->fresh();
        $this->assertSame($ownerRoleId, (int) $owner->companies()->where('companies.id', $company->id)->first()->pivot->role_id);
        $this->assertSame($collectorRoleId, (int) $collector->companies()->where('companies.id', $company->id)->first()->pivot->role_id);
        $this->assertTrue($owner->hasPermission('cobros.portal_dueno', $company));
        $this->assertTrue($collector->hasPermission('cobros.cobrar', $company));
        $this->assertFalse($collector->hasPermission('cobros.portal_dueno', $company));

        // Delegación de aprobación de créditos intacta (default: dueño autoriza).
        $this->assertTrue(CobrosCollectorAuthorityPolicy::requiresOwner((int) $company->id, (int) $collector->id));

        // El cobrador legado puede adoptar modalidad sin perder su rol original.
        $result = $service->assign($company, $collector, CobrosModalityCatalog::COBRADOR_VINCULADO);
        $this->assertFalse($result['role_changed']);
        $this->assertSame($collectorRoleId, (int) $collector->fresh()->companies()->where('companies.id', $company->id)->first()->pivot->role_id);
        $this->assertSame(CobrosModalityCatalog::COBRADOR_VINCULADO, $service->currentModality($company, $collector));
    }
}

