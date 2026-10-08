<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorAccessService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CobrosCollectorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_collector_is_authorized_and_service_is_read_only(): void
    {
        [$c, $b, $u] = $this->context();
        $service = app(CollectorAccessService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue($service->canCollectIn($u, $c, $b->id));
        $this->assertSame([$b->id], $service->branchesForCollecting($u, $c)->modelKeys());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q['query']))));
    }

    public function test_active_branch_session_does_not_authorize_an_unassigned_branch(): void
    {
        [$c, $b, $u] = $this->context();
        $u->branches()->detach($b);
        $this->withSession(['active_company_id' => $c->id, 'active_branch_id' => $b->id]);
        $service = app(CollectorAccessService::class);
        $this->assertFalse($service->canCollectIn($u, $c, $b->id));
        $this->assertTrue($service->branchesForCollecting($u, $c)->isEmpty());
        $this->actingAs($u)->get(route('cobros.collector.index'))->assertOk()
            ->assertViewHas('customers', fn ($rows) => $rows->getCollection()->isEmpty());
    }

    public function test_foreign_branch_pivot_contamination_never_grants_access(): void
    {
        [$a, $aBranch, $user] = $this->context();
        [$b, $bBranch] = $this->context();
        $user->branches()->attach($bBranch);
        $service = app(CollectorAccessService::class);
        $this->assertFalse($service->canCollectIn($user, $a, $bBranch->id));
        $this->assertFalse($service->canCollectIn($user, $b, $bBranch->id));
        $this->assertFalse($service->canCollectIn($user, $b, $aBranch->id));
        $this->assertSame([$aBranch->id], $service->branchesForCollecting($user, $a)->modelKeys());
        $this->assertTrue($service->branchesForCollecting($user, $b)->isEmpty());
        $this->actingAs($user)->withSession(['active_company_id' => $a->id])
            ->get(route('cobros.collector.index', ['branch_id' => $bBranch->id, 'company_id' => $b->id]))
            ->assertOk()->assertViewHas('company', fn ($c) => $c->id === $a->id)
            ->assertViewHas('branches', fn ($rows) => $rows->modelKeys() === [$aBranch->id]);
        // EnsureActiveCompany corrige contexto ajeno; nunca opera bajo B.
        $this->withSession(['active_company_id' => $b->id])->get(route('cobros.index'))
            ->assertRedirect(route('cobros.collector.index'))->assertSessionHas('active_company_id', $a->id);
    }

    public function test_permission_is_required_even_with_assigned_branch(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'cobros.supervisar']);
        $service = app(CollectorAccessService::class);
        $this->assertFalse($service->canCollectIn($u, $c, $b->id));
        $this->assertTrue($service->branchesForCollecting($u, $c)->isEmpty());
    }

    public function test_inactive_user_is_blocked_on_service_and_every_direct_route(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'cobros.cobrar', 'cobros.supervisar', 'cobros.portal_dueno']);
        $u->update(['is_active' => false]);
        $this->assertFalse(app(CollectorAccessService::class)->canCollectIn($u, $c, $b->id));
        $this->assertTrue(app(CollectorAccessService::class)->branchesForCollecting($u, $c)->isEmpty());
        $this->actingAs($u)->withSession(['active_company_id' => $c->id]);
        foreach (['index', 'collector.index', 'supervisor.index', 'owner.dashboard'] as $route) {
            $this->get(route('cobros.'.$route))->assertForbidden();
        }
    }

    public function test_inactive_role_is_blocked_by_existing_permission_mechanism(): void
    {
        [$c, $b, $u, $role] = $this->context(['cobros.acceder', 'cobros.cobrar', 'cobros.supervisar', 'cobros.portal_dueno']);
        $role->update(['is_active' => false]);
        $this->assertFalse(app(CollectorAccessService::class)->canCollectIn($u, $c, $b->id));
        $this->assertTrue(app(CollectorAccessService::class)->branchesForCollecting($u, $c)->isEmpty());
        $this->actingAs($u)->withSession(['active_company_id' => $c->id]);
        foreach (['index', 'collector.index', 'supervisor.index', 'owner.dashboard'] as $route) {
            $this->get(route('cobros.'.$route))->assertForbidden();
        }
    }

    public function test_inactive_or_unknown_branch_cannot_be_used(): void
    {
        [$c, $b, $u] = $this->context();
        $b->update(['is_active' => false]);
        $this->assertFalse(app(CollectorAccessService::class)->canCollectIn($u, $c, $b->id));
        $this->assertFalse(app(CollectorAccessService::class)->canCollectIn($u, $c, 999999));
        $this->assertTrue(app(CollectorAccessService::class)->branchesForCollecting($u, $c)->isEmpty());
    }

    public function test_same_user_switches_roles_and_branches_with_company_context(): void
    {
        [$a, $aBranch, $user] = $this->context();
        [$b, $bBranch] = $this->context(['cobros.acceder', 'cobros.supervisar'], $user);
        $service = app(CollectorAccessService::class);
        $this->assertTrue($service->canCollectIn($user, $a, $aBranch->id));
        $this->assertFalse($service->canCollectIn($user, $b, $bBranch->id));
        $this->assertFalse($service->canCollectIn($user, $b, $aBranch->id));
        $this->assertSame([$aBranch->id], $service->branchesForCollecting($user, $a)->modelKeys());
        $this->assertTrue($service->branchesForCollecting($user, $b)->isEmpty());
        $this->actingAs($user)->withSession(['active_company_id' => $a->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
        $this->get(route('cobros.supervisor.index'))->assertForbidden();
        $this->withSession(['active_company_id' => $b->id])->get(route('cobros.index'))
            ->assertRedirect(route('cobros.supervisor.index'));
        $this->get(route('cobros.supervisor.index'))->assertOk()
            ->assertViewHas('branches', fn ($rows) => $rows->modelKeys() === [$bBranch->id]);
        $this->get(route('cobros.collector.index'))->assertForbidden();
        $this->withSession(['active_company_id' => $a->id])->get(route('cobros.collector.index'))->assertOk()
            ->assertViewHas('branches', fn ($rows) => $rows->modelKeys() === [$aBranch->id]);
    }

    public function test_entry_priority_is_owner_then_supervisor_then_collector(): void
    {
        [$c, $b, $u, $role] = $this->context(['cobros.acceder', 'cobros.portal_dueno', 'cobros.supervisar', 'cobros.cobrar']);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.index'))->assertRedirect(route('cobros.owner.dashboard'));
        $this->get(route('cobros.owner.dashboard'))->assertOk()->assertSee('Cartera del dueño');
        $role->permissions()->detach(Permission::where('name', 'cobros.portal_dueno')->first()->id);
        $this->get(route('cobros.index'))->assertRedirect(route('cobros.supervisor.index'));
        $this->get(route('cobros.supervisor.index'))->assertOk()->assertSee('Panel del supervisor');
        $role->permissions()->detach(Permission::where('name', 'cobros.supervisar')->first()->id);
        $this->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
        $this->get(route('cobros.collector.index'))->assertOk()->assertSee('Mi cartera')
            ->assertDontSee('Total por cobrar')->assertDontSee('MVS Commerce');
        $role->permissions()->detach(Permission::where('name', 'cobros.cobrar')->first()->id);
        $this->get(route('cobros.index'))->assertForbidden();
    }

    public function test_direct_operational_urls_require_their_permission(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder']);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id]);
        foreach (['index', 'collector.index', 'supervisor.index', 'owner.dashboard'] as $route) {
            $this->get(route('cobros.'.$route))->assertForbidden();
        }
    }

    public function test_supervisor_lists_only_own_company_branches_without_requiring_assignment(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'cobros.supervisar']);
        [$other, $foreignBranch] = $this->context();
        $u->branches()->detach();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.index', ['company_id' => $other->id]))->assertOk()
            ->assertViewHas('company', fn ($company) => $company->id === $c->id)
            ->assertViewHas('branches', fn ($rows) => $rows->modelKeys() === [$b->id])
            ->assertDontSee($foreignBranch->name)->assertDontSee('Total por cobrar');
    }

    public function test_base_permission_module_and_license_still_protect_direct_landings(): void
    {
        [$c, $b, $u, $role] = $this->context(['cobros.cobrar', 'cobros.supervisar']);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id]);
        $this->get(route('cobros.collector.index'))->assertForbidden();
        $this->get(route('cobros.supervisor.index'))->assertForbidden();
        $permission = Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'Acceder', 'module' => 'CobrOS', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $c->modules()->where('module_key', 'cobros')->update(['is_enabled' => false]);
        $this->get(route('cobros.collector.index'))->assertForbidden();
        $this->get(route('cobros.supervisor.index'))->assertForbidden();
        $this->assertFalse(app(CollectorAccessService::class)->canCollectIn($u, $c->fresh(), $b->id));
        $c->modules()->where('module_key', 'cobros')->update(['is_enabled' => true]);
        $c->license()->update(['status' => 'suspended']);
        $this->get(route('cobros.collector.index'))->assertRedirect(route('license.status'));
        $this->get(route('cobros.supervisor.index'))->assertRedirect(route('license.status'));
    }

    public function test_shell_navigation_follows_active_company_permissions_only(): void
    {
        [$a, $branch, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        [$b] = $this->context(['cobros.acceder', 'cobros.supervisar'], $user);
        [$c] = $this->context(['cobros.acceder', 'cobros.cobrar'], $user);
        foreach ([[$a, 'owner.dashboard'], [$b, 'supervisor.index'], [$c, 'collector.index']] as [$company, $selected]) {
            $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id])
                ->get(route('cobros.'.$selected))->assertOk()
                ->assertSee('logo-mvs-cobros.png')->assertSee($company->trade_name)->assertSee($user->name)
                ->assertSee('aria-current="page"', false)->assertDontSee('MVS Commerce')
                ->assertSee('Más')->assertSee('aria-expanded="false"', false)
                ->assertSee('<dialog id="cobros-more-panel"', false)->assertSee('Cerrar menú')
                ->assertDontSee('<aside class="cobros-sidebar"', false)
                ->assertDontSee('Asignaciones')->assertDontSee('Visitas')->assertDontSee('Reportes')
                ->assertDontSee('Configuración')->assertDontSee('Fase 1E');
            foreach (['owner.dashboard', 'supervisor.index', 'collector.index'] as $route) {
                $link = 'href="'.route('cobros.'.$route).'"';
                if ($route === $selected) {
                    $response->assertSee($link, false);
                    $this->assertSame(1, substr_count($response->getContent(), $link));
                } else {
                    $response->assertDontSee($link, false);
                }
            }
            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent());
            foreach ($dom->getElementsByTagName('a') as $link) {
                $href = $link->getAttribute('href');
                $this->assertTrue(str_starts_with($href, '#') || str_starts_with($href, url('/cobros')), $href);
            }
            [$barHrefs, $panelHrefs] = $this->navHrefsBySlot($dom);
            $this->assertSame([], array_intersect($barHrefs, $panelHrefs), 'barra y Más no deben duplicar enlaces');
            $this->assertSame(array_unique($barHrefs), $barHrefs, 'la barra no debe repetir enlaces');
            $this->assertSame(array_unique($panelHrefs), $panelHrefs, 'el panel Más no debe repetir enlaces');
            $expected = match ($selected) {
                'owner.dashboard' => ['cobros.owner.dashboard', 'cobros.owner.collectors', 'cobros.owner.customers', 'cobros.owner.solicitudes.index', 'cobros.owner.audit', 'cobros.owner.reports', 'cobros.owner.alerts', 'cobros.owner.routes'],
                'supervisor.index' => ['cobros.supervisor.index', 'cobros.supervisor.alerts', 'cobros.supervisor.audit'],
                'collector.index' => ['cobros.visits.today', 'cobros.collector.index', 'cobros.collector.payments.history', 'cobros.closing.show'],
            };
            $this->assertSame(
                array_map(fn ($r) => route($r), $expected),
                array_merge($barHrefs, $panelHrefs),
                'la barra Más deben cubrir exactamente las opciones autorizadas de la empresa activa'
            );
            $response->assertDontSee('Fidelización')->assertDontSee('Fidelization');
            if ($selected === 'supervisor.index') {
                $response->assertSee('Panel del supervisor')->assertDontSee('Total por cobrar')->assertDontSee('Clientes con saldo');
            }
        }
    }

    public function test_owner_full_profile_sees_each_authorized_option_exactly_once(): void
    {
        [$c, $b, $u] = $this->context([
            'cobros.acceder',
            'cobros.portal_dueno',
            'cobros.supervisar',
            'cobros.asignar',
            'cobros.cobrar',
        ]);

        $response = $this->actingAs($u)->withSession(['active_company_id' => $c->id, 'active_branch_id' => $b->id])
            ->get(route('cobros.owner.dashboard'))->assertOk();
        $content = $response->getContent();

        $expected = [
            'cobros.owner.dashboard',
            'cobros.owner.collectors',
            'cobros.owner.customers',
            'cobros.owner.solicitudes.index',
            'cobros.owner.assignments',
            'cobros.supervisor.index',
            'cobros.owner.audit',
            'cobros.owner.reports',
            'cobros.owner.alerts',
            'cobros.visits.today',
            'cobros.collector.index',
            'cobros.collector.payments.history',
            'cobros.closing.show',
        ];
        // Cada opción autorizada aparece UNA sola vez en la navegación (barra + Más).
        // El acceso puntual de la campana de notificaciones en el header no es
        // navegación y no cuenta para este criterio.
        $dom = new \DOMDocument;
        @$dom->loadHTML($content);
        [$barHrefs, $panelHrefs] = $this->navHrefsBySlot($dom);
        $navHrefs = array_merge($barHrefs, $panelHrefs);
        foreach ($expected as $route) {
            $this->assertSame(1, count(array_filter($navHrefs, fn ($href) => $href === route($route))), $route);
        }

        // Alertas y Auditoría aparecen UNA vez: el dueño no ve las variantes de
        // supervisión que apuntan al mismo controlador.
        $this->assertSame(1, substr_count($content, '<span>Alertas</span>'));
        $this->assertSame(1, substr_count($content, '<span>Auditoría</span>'));
        $response->assertDontSee(route('cobros.supervisor.alerts'), false)
            ->assertDontSee(route('cobros.supervisor.audit'), false);

        // Sin filtraciones de navegación Commerce/Fidelización en el shell CobrOS.
        $response->assertDontSee('MVS Commerce')
            ->assertDontSee('Fidelización')->assertDontSee('Fidelization');

        $this->assertNotEmpty($barHrefs);
        $this->assertNotEmpty($panelHrefs);
        $this->assertSame([], array_intersect($barHrefs, $panelHrefs), 'sin duplicación barra vs Más');
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $this->assertTrue(str_starts_with($href, '#') || str_starts_with($href, url('/cobros')), $href);
        }
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function navHrefsBySlot(\DOMDocument $dom): array
    {
        $hrefs = ['cobros-navigation' => [], 'cobros-panel-navigation' => []];
        foreach ($dom->getElementsByTagName('nav') as $nav) {
            $classes = preg_split('/\s+/', trim($nav->getAttribute('class'))) ?: [];
            foreach (array_keys($hrefs) as $slot) {
                if (in_array($slot, $classes, true)) {
                    foreach ($nav->getElementsByTagName('a') as $anchor) {
                        $hrefs[$slot][] = $anchor->getAttribute('href');
                    }
                }
            }
        }

        return [$hrefs['cobros-navigation'], $hrefs['cobros-panel-navigation']];
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar'], ?User $user = null): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.uniqid(), 'code' => 'P', 'is_active' => true]);
        $user ??= User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Operativo', 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        return [$company->fresh(), $branch, $user, $role];
    }
}
