<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const FIDELIDAD_PERMISSIONS = [
        'fidelidad.ver',
        'fidelidad.dashboard',
        'fidelidad.oportunidades',
        'fidelidad.clientes',
        'fidelidad.whatsapp',
        'fidelidad.contactar',
        'fidelidad.configuracion',
        'fidelidad.multiplicadores',
        'fidelidad.premios',
        'fidelidad.canjes',
        'fidelidad.ajustes',
        'fidelidad.portal',
        'fidelidad.promociones',
    ];

    // ── 1. Permisos sembrados y asignados al Administrador ──────────────

    public function test_all_fidelidad_permissions_are_seeded(): void
    {
        $this->seed(PermissionSeeder::class);

        $seeded = Permission::query()
            ->where('module', 'Fidelidad')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $expected = self::FIDELIDAD_PERMISSIONS;
        sort($expected);
        $this->assertEquals($expected, $seeded);
    }

    public function test_administrator_role_receives_all_fidelidad_permissions(): void
    {
        $company = Company::create(['trade_name' => 'Emp '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $adminRole = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);

        $this->seed(PermissionSeeder::class);

        foreach (self::FIDELIDAD_PERMISSIONS as $perm) {
            $permission = Permission::where('name', $perm)->firstOrFail();
            $this->assertTrue(
                $adminRole->permissions()->whereKey($permission->getKey())->exists(),
                "Administrador缺少权限: {$perm}",
            );
        }
    }

    // ── 2. Administrador puede acceder a todas las rutas ────────────────

    public function test_admin_can_access_kardex_index(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.kardex.index'))->assertOk();
    }

    public function test_admin_can_access_dashboard(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.dashboard'))->assertOk();
    }

    public function test_admin_can_access_opportunities(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.opportunities.index'))->assertOk();
    }

    public function test_admin_can_access_settings(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.settings'))->assertRedirect();
    }

    public function test_admin_can_access_rules(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.rules.index'))->assertOk();
    }

    public function test_admin_can_access_adjustments(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.adjustments.index'))->assertOk();
    }

    public function test_admin_can_access_multipliers(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.multipliers.index'))->assertOk();
    }

    public function test_admin_can_access_rewards(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.rewards.index'))->assertOk();
    }

    public function test_admin_can_access_redemptions(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.redemptions.index'))->assertOk();
    }

    public function test_admin_can_access_portal_accesses(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.accesses.index'))->assertOk();
    }

    public function test_admin_can_access_promotions(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.promotions.index'))->assertOk();
    }

    public function test_admin_can_access_portal_show(): void
    {
        [$company, $branch, $admin] = $this->adminContext();
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente Portal',
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
        $this->actingAs($admin)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.portal.show', $customer))->assertOk();
    }

    // ── 3. Usuario sin permiso recibe 403 ──────────────────────────────

    public function test_user_without_permission_gets_403_on_kardex(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.kardex.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_dashboard(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.dashboard'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_adjustments(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.adjustments.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_multipliers(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.multipliers.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_rewards(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.rewards.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_redemptions(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.redemptions.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_portal_accesses(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.accesses.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_promotions(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.promotions.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_rules(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.rules.index'))->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_opportunities(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.opportunities.index'))->assertForbidden();
    }

    // ── 4. Sidebar muestra todas las entradas para Administrador ────────

    public function test_sidebar_shows_all_loyalty_entries_for_admin(): void
    {
        $this->seed(PermissionSeeder::class);

        $company = Company::create(['trade_name' => 'Emp '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $adminRole = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $adminRole->permissions()->syncWithoutDetaching(Permission::where('is_active', true)->pluck('id')->all());
        $user = User::factory()->create();
        $user->companies()->attach($company->id, ['role_id' => $adminRole->id]);
        $user->branches()->attach($branch->id);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch));
        $html = view('components.navigation.sidebar')->render();

        $expectedEntries = [
            'Dashboard',
            'Oportunidades',
            'Kardex',
            'Centro de reglas',
            'Multiplicadores',
            'Premios',
            'Canjes de premios',
            'Ajustes de puntos',
            'Accesos al portal',
            'Promociones del portal',
        ];

        foreach ($expectedEntries as $label) {
            $this->assertStringContainsString($label, $html, "Sidebar entry missing: {$label}");
        }
    }

    // ── 5. No hay rutas de Fidelización sin middleware ──────────────────

    public function test_all_loyalty_routes_have_permission_middleware(): void
    {
        $this->seed(PermissionSeeder::class);

        $routes = collect([
            'loyalty.kardex.index' => 'fidelidad.ver',
            'loyalty.dashboard' => 'fidelidad.dashboard',
            'loyalty.portal.show' => 'fidelidad.ver',
            'loyalty.opportunities.index' => 'fidelidad.oportunidades',
            'loyalty.opportunities.contact' => 'fidelidad.contactar',
            'loyalty.settings' => 'fidelidad.configuracion',
            'loyalty.rules.index' => 'fidelidad.configuracion',
            'loyalty.rules.update' => 'fidelidad.configuracion',
            'loyalty.adjustments.index' => 'fidelidad.ajustes',
            'loyalty.adjustments.store' => 'fidelidad.ajustes',
            'loyalty.accesses.index' => 'fidelidad.portal',
            'loyalty.accesses.store' => 'fidelidad.portal',
            'loyalty.multipliers.index' => 'fidelidad.multiplicadores',
            'loyalty.multipliers.store' => 'fidelidad.multiplicadores',
            'loyalty.rewards.index' => 'fidelidad.premios',
            'loyalty.rewards.store' => 'fidelidad.premios',
            'loyalty.promotions.index' => 'fidelidad.promociones',
            'loyalty.promotions.store' => 'fidelidad.promociones',
            'loyalty.redemptions.index' => 'fidelidad.canjes',
            'loyalty.redemptions.store' => 'fidelidad.canjes',
        ]);

        $middleware = app('router')->getRoutes();

        foreach ($routes as $routeName => $expectedPermission) {
            $route = $middleware->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} not found");
            $actionMiddleware = $route->gatherMiddleware();
            $hasPermission = collect($actionMiddleware)->contains(
                fn ($m) => str_contains($m, 'permission:fidelidad.')
            );
            $this->assertTrue(
                $hasPermission,
                "Route {$routeName} missing permission:fidelidad.* middleware (has: " . implode(', ', $actionMiddleware) . ")",
            );
        }
    }

    // ── 6. Aislamiento por empresa ──────────────────────────────────────

    public function test_company_a_cannot_see_company_b_loyalty_data(): void
    {
        [$companyA, $branchA] = $this->context();
        [$companyB, $branchB] = $this->context();

        $userA = $this->userWithPermissions($companyA, $branchA, ['fidelidad.ver']);
        $userB = $this->userWithPermissions($companyB, $branchB, ['fidelidad.ver']);

        // Create a customer with loyalty account in companyA
        $customerA = Customer::create([
            'company_id' => $companyA->id,
            'name' => 'Cliente A',
            'customer_type' => 'individual',
            'is_active' => true,
        ]);

        $this->actingAs($userB)->withSession($this->activeSession($companyB, $branchB))
            ->get(route('loyalty.portal.show', $customerA))
            ->assertNotFound();
    }

    public function test_company_a_admin_cannot_toggle_company_b_multiplier(): void
    {
        [$companyA, $branchA] = $this->context();
        [$companyB, $branchB] = $this->context();

        $adminA = $this->userWithPermissions($companyA, $branchA, ['fidelidad.multiplicadores']);

        $multiplierB = LoyaltyMultiplier::create([
            'company_id' => $companyB->id,
            'name' => 'B promo',
            'multiplier' => '2.0000',
            'starts_at' => '2026-08-22 00:00:00',
            'ends_at' => '2026-08-22 23:59:59',
            'is_active' => true,
        ]);

        $this->actingAs($adminA)->withSession($this->activeSession($companyA, $branchA))
            ->patch(route('loyalty.multipliers.toggle', $multiplierB))
            ->assertNotFound();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Emp '.uniqid(), 'timezone' => 'UTC', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'earn_on_offers' => false]);

        return [$company, $branch];
    }

    private function adminContext(): array
    {
        [$company, $branch] = $this->context();

        $this->seed(PermissionSeeder::class);

        $adminRole = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $adminRole->permissions()->syncWithoutDetaching(
            Permission::where('is_active', true)->pluck('id')->all()
        );

        $user = User::factory()->create();
        $user->companies()->attach($company->id, ['role_id' => $adminRole->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function userWithPermissions(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

}
