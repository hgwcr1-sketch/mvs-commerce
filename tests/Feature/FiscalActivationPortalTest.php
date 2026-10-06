<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyLicense;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\CompanyFiscalConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FASE 3A: acción "Continuar activación fiscal" en el paso de conexión.
 *
 * Cubre reutilización de datos ya guardados en MVS, permiso de edición,
 * ausencia de secretos, estados humanos de activación y aislamiento
 * multiempresa.
 */
class FiscalActivationPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fiscalContext(): array
    {
        $company = Company::create(['trade_name' => 'F'.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P'.uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function actingUser(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Facturación Electrónica', 'is_active' => true],
            );
            $role->permissions()->attach($permission->id);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        return $user;
    }

    private function enableFiscal(Company $company): void
    {
        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true]);
    }

    public function test_activation_panel_reuses_saved_mvs_data_without_asking_again(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);

        $company->update([
            'identification_type' => '02',
            'identification_number' => '3101678166',
            'legal_name' => 'PANADERIA UNDAMO S.A.',
        ]);

        $service = app(CompanyFiscalConfigService::class);
        $service->updateFiscalData($company, [
            'economic_activity' => '56120',
            'fiscal_branch_code' => '001',
            'fiscal_terminal_code' => '00001',
        ]);
        CompanyFiscalConfig::query()->where('company_id', $company->id)->update(['tax_regime' => 'general']);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $response = $this->get(route('fiscal.setup', ['step' => 'conexion']));

        $response->assertOk()
            ->assertSee('Activación fiscal')
            ->assertSee('Activación pendiente')
            ->assertSee('Continuar activación fiscal')
            ->assertSee('MVS Commerce sigue siendo su sistema principal')
            ->assertSee('PANADERIA UNDAMO S.A.')
            ->assertSee('02 3101678166')
            ->assertSee('56120')
            ->assertSee('001')
            ->assertSee('00001')
            ->assertSee('Régimen general');

        $response->assertDontSee('name="legal_name"', false);
        $response->assertDontSee('name="identification_number"', false);
        $response->assertDontSee('name="economic_activity"', false);
        $response->assertDontSee('name="fiscal_branch_code"', false);
        $response->assertDontSee('name="fiscal_terminal_code"', false);

        $response->assertDontSee('FacturaEnCR', false);
        $response->assertDontSee('facturaencr', false);
    }

    public function test_activation_states_follow_credentials_and_verification(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Activación pendiente');

        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'provider_api_key' => encrypt('efk_READY', false),
            'provider_api_secret' => encrypt('efs_READY', false),
        ]);

        $this->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Verificación pendiente')
            ->assertSee('Verificar activación');

        CompanyFiscalConfig::query()->where('company_id', $company->id)->update(['last_verified_at' => now()]);

        $this->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Listo para facturar')
            ->assertDontSee('>Verificar activación<', false);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_NEW', 'api_secret' => 'efs_NEW']);
        Http::fake(['auth/verify' => Http::response(['error' => 'unauthorized'], 401)]);
        $this->post(route('fiscal.verify'))->assertRedirect(route('fiscal.setup', ['step' => 'verificar']));

        $this->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Continuar en proveedor');
    }

    public function test_activation_action_requires_editing_permission(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);

        $this->get(route('fiscal.activation.continue'))->assertRedirect(route('login'));

        $this->actingUser($company, $branch, ['fiscal.ver']);

        $this->get(route('fiscal.setup', ['step' => 'conexion']))->assertForbidden();
        $this->get(route('fiscal.activation.continue'))->assertForbidden();
    }

    public function test_activation_action_redirects_to_provider_portal_and_hides_secrets(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'provider_api_key' => encrypt('efk_SUPERSECRETVALUE123', false),
            'provider_api_secret' => encrypt('efs_SUPERSECRETPASSWORD456', false),
        ]);

        $portalUrl = (string) config('facturaencr.portal_url');
        $this->assertNotEmpty($portalUrl);

        $step = $this->get(route('fiscal.setup', ['step' => 'conexion']));
        $step->assertOk();
        $step->assertDontSee('efk_SUPERSECRETVALUE123', false);
        $step->assertDontSee('efs_SUPERSECRETPASSWORD456', false);

        $this->get(route('fiscal.activation.continue'))->assertRedirect($portalUrl);
    }

    public function test_activation_panel_is_isolated_per_company(): void
    {
        [$companyA, $branchA] = $this->fiscalContext();
        $this->enableFiscal($companyA);
        $companyA->update([
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'EMPRESA ALPHA S.A.',
        ]);
        app(CompanyFiscalConfigService::class)->updateFiscalData($companyA, ['economic_activity' => '56110']);

        [$companyB, $branchB] = $this->fiscalContext();
        $this->enableFiscal($companyB);
        $this->actingUser($companyB, $branchB, ['fiscal.ver', 'fiscal.editar']);

        $response = $this->get(route('fiscal.setup', ['step' => 'conexion']));

        $response->assertOk()
            ->assertDontSee('EMPRESA ALPHA S.A.')
            ->assertDontSee('3101000000')
            ->assertDontSee('56110')
            ->assertSee('Falta en Paso 1');
    }
}
