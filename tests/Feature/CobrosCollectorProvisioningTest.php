<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Cobros\CollectorManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Diagnóstico 1 — provisión real de un cobrador creado por el dueño.
 *
 * Reproduce el flujo completo sin asumir IDs: el dueño crea el cobrador por la
 * ruta HTTP real, el cobrador entra por el login CobrOS, cambia la contraseña
 * temporal y debe aterrizar en su portal. Cada eslabón se verifica aparte para
 * localizar exactamente dónde se rompe la entrada.
 */
class CobrosCollectorProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function provision(array $extraOwnerPermissions = []): array
    {
        $company = Company::create([
            'trade_name' => 'Provision '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $platformAdmin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $license = app(CompanyLicenseService::class);
        $license->updateModules($company, $platformAdmin, ['cobros']);
        $license->updateCobrosContract($company, $platformAdmin, [
            'license_mode' => 'company_cupo',
            'collector_limit' => 3,
            'admin_license_charged' => false,
        ]);

        $owner = User::factory()->create(['password' => 'Secure123', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño', 'is_active' => true]);
        foreach (array_merge(['cobros.acceder', 'cobros.portal_dueno', 'cobros.asignar'], $extraOwnerPermissions) as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $owner->companies()->attach($company->id, ['role_id' => $role->id]);
        $owner->branches()->attach($branch->id);

        return [$company->fresh(), $branch, $owner];
    }

    public function test_new_collector_created_by_owner_can_enter_the_portal(): void
    {
        [$company, $branch, $owner] = $this->provision();
        $email = 'cob'.uniqid().'@test.test';

        // 1) El dueño crea el cobrador por la ruta real.
        $response = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Cobrador Nuevo',
                'email' => $email,
                'phone' => '88888888',
                'branch_ids' => [$branch->id],
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertSessionHas('collector_plain_password');
        $response->assertRedirect();

        $plain = session('collector_plain_password');
        $this->assertNotNull($plain, 'El dueño debe recibir la contraseña temporal.');

        $collector = User::query()->where('email', $email)->firstOrFail();

        // 2) Aislamiento de provisión: usuario, rol, permisos, empresa, sucursal, módulo.
        $this->assertTrue($collector->is_active, 'El cobrador debe quedar activo.');
        $this->assertTrue($collector->must_change_password);
        $this->assertFalse($collector->is_platform_admin);
        $this->assertNotNull($collector->roleInCompany($company), 'Debe tener rol en la empresa.');
        $this->assertSame('Cobrador CobrOS', $collector->roleInCompany($company)->name);
        $this->assertTrue($collector->hasPermission('cobros.acceder', $company), 'Falta permission_role cobros.acceder.');
        $this->assertTrue($collector->hasPermission('cobros.cobrar', $company), 'Falta permission_role cobros.cobrar.');
        $this->assertTrue($company->fresh()->isModuleEnabled('cobros'), 'Módulo cobros deshabilitado.');
        $this->assertNotNull(app(\App\Services\Cobros\CollectorManagementService::class)->getContract($company), 'Falta contrato CobrOS.');
        $this->assertTrue($collector->companies()->where('companies.id', $company->id)->exists(), 'Sin empresa asignada.');
        $this->assertSame([$branch->id], $collector->branches()->where('branches.company_id', $company->id)->get()->pluck('id')->all(), 'Sin sucursal asignada.');

        // 3) El dueño cierra sesión en el equipo y el cobrador entra con su
        //    contraseña temporal (mismo guard web compartido por ambos portales).
        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.login'));

        $login = $this->post(route('cobros.login.store'), ['email' => $email, 'password' => $plain]);
        $this->assertSame($collector->id, auth()->id(), 'Tras el login debe quedar autenticado el cobrador.');
        $this->assertTrue((bool) auth()->user()->must_change_password, 'El flag must_change_password debe seguir activo tras el login.');
        $login->assertRedirect(route('cobros.password.force'));

        // 4) Cambio forzado de contraseña.
        $this->post(route('cobros.password.force.store'), [
            'password' => 'NuevaClave7',
            'password_confirmation' => 'NuevaClave7',
        ])->assertRedirect(route('cobros.index'));

        // 5) Entrada al portal del cobrador.
        $this->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
        $this->get(route('cobros.collector.index'))->assertOk()->assertSee('Mi cartera');
    }

    public function test_collector_without_branches_still_enters_the_portal(): void
    {
        [$company, $branch, $owner] = $this->provision();
        $email = 'sinsucursal'.uniqid().'@test.test';

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Cobrador Sin Sucursal',
                'email' => $email,
            ])->assertRedirect();

        $collector = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame([], $collector->branches()->get()->pluck('id')->all());

        $plain = session('collector_plain_password');
        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.login'));

        $this->post(route('cobros.login.store'), ['email' => $email, 'password' => $plain])
            ->assertRedirect(route('cobros.password.force'));

        $this->post(route('cobros.password.force.store'), [
            'password' => 'NuevaClave7',
            'password_confirmation' => 'NuevaClave7',
        ])->assertRedirect(route('cobros.index'));

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
        $this->get(route('cobros.collector.index'))->assertOk();
    }

    /**
     * Un equipo con sesión abierta (el dueño probando en el teléfono del
     * cobrador, u otro usuario de Commerce) nunca llega al formulario de
     * login CobrOS: RedirectIfAuthenticated lo manda directo a /cobros.
     */
    public function test_device_with_open_session_never_reaches_the_collector_login_form(): void
    {
        [$company, $branch, $owner] = $this->provision();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.login'))->assertRedirect(route('cobros.index'));

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.owner.dashboard'));
    }

    /**
     * Sesión abierta de un usuario SIN permiso CobrOS: tampoco puede ver el
     * formulario de login y recibe un muro 403 dentro de CobrOS.
     */
    public function test_authenticated_user_without_cobros_permission_hits_a_403_wall(): void
    {
        [$company, $branch, $owner] = $this->provision();
        $stranger = User::factory()->create(['password' => 'Secure123', 'is_active' => true]);
        $stranger->companies()->attach($company->id, ['role_id' => Role::create([
            'company_id' => $company->id, 'name' => 'Sin CobrOS', 'is_active' => true,
        ])->id]);

        $this->actingAs($stranger)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.login'))->assertRedirect(route('cobros.index'));

        $this->get(route('cobros.index'))->assertForbidden();
    }

    public function test_owner_with_all_cobros_permissions_does_not_consume_the_collector_slot(): void
    {
        [$company, $branch, $owner] = $this->provision(['cobros.cobrar']);
        $service = app(CollectorManagementService::class);

        $this->assertTrue($owner->hasPermission('cobros.cobrar', $company));
        $this->assertSame(0, $service->getCollectorCount($company), 'El dueño/administrador no debe consumir cupo.');
        $this->assertTrue($service->canCreateCollector($company));

        for ($i = 1; $i <= 3; $i++) {
            $service->createCollector($company, [
                'name' => "Cobrador {$i}",
                'email' => "cob{$i}@test.test",
            ], $owner);
        }

        $this->assertSame(3, $service->getCollectorCount($company), 'Solo los cobradores reales cuentan.');
        $this->assertFalse($service->canCreateCollector($company));
        $this->assertSame(4, $service->getPotentialLicenses($company), '3 cobradores + 1 dueño/admin.');
    }

    public function test_existing_inactive_user_is_reactivated_when_registered_by_the_owner(): void
    {
        [$company, $branch, $owner] = $this->provision();
        $email = 'reactivado'.uniqid().'@test.test';
        $existing = User::factory()->create(['email' => $email, 'password' => 'ClaveAnterior9', 'is_active' => false]);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Cobrador Reactivado',
                'email' => $email,
            ])->assertSessionHasNoErrors()->assertRedirect();

        $plain = session('collector_plain_password');
        $this->assertNotNull($plain, 'El dueño debe recibir la contraseña temporal.');

        $existing->refresh();
        $this->assertTrue($existing->is_active, 'El alta debe reactivar al usuario inactivo.');
        $this->assertTrue($existing->must_change_password);
        $this->assertSame('Cobrador CobrOS', $existing->roleInCompany($company)->name);

        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.login'));
        $this->post(route('cobros.login.store'), ['email' => $email, 'password' => $plain])
            ->assertRedirect(route('cobros.password.force'));
    }

    public function test_blocked_registration_shows_the_error_and_the_cupo_notice_to_the_owner(): void
    {
        [$company, $branch, $owner] = $this->provision();
        $service = app(CollectorManagementService::class);

        for ($i = 1; $i <= 3; $i++) {
            $service->createCollector($company, [
                'name' => "Cobrador {$i}",
                'email' => "full{$i}@test.test",
            ], $owner);
        }

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Excedente',
                'email' => 'excedente@test.test',
            ])->assertSessionHasErrors('collector');

        $this->assertTrue(session()->has('errors'), 'flash de errores presente tras el POST.');
        $this->assertSame(
            'Límite de cobradores alcanzado (3).',
            session('errors')->getBag('default')->first(),
            'El mensaje de cupo debe viajar en el flash de errores.'
        );

        $this->get(route('cobros.owner.collectors'))->assertOk()
            ->assertSee('Cupo de cobradores alcanzado')
            ->assertSee('3 / 3');
    }
}
