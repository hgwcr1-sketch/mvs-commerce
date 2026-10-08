<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CobrosQuickNavService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosQuickNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_bar_shows_four_plus_more_and_edit_persists_per_user(): void
    {
        [$c, $b, $u] = $this->ctx();
        $nav = app(CobrosQuickNavService::class);
        $allowed = $nav->allowedRoutes($u, $c);
        $this->assertContains('cobros.visits.today', $allowed);
        $bar = $nav->barRoutes($u, $allowed);
        $this->assertSame(['cobros.visits.today', 'cobros.collector.index', 'cobros.collector.my-customers', 'cobros.collector.payments.history'], $bar);

        $html = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.index'))->getContent();
        $this->assertStringContainsString('cobros-navigation', $html);
        $this->assertStringContainsString('id="cobros-more"', $html);
        $this->assertStringContainsString('Personalizar accesos rápidos', $html);
        $this->assertStringContainsString('Cierre del día', $html);
        $this->assertStringNotContainsString('Cierre del dÃ­a', $html);

        $pick = ['cobros.closing.show', 'cobros.collector.credits.index', 'cobros.collector.credit-requests.index', 'cobros.collector.payments.history'];
        $this->put(route('cobros.quick-nav.update'), ['shortcuts' => $pick])->assertRedirect(route('cobros.quick-nav.edit'));
        $this->assertSame($pick, $nav->barRoutes($u->fresh(), $nav->allowedRoutes($u->fresh(), $c->fresh())));

        // Otro usuario conserva el default (por usuario, no por empresa).
        [, , $u2] = $this->ctx($c, 'cobros.acceder', 'cobros.cobrar', true);
        $this->assertSame(['cobros.visits.today', 'cobros.collector.index', 'cobros.collector.my-customers', 'cobros.collector.payments.history'], $nav->barRoutes($u2, $nav->allowedRoutes($u2, $c)));
    }

    public function test_rejects_unauthorized_duplicated_and_wrong_count(): void
    {
        [$c, $b, $u] = $this->ctx();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id]);
        $this->put(route('cobros.quick-nav.update'), ['shortcuts' => ['cobros.owner.dashboard', 'cobros.visits.today', 'cobros.collector.index', 'cobros.collector.my-customers']])->assertSessionHasErrors('shortcuts');
        $this->put(route('cobros.quick-nav.update'), ['shortcuts' => ['cobros.visits.today', 'cobros.visits.today', 'cobros.collector.index', 'cobros.collector.my-customers']])->assertSessionHasErrors('shortcuts');
        $this->put(route('cobros.quick-nav.update'), ['shortcuts' => ['cobros.visits.today']])->assertSessionHasErrors('shortcuts');
    }

    private function ctx(?Company $c = null, string ...$perms): array
    {
        $perms = $perms === [] ? ['cobros.acceder', 'cobros.cobrar'] : $perms;
        $c ??= Company::create(['trade_name' => 'E '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $b = Branch::create(['company_id' => $c->id, 'name' => 'S '.uniqid(), 'code' => 'P', 'is_active' => true]);
        $u = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Op', 'is_active' => true]);
        foreach ($perms as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $u->companies()->attach($c->id, ['role_id' => $role->id]);
        $u->branches()->attach($b);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($c, $admin, ['cobros']);

        return [$c->fresh(), $b, $u];
    }
}
