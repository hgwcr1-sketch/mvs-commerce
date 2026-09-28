<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltySettingsSidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fidelizacion_menu_links_to_general_settings_for_users_with_edit_permission(): void
    {
        $html = $this->renderSidebar(['fidelidad.dashboard', 'configuracion.ver', 'configuracion.editar']);

        $this->assertStringContainsString('Fidelización', $html);
        $this->assertSame(2, $this->settingsLinkCount($html));
    }

    public function test_fidelizacion_menu_hides_settings_link_without_edit_permission(): void
    {
        $html = $this->renderSidebar(['fidelidad.dashboard', 'configuracion.ver']);

        $this->assertStringContainsString('Fidelización', $html);
        $this->assertSame(1, $this->settingsLinkCount($html));
    }

    private function settingsLinkCount(string $html): int
    {
        return substr_count($html, 'href="'.route('configuracion.index').'"');
    }

    private function renderSidebar(array $permissions): string
    {
        [$company, $branch] = $this->companyContext();
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

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        return view('components.navigation.sidebar')->render();
    }

    private function companyContext(): array
    {
        $company = Company::create(['trade_name' => 'Navegación '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);

        return [$company, $branch];
    }
}
