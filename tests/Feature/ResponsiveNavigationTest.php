<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponsiveNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bottom_bar_shows_only_authorized_items_plus_more(): void
    {
        $html = $this->renderBottomBar(['dashboard.ver', 'pos.acceder', 'productos.ver', 'caja.abrir']);

        foreach (['Inicio', 'POS', 'Productos', 'Caja'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertStringContainsString('id="bottom-nav"', $html);
        $this->assertSame(1, substr_count($html, 'mvs-open-nav'));
    }

    public function test_bottom_bar_hides_items_without_permission(): void
    {
        $html = $this->renderBottomBar(['dashboard.ver']);

        $this->assertStringContainsString('Inicio', $html);
        $this->assertStringNotContainsString(route('pos.index'), $html);
        $this->assertStringNotContainsString(route('productos.index'), $html);
        $this->assertStringNotContainsString(route('cash.index'), $html);
    }

    public function test_bottom_bar_grants_caja_with_only_view_permission(): void
    {
        $html = $this->renderBottomBar(['dashboard.admin', 'caja.ver']);

        $this->assertStringContainsString(route('cash.index'), $html);
        $this->assertStringContainsString(route('dashboard'), $html);
    }

    public function test_sidebar_shell_context_renders_rail_with_pin_and_more_trigger(): void
    {
        $html = $this->renderSidebar(['dashboard.ver'], 'shell');

        $this->assertStringContainsString('id="app-sidebar"', $html);
        $this->assertStringContainsString('x-data="sidebarShell"', $html);
        $this->assertStringContainsString('Fijar o desanclar el menú expandido', $html);
        $this->assertStringContainsString('nav-more-trigger', $html);
    }

    public function test_sidebar_sheet_context_renders_full_menu_without_shell_chrome(): void
    {
        $html = $this->renderSidebar(['dashboard.ver'], 'sheet');

        $this->assertStringContainsString('aria-label="Navegación"', $html);
        $this->assertStringNotContainsString('id="app-sidebar"', $html);
        $this->assertStringNotContainsString('sidebarShell', $html);
        $this->assertStringContainsString(route('dashboard'), $html);
    }

    public function test_layout_includes_mobile_navigation_for_non_pos_routes(): void
    {
        [$company, $branch] = $this->companyContext();
        $user = $this->userWithPermissions($company, $branch, ['dashboard.ver']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        $html = view('layouts.app')->render();

        $this->assertStringContainsString('id="bottom-nav"', $html);
        $this->assertStringContainsString('mvs-open-nav', $html);
        $this->assertStringContainsString('Menú de navegación', $html);
    }

    private function renderBottomBar(array $permissions): string
    {
        [$company, $branch] = $this->companyContext();
        $user = $this->userWithPermissions($company, $branch, $permissions);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        return view('components.navigation.bottom-bar')->render();
    }

    private function renderSidebar(array $permissions, string $context): string
    {
        [$company, $branch] = $this->companyContext();
        $user = $this->userWithPermissions($company, $branch, $permissions);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        return view('components.navigation.sidebar', ['context' => $context])->render();
    }

    private function userWithPermissions(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'General', 'is_active' => true],
            );
            $role->permissions()->attach($permission->id);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function companyContext(): array
    {
        $company = Company::create(['trade_name' => 'Navegación '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);

        return [$company, $branch];
    }
}
