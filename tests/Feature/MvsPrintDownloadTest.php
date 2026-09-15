<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvsPrintDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ── ACCESS CONTROL ─────────────────────────────────────────────

    public function test_user_with_permission_can_see_download_section(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => 'https://example.com/MVS-Print-Setup.exe']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'))
            ->assertOk()
            ->assertSee('Descargar MVS Print para Windows');
    }

    public function test_user_without_permission_cannot_access(): void
    {
        [$company, $branch, $user] = $this->ctx(withPermission: false);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'))
            ->assertForbidden();
    }

    // ── BUTTON STATES ──────────────────────────────────────────────

    public function test_download_button_active_when_url_configured(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => 'https://example.com/MVS-Print-Setup.exe']);
        config(['mvsprint.installer.version' => '1.0.0']);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'));

        $response->assertOk();
        $response->assertSee('Descargar MVS Print para Windows');
        $response->assertSee(route('mvs.print.download'));
        $response->assertSee('Versión 1.0.0');
        $response->assertDontSee('Descarga no disponible temporalmente');
    }

    public function test_download_button_disabled_when_no_url(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => '']);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'));

        $response->assertOk();
        $response->assertSee('Descarga no disponible temporalmente');
        $response->assertDontSee(route('mvs.print.download'));
    }

    public function test_version_hidden_when_not_configured(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => 'https://example.com/MVS-Print-Setup.exe']);
        config(['mvsprint.installer.version' => '']);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'));

        $response->assertOk();
        $response->assertSee('Descargar MVS Print para Windows');
        $response->assertSee('Instale MVS Print una sola vez');
        $response->assertDontSee('Versión 2.9.9');
    }

    // ── SECURITY: NO EXPOSE INTERNALS ──────────────────────────────

    public function test_view_does_not_expose_qz_tray_in_download_section(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => 'https://example.com/MVS-Print-Setup.exe']);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'));

        $response->assertOk();
        $response->assertDontSee('qz.io');
        $response->assertDontSee('WebSocket');
        $response->assertDontSee('NSIS');
        $response->assertDontSee('Java');
        $response->assertDontSee('QZ Tray v');
    }

    public function test_view_does_not_expose_filesystem_paths(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => 'https://example.com/MVS-Print-Setup.exe']);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'));

        $response->assertOk();
        $response->assertDontSee('storage/');
        $response->assertDontSee('C:\\');
        $response->assertDontSee('/var/www');
        $response->assertDontSee('mvs-print-installer');
    }

    // ── REDIRECT ───────────────────────────────────────────────────

    public function test_download_route_redirects_when_url_configured(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => 'https://cdn.mvscommerce.com/MVS-Print-Setup.exe']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.download'))
            ->assertRedirect('https://cdn.mvscommerce.com/MVS-Print-Setup.exe');
    }

    public function test_download_route_returns_404_when_no_url(): void
    {
        [$company, $branch, $user] = $this->ctx();

        config(['mvsprint.installer.download_url' => '']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.download'))
            ->assertNotFound();
    }

    // ── MULTITENANT ────────────────────────────────────────────────

    public function test_download_requires_permission(): void
    {
        [$company, $branch, $user] = $this->ctx(withPermission: false);

        config(['mvsprint.installer.download_url' => 'https://example.com/MVS-Print-Setup.exe']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.download'))
            ->assertForbidden();
    }

    // ── HELPERS ────────────────────────────────────────────────────

    private function ctx(bool $withPermission = true): array
    {
        $suffix = bin2hex(random_bytes(4));
        $company = Company::create(['trade_name' => 'Co '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Branch '.$suffix, 'code' => strtoupper(substr($suffix, 0, 4)), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Role '.$suffix, 'is_active' => true]);

        if ($withPermission) {
            $perm = Permission::firstOrCreate(['name' => 'mvs.print.configurar'], ['label' => 'Configurar MVS Print', 'module' => 'MVS Print', 'is_active' => true]);
            $role->permissions()->attach($perm);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function ctxCompany(string $name): array
    {
        $company = Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Branch '.$name, 'code' => strtoupper(substr($name, 0, 4)), 'is_active' => true]);

        return [$company, $branch];
    }
}
