<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
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

    public function test_tenant_shell_uses_bottom_navigation_without_desktop_sidebar(): void
    {
        [$company, $branch] = $this->companyContext();
        $user = $this->userWithPermissions($company, $branch, ['dashboard.ver']);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        $html = view('layouts.app')->render();
        $bottomBar = view('components.navigation.bottom-bar')->render();

        $this->assertStringContainsString('id="bottom-nav"', $html);
        $this->assertStringNotContainsString('id="app-sidebar"', $html);
        $this->assertStringNotContainsString('lg:hidden', $bottomBar);
    }

    public function test_pos_mobile_action_bar_stays_above_tenant_navigation(): void
    {
        $pos = file_get_contents(resource_path('views/pos/index.blade.php'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('id="pos-sticky-bar"', $pos);
        $this->assertStringContainsString('bottom-14', $pos);
        $this->assertStringNotContainsString("@unless(request()->routeIs('pos.*'))", $layout);
    }

    public function test_tenant_header_uses_company_logo_or_neutral_initial_and_keeps_mvs_brand_separate(): void
    {
        [$company, $branch] = $this->companyContext();
        $user = $this->userWithPermissions($company, $branch, []);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        $withoutLogo = view('components.header')->render();
        $this->assertStringContainsString('Empresa '.$company->trade_name, $withoutLogo);
        $this->assertStringContainsString('MVS Commerce', $withoutLogo);
        $this->assertStringNotContainsString('logo-mvs-corto.png', $withoutLogo);

        $logoPath = 'companies/header-test-'.uniqid().'.png';
        Storage::disk('public')->put($logoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

        try {
            $company->update(['logo' => $logoPath]);
            $withLogo = view('components.header')->render();
            $this->assertStringContainsString('/storage/'.$logoPath, $withLogo);
            $this->assertStringNotContainsString('http://localhost/storage/', $withLogo);
            $this->assertStringContainsString('Logo de '.$company->trade_name, $withLogo);
            $this->assertStringContainsString('onerror="this.hidden=true; this.nextElementSibling.hidden=false"', $withLogo);
            $this->assertFileExists(public_path('storage/'.$logoPath));
        } finally {
            Storage::disk('public')->delete($logoPath);
        }

        $withMissingLogo = view('components.header')->render();
        $this->assertStringContainsString('Empresa '.$company->trade_name, $withMissingLogo);
        $this->assertStringNotContainsString('<img', $withMissingLogo);
        $this->assertStringNotContainsString('/storage/'.$logoPath, $withMissingLogo);
    }

    public function test_master_panel_layout_remains_separate_from_tenant_navigation(): void
    {
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $this->actingAs($platform);

        $html = view('layouts.platform', ['errors' => new ViewErrorBag])->render();

        $this->assertStringContainsString('Panel Maestro MVS', $html);
        $this->assertStringNotContainsString('id="bottom-nav"', $html);
        $this->assertStringNotContainsString('mvs-open-nav', $html);
    }

    public function test_tenant_header_falls_back_when_logo_exists_on_disk_but_public_mapping_is_broken(): void
    {
        Storage::fake('public');
        [$company, $branch] = $this->companyContext();
        $user = $this->userWithPermissions($company, $branch, []);
        $logoPath = 'companies/private-only-logo.png';
        Storage::disk('public')->put($logoPath, 'not-publicly-mapped');
        $company->update(['logo' => $logoPath]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        $this->assertTrue(Storage::disk('public')->exists($logoPath));
        $html = view('components.header')->render();

        $this->assertStringContainsString('Empresa '.$company->trade_name, $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('/storage/'.$logoPath, $html);
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
