<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosModalityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BLOQUE 21D-FIX-3G1 — autorización de servidor por modalidad.
 *
 * Matriz: prestamista (no cobra), cobrador_independiente y cobrador_vinculado
 * (cobran, sin administración), prestamista_cobrador (ambas) y membresía sin
 * modalidad (compatibilidad legacy). Aislamiento por empresa intacto.
 */
class CobrosModalityAuthorization21D3G1Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: Branch} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'CR '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'C'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        return [$company, $user, $branch];
    }

    private function withModality(Company $company, User $user, string $modality): void
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Temp '.uniqid(), 'is_active' => true]);
        foreach (CobrosModalityCatalog::permissions($modality) as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($company->branches()->first()->id);
        app(CobrosModalityService::class)->assign($company, $user, $modality);
    }

    public function test_prestamista_is_denied_operational_routes_but_keeps_admin(): void
    {
        [$c, $u] = $this->context();
        $this->withModality($c, $u, CobrosModalityCatalog::PRESTAMISTA);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.index'))->assertForbidden();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.show'))->assertForbidden();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->assertForbidden();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.routes.select'))->assertForbidden();

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertOk();
    }

    public function test_cobrador_vinculado_collects_but_is_denied_administration(): void
    {
        [$c, $u] = $this->context();
        $this->withModality($c, $u, CobrosModalityCatalog::COBRADOR_VINCULADO);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.index'))->assertOk();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.show'))->assertOk();

        // Sin permisos administrativos: el middleware de permisos ya aplica;
        // además la modalidad deniega aunque un rol legado los contuviera.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertForbidden();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index'))->assertForbidden();
    }

    public function test_cobrador_independiente_collects_but_is_denied_administration(): void
    {
        [$c, $u] = $this->context();
        $this->withModality($c, $u, CobrosModalityCatalog::COBRADOR_INDEPENDIENTE);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.index'))->assertOk();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.customers'))->assertForbidden();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.collectors'))->assertForbidden();
    }

    public function test_prestamista_cobrador_keeps_both_families(): void
    {
        [$c, $u] = $this->context();
        $this->withModality($c, $u, CobrosModalityCatalog::PRESTAMISTA_COBRADOR);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.index'))->assertOk();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertOk();
    }

    public function test_membership_without_modality_keeps_legacy_access(): void
    {
        [$c, $u, $b] = $this->context();
        // Rol legado con permisos de ambas familias, SIN modalidad asignada.
        $role = Role::create(['company_id' => $c->id, 'name' => 'Legado '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar', 'cobros.portal_dueno'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $u->companies()->attach($c->id, ['role_id' => $role->id]);
        $u->branches()->attach($b->id);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.index'))->assertOk();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertOk();
    }

    public function test_prestamista_denied_even_when_legacy_role_contains_cobrar(): void
    {
        [$c, $u] = $this->context();
        // Rol legado con cobrar + modalidad prestamista (unión nunca revoca).
        $role = Role::create(['company_id' => $c->id, 'name' => 'Mixto '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar', 'cobros.portal_dueno'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $u->companies()->attach($c->id, ['role_id' => $role->id]);
        $u->branches()->attach($c->branches()->first()->id);
        app(CobrosModalityService::class)->assign($c, $u, CobrosModalityCatalog::PRESTAMISTA);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.index'))->assertForbidden();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.dashboard'))->assertOk();
    }

    public function test_isolation_other_company_membership_is_not_affected(): void
    {
        [$c1, $u1] = $this->context();
        [$c2] = $this->context();
        $this->withModality($c1, $u1, CobrosModalityCatalog::PRESTAMISTA);

        // Segunda empresa donde el MISMO usuario es cobrador vinculado.
        $this->withModality($c2, $u1, CobrosModalityCatalog::COBRADOR_VINCULADO);

        $this->actingAs($u1)->withSession(['active_company_id' => $c1->id])
            ->get(route('cobros.collector.index'))->assertForbidden();
        $this->actingAs($u1)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.collector.index'))->assertOk();
    }
}
