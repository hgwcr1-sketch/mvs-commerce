<?php

namespace Tests\Feature;

use App\Models\{Branch, Company, Permission, Role, User};
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1A MVS CobrOS — Fundación: acceso separado, activación por empresa,
 * permisos propios y aislamiento multiempresa.
 *
 * No cubre funciones financieras ni operativas: todavía no existen.
 */
class CobrosModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | Acceso al portal
     | --------------------------------------------------------------------- */

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('cobros.index'))->assertRedirect(route('login'));
    }

    public function test_company_without_cobros_module_is_rejected(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder']);
        $this->setContract($c, ['sales']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertForbidden();
    }

    public function test_company_with_module_but_user_without_permission_is_rejected(): void
    {
        [$c, $b, $u] = $this->context(['clientes.ver']);
        $this->setContract($c, ['cobros']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertForbidden();
    }

    public function test_user_with_permission_but_module_disabled_is_rejected(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder']);

        // Empresas sin contrato configurado mantienen el comportamiento legacy.
        $this->assertTrue($c->isModuleEnabled('cobros'));

        $this->setContract($c, ['sales']);
        $this->assertFalse($c->fresh()->isModuleEnabled('cobros'));

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertForbidden();
    }

    public function test_company_with_module_and_user_with_permission_opens_initial_panel(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder']);
        $this->setContract($c, ['cobros']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertOk()
            ->assertSee('MVS CobrOS')
            ->assertSee('Panel de CobrOS')
            ->assertSee('src="'.asset('images/logo-mvs-cobros.png').'"', false)
            ->assertSee('href="'.asset('icons/cobros/favicon-32x32.png').'"', false)
            ->assertSee('href="'.asset('icons/cobros/apple-touch-icon.png').'"', false)
            ->assertSee('href="'.asset('manifest-cobros.json').'"', false)
            ->assertDontSee('href="'.asset('manifest.json').'"', false)
            ->assertDontSee('MVS Commerce')
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertSee($c->trade_name);
    }

    /* ---------------------------------------------------------------------
     | Aislamiento multiempresa
     | --------------------------------------------------------------------- */

    public function test_user_of_other_company_cannot_use_a_foreign_company_context(): void
    {
        [$a, $aBranch, $ua] = $this->context(['cobros.acceder'], 'Alpha');
        [$b, $bBranch, $ub] = $this->context(['clientes.ver'], 'Beta');
        $this->setContract($a, ['cobros']);
        $this->setContract($b, ['sales']);

        // Beta intenta entrar con el contexto de Alpha: la sesión se corrige a su
        // propia empresa y el acceso queda rechazado por falta de módulo.
        $this->actingAs($ub)->withSession(['active_company_id' => $a->id, 'active_branch_id' => $aBranch->id])
            ->get(route('cobros.index'))
            ->assertForbidden()
            ->assertSessionHas('active_company_id', $b->id);

        // Alpha solo ve su empresa, nunca datos de Beta.
        $this->actingAs($ua)->withSession($this->ctx($a, $aBranch))
            ->get(route('cobros.index'))
            ->assertOk()
            ->assertSee($a->trade_name)
            ->assertDontSee($b->trade_name);
    }

    /* ---------------------------------------------------------------------
     | Perfiles de CobrOS
     | --------------------------------------------------------------------- */

    public function test_owner_with_portal_dueno_reaches_the_owner_panel(): void
    {
        [$c, $b, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        $this->setContract($c, ['cobros']);

        $this->actingAs($owner)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertOk();

        // El Portal del Dueño, por sí solo, no concede entrada (sin bypass).
        [$c2, $b2, $onlyPortal] = $this->context(['cobros.portal_dueno'], 'PortalSolo');
        $this->setContract($c2, ['cobros']);

        $this->actingAs($onlyPortal)->withSession($this->ctx($c2, $b2))
            ->get(route('cobros.index'))
            ->assertForbidden();
    }

    public function test_collector_with_only_cobrar_permission_has_no_admin_access(): void
    {
        [$c, $b, $collector] = $this->context(['cobros.cobrar']);
        $this->setContract($c, ['cobros']);

        $this->actingAs($collector)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertForbidden();

        $this->assertFalse($collector->hasPermission('cobros.acceder', $c));
    }

    /* ---------------------------------------------------------------------
     | Activación por empresa
     | --------------------------------------------------------------------- */

    public function test_tenant_cannot_self_activate_the_cobros_module(): void
    {
        [$c, $b, $u] = $this->context(['dashboard.ver', 'cobros.acceder']);
        $this->setContract($c, ['sales']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->patch(route('platform.modules.update', $c), ['modules' => ['cobros']])
            ->assertForbidden();

        $this->assertFalse($c->fresh()->isModuleEnabled('cobros'));
    }

    public function test_enabling_cobros_does_not_enable_pos_cash_inventory_or_routeos(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'pos.acceder', 'routeos.acceder']);
        $this->setContract($c, ['cobros']);

        $c->refresh();
        $this->assertTrue($c->isModuleEnabled('cobros'));
        $this->assertFalse($c->isModuleEnabled('sales'));
        $this->assertFalse($c->isModuleEnabled('inventory'));
        $this->assertFalse($c->isModuleEnabled('cash'));
        $this->assertFalse($c->isModuleEnabled('routeos'));

        // Con contrato solo CobrOS, los permisos de otros módulos dejan de ser efectivos.
        $this->assertFalse($u->hasPermission('pos.acceder', $c));
        $this->assertFalse($u->hasPermission('routeos.acceder', $c));
    }

    public function test_disabling_cobros_blocks_access_without_destroying_data(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder']);
        $this->setContract($c, ['cobros']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertOk();

        $this->setContract($c, ['sales']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('cobros.index'))
            ->assertForbidden();

        // Empresa, vínculo de usuario, rol y permiso persisten: desactivar no borra datos.
        $this->assertTrue($c->fresh()->exists());
        $this->assertTrue($u->fresh()->companies()->whereKey($c->id)->exists());
        $role = $u->roleInCompany($c);
        $this->assertNotNull($role);
        $this->assertTrue($role->permissions()->where('permissions.name', 'cobros.acceder')->exists());
        $this->assertTrue(
            $c->modules()->where('module_key', 'cobros')->where('is_enabled', false)->exists()
        );
    }

    /* ---------------------------------------------------------------------
     | Navegación separada desde Commerce
     | --------------------------------------------------------------------- */

    public function test_cobros_is_absent_from_commerce_navigation_even_with_module_and_permission(): void
    {
        [$c, $b, $u] = $this->context(['dashboard.ver', 'cobros.acceder']);
        $this->setContract($c, ['cobros', 'administration']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('MVS CobrOS')
            ->assertDontSee('href="'.route('cobros.index').'"', false);

        $this->setContract($c, ['administration']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('MVS CobrOS');
    }

    public function test_cobros_only_company_with_active_license_can_enter_without_commerce_modules(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder']);
        $this->setContract($c, ['cobros']);
        $license = app(\App\Services\CompanyLicenseService::class)->ensure($c);
        $license->update(['status' => 'active', 'expires_at' => now()->addMonth()]);

        $this->assertSame(['cobros'], $c->modules()->where('is_enabled', true)->pluck('module_key')->all());
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.index'))->assertOk();

        $license->update(['status' => 'suspended']);
        $this->get(route('cobros.index'))->assertRedirect(route('license.status'));
    }

    private function context(array $permissions, string $companyName = 'Empresa'): array
    {
        $c = Company::create([
            'trade_name' => $companyName.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $b = Branch::create(['company_id' => $c->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        $u = User::factory()->create();
        $r = Role::create(['company_id' => $c->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $r->permissions()->attach($permission);
        }

        $u->companies()->attach($c->id, ['role_id' => $r->id]);
        $u->branches()->attach($b->id);

        app(CompanyCashSettingsProvisioner::class)->provision($c);

        return [$c, $b, $u];
    }

    /** Configura el contrato de módulos de la empresa como Platform Admin. */
    private function setContract(Company $company, array $enabledModules): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('platform.modules.update', $company), ['modules' => $enabledModules])
            ->assertRedirect();

        $company->refresh();
    }

    private function ctx(Company $c, Branch $b): array
    {
        return ['active_company_id' => $c->id, 'active_branch_id' => $b->id];
    }
}