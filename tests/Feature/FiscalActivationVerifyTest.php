<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyLicense;
use App\Models\ElectronicDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\CompanyFiscalConfigService;
use App\Services\Fiscal\FiscalConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FASE 3B: verificación automática de activación en el paso de conexión.
 *
 * Cubre los cinco estados humanos, el resumen cuando se está listo, el
 * detalle exacto de lo que falta, el aislamiento por empresa y que la
 * verificación no emite ni consume cuota.
 */
class FiscalActivationVerifyTest extends TestCase
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

    private function completeFiscalData(Company $company, array $overrides = []): void
    {
        $company->update([
            'identification_type' => '02',
            'identification_number' => '3101678166',
            'legal_name' => 'PANADERIA UNDAMO S.A.',
        ]);

        app(CompanyFiscalConfigService::class)->updateFiscalData($company, array_merge([
            'economic_activity' => '56120',
            'fiscal_branch_code' => '001',
            'fiscal_terminal_code' => '00001',
        ], $overrides));
    }

    private function registerKeys(Company $company, string $environment = 'production'): void
    {
        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'provider_api_key' => encrypt('efk_ACTIVATIONKEY123456', false),
            'provider_api_secret' => encrypt('efs_ACTIVATIONSECRET123456', false),
            'environment' => $environment,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function freshSession(Company $company): array
    {
        return ['fiscal_activation_report_'.$company->id => null];
    }

    public function test_ready_state_shows_full_summary_without_emitting(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->completeFiscalData($company);
        $this->registerKeys($company, 'production');

        Http::fake([
            'auth/verify' => Http::response(['ok' => true, 'apiKey' => ['environment' => 'production']], 200),
            'emisores/3101678166/sucursales' => Http::response(['items' => []], 200),
            'certificates' => Http::response(['items' => [['status' => 'active']]], 200),
        ]);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $response = $this->withSession($this->freshSession($company))
            ->get(route('fiscal.setup', ['step' => 'conexion']));

        $response->assertOk()
            ->assertSee('Listo para facturar')
            ->assertSee('Facturación electrónica lista')
            ->assertSee('PANADERIA UNDAMO S.A.')
            ->assertSee('02 3101678166')
            ->assertSee('Producción')
            ->assertSee('Sucursal: 001.')
            ->assertSee('Terminal: 00001.')
            ->assertSee('Certificado activo para su emisor.')
            ->assertSee('Certificado activo')
            ->assertSee('Última verificación')
            ->assertSee('Verificar activación');

        $this->assertSame(0, ElectronicDocument::count());
        $this->assertSame(0, app(FiscalConsumptionService::class)->monthlyUsage($company->id));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'documents'));
    }

    public function test_missing_certificate_blocks_ready_state(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->completeFiscalData($company);
        $this->registerKeys($company, 'production');

        Http::fake([
            'auth/verify' => Http::response(['ok' => true, 'apiKey' => ['environment' => 'production']], 200),
            'emisores/3101678166/sucursales' => Http::response(['items' => []], 200),
            'certificates' => Http::response(['items' => []], 200),
        ]);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->withSession($this->freshSession($company))
            ->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Certificado pendiente')
            ->assertSee('Todavía no hay certificado para su emisor')
            ->assertDontSee('Facturación electrónica lista', false);
    }

    public function test_wrong_key_environment_blocks_ready_state(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->completeFiscalData($company);
        $this->registerKeys($company, 'production');

        Http::fake([
            'auth/verify' => Http::response(['ok' => true, 'apiKey' => ['environment' => 'sandbox']], 200),
            'emisores/3101678166/sucursales' => Http::response(['items' => []], 200),
        ]);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->withSession($this->freshSession($company))
            ->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Configuración incompleta')
            ->assertSee('deben coincidir')
            ->assertDontSee('Facturación electrónica lista', false);
    }

    public function test_missing_branch_and_terminal_block_ready_state(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->completeFiscalData($company, ['fiscal_branch_code' => null, 'fiscal_terminal_code' => null]);
        $this->registerKeys($company, 'sandbox');

        Http::fake([
            'auth/verify' => Http::response(['ok' => true, 'apiKey' => ['environment' => 'sandbox']], 200),
            'emisores/3101678166/sucursales' => Http::response(['items' => []], 200),
        ]);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->withSession($this->freshSession($company))
            ->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Configuración incompleta')
            ->assertSee('Falta la sucursal')
            ->assertSee('Falta la terminal')
            ->assertDontSee('Facturación electrónica lista', false);
    }

    public function test_activation_report_is_isolated_per_company(): void
    {
        [$companyA, $branchA] = $this->fiscalContext();
        $this->enableFiscal($companyA);
        $this->completeFiscalData($companyA);
        $this->registerKeys($companyA, 'production');

        Http::fake([
            'auth/verify' => Http::response(['ok' => true, 'apiKey' => ['environment' => 'production']], 200),
            'emisores/3101678166/sucursales' => Http::response(['items' => []], 200),
            'certificates' => Http::response(['items' => [['status' => 'active']]], 200),
        ]);

        $this->actingUser($companyA, $branchA, ['fiscal.ver', 'fiscal.editar']);
        $this->withSession($this->freshSession($companyA))
            ->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Listo para facturar');

        [$companyB, $branchB] = $this->fiscalContext();
        $this->enableFiscal($companyB);
        $this->actingUser($companyB, $branchB, ['fiscal.ver', 'fiscal.editar']);

        $this->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertDontSee('PANADERIA UNDAMO S.A.')
            ->assertDontSee('3101678166')
            ->assertDontSee('Listo para facturar')
            ->assertSee('Activación pendiente');
    }

    public function test_verification_does_not_emit_or_consume_quota(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->completeFiscalData($company);
        $this->registerKeys($company, 'production');

        Http::fake([
            'auth/verify' => Http::response(['ok' => true, 'apiKey' => ['environment' => 'production']], 200),
            'emisores/3101678166/sucursales' => Http::response(['items' => []], 200),
            'certificates' => Http::response(['items' => [['status' => 'active']]], 200),
        ]);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $usageBefore = app(FiscalConsumptionService::class)->monthlyUsage($company->id);

        $this->post(route('fiscal.activation.verify'))
            ->assertRedirect(route('fiscal.setup', ['step' => 'conexion']))
            ->assertSessionHas('status');

        $this->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Listo para facturar')
            ->assertSee('Facturación electrónica lista');

        $this->assertSame(0, ElectronicDocument::count());
        $this->assertSame($usageBefore, app(FiscalConsumptionService::class)->monthlyUsage($company->id));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'documents'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'emision'));
        Http::assertSentCount(3);
    }

    public function test_verification_never_exposes_secrets(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->completeFiscalData($company);
        $this->registerKeys($company, 'sandbox');

        Http::fake([
            'auth/verify' => Http::response(['ok' => true, 'apiKey' => ['environment' => 'sandbox']], 200),
            'emisores/3101678166/sucursales' => Http::response(['items' => []], 200),
        ]);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $step = $this->withSession($this->freshSession($company))
            ->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk();

        $step->assertDontSee('efk_ACTIVATIONKEY123456', false);
        $step->assertDontSee('efs_ACTIVATIONSECRET123456', false);
        $step->assertDontSee('FacturaEnCR', false);
        $step->assertDontSee('facturaencr', false);

        $action = $this->post(route('fiscal.activation.verify'))
            ->assertRedirect(route('fiscal.setup', ['step' => 'conexion']));

        $action->assertDontSee('efk_ACTIVATIONKEY123456', false);
        $action->assertDontSee('efs_ACTIVATIONSECRET123456', false);
    }
}
