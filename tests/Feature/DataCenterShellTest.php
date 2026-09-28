<?php

namespace Tests\Feature;

use App\Http\Controllers\DataCenterController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\PurchaseImportController;
use App\Http\Controllers\PurchaseXmlImportController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DataCenterShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_routes_point_to_the_orchestration_controller(): void
    {
        foreach (['index', 'imports', 'exports', 'reports'] as $action) {
            $route = Route::getRoutes()->getByName("data-center.{$action}");

            $this->assertNotNull($route);
            $this->assertSame(DataCenterController::class.'@'.$action, $route->getActionName());
        }
    }

    public function test_import_cards_link_to_existing_flows_without_replacing_them(): void
    {
        [$company, $branch, $user] = $this->context([
            'compras.crear',
            'compras.ver',
            'inventario.ver',
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.imports'));

        $response->assertOk()
            ->assertSee('data-existing-import="purchase-excel"', false)
            ->assertSee('data-existing-import="purchase-xml"', false)
            ->assertSee('data-existing-import="inventory"', false)
            ->assertSee(route('compras.index'), false)
            ->assertSee(route('compras.import.xml.create'), false)
            ->assertSee(route('importaciones.inventario'), false);

        $this->assertSame(
            PurchaseImportController::class.'@store',
            Route::getRoutes()->getByName('compras.import.excel')->getActionName(),
        );
        $this->assertSame(
            PurchaseXmlImportController::class.'@store',
            Route::getRoutes()->getByName('compras.import.xml')->getActionName(),
        );
        $this->assertSame(
            DataImportController::class.'@inventory',
            Route::getRoutes()->getByName('importaciones.inventario')->getActionName(),
        );
    }

    public function test_each_section_enforces_its_existing_permission(): void
    {
        [$company, $branch, $user] = $this->context([]);
        $session = $this->activeSession($company, $branch);

        foreach (['index', 'imports', 'exports', 'reports'] as $section) {
            $this->actingAs($user)->withSession($session)
                ->get(route("data-center.{$section}"))
                ->assertForbidden();
        }

        [$importCompany, $importBranch, $importUser] = $this->context(['compras.crear']);
        $this->actingAs($importUser)->withSession($this->activeSession($importCompany, $importBranch))
            ->get(route('data-center.imports'))->assertOk();

        [$exportCompany, $exportBranch, $exportUser] = $this->context(['reportes.exportar']);
        $this->actingAs($exportUser)->withSession($this->activeSession($exportCompany, $exportBranch))
            ->get(route('data-center.exports'))->assertOk();

        [$reportCompany, $reportBranch, $reportUser] = $this->context(['reportes.ver']);
        $this->actingAs($reportUser)->withSession($this->activeSession($reportCompany, $reportBranch))
            ->get(route('data-center.reports'))->assertOk();
    }

    public function test_shell_only_shows_sections_and_imports_allowed_to_the_user(): void
    {
        [$company, $branch, $user] = $this->context(['inventario.ver']);
        $session = $this->activeSession($company, $branch);

        $this->actingAs($user)->withSession($session)
            ->get(route('data-center.index'))
            ->assertOk()
            ->assertSee(route('data-center.imports'), false)
            ->assertDontSee(route('data-center.exports'), false)
            ->assertDontSee(route('data-center.reports'), false);

        $this->actingAs($user)->withSession($session)
            ->get(route('data-center.imports'))
            ->assertOk()
            ->assertSee('data-existing-import="inventory"', false)
            ->assertDontSee('data-existing-import="purchase-excel"', false)
            ->assertDontSee('data-existing-import="purchase-xml"', false);
    }

    public function test_shell_is_mobile_first_and_preserves_tablet_and_desktop_layouts(): void
    {
        [$company, $branch, $user] = $this->context([
            'compras.crear',
            'compras.ver',
            'inventario.ver',
            'reportes.exportar',
            'reportes.ver',
        ]);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.index'))
            ->assertOk()
            ->assertSee('grid grid-cols-1 gap-4 md:grid-cols-3', false)
            ->assertSee('grid grid-cols-1 gap-2 sm:grid-cols-3', false)
            ->assertSee('min-h-44', false)
            ->assertSee('min-h-11', false)
            ->assertSee('max-w-6xl', false);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->get(route('data-center.imports'))
            ->assertOk()
            ->assertSee('grid grid-cols-1 gap-4 lg:grid-cols-3', false)
            ->assertSee('w-full', false)
            ->assertSee('sm:w-auto', false);
    }

    public function test_sidebar_has_one_permission_aware_entry_and_legacy_import_url_redirects(): void
    {
        [$company, $branch, $user] = $this->context(['reportes.ver']);
        $session = $this->activeSession($company, $branch);

        $this->actingAs($user)->withSession($session);
        $html = view('components.navigation.sidebar', ['context' => 'sheet'])->render();

        $this->assertSame(1, substr_count($html, route('data-center.index')));
        $this->assertStringContainsString('Centro de Datos', $html);

        $this->get(route('importaciones.index'))
            ->assertRedirect('/centro-de-datos/importar');
    }

    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'Centro de Datos '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => strtoupper(substr(uniqid(), -6)),
            'is_active' => true,
        ]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Datos '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Centro de Datos', 'is_active' => true],
            );
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return [
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ];
    }
}
