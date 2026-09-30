<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\ElectronicDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Customer;
use App\Models\User;
use App\Services\Fiscal\CompanyFiscalConfigService;
use App\Services\Fiscal\FiscalManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FiscalPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fiscalContext(): array
    {
        $company = Company::create(['trade_name' => 'F' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P' . uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function actingUser(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R' . uniqid(), 'is_active' => true]);

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

    private function enableFiscal(Company $company, array $attributes = []): void
    {
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        \App\Models\CompanyLicense::query()->where('company_id', $company->id)->update(array_merge([
            'fiscal_enabled' => true,
        ], $attributes));
    }

    public function test_guest_and_unauthorized_are_blocked(): void
    {
        [$company, $branch] = $this->fiscalContext();

        $this->get(route('fiscal.index'))->assertRedirect(route('login'));

        $this->actingUser($company, $branch, []);
        $this->get(route('fiscal.index'))->assertForbidden();
        $this->get(route('fiscal.setup', ['step' => 'datos']))->assertForbidden();
    }

    public function test_index_shows_incomplete_for_fresh_company(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 10]);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $response = $this->get(route('fiscal.index'));

        $response->assertOk();
        $response->assertSee('Incompleto');
        $response->assertSee('Pruebas');
        $response->assertSee('Facturación Electrónica');
    }

    public function test_index_explains_disabled_module(): void
    {
        [$company, $branch] = $this->fiscalContext();
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $response = $this->get(route('fiscal.index'));

        $response->assertOk();
        $response->assertSee('no está habilitado');

        $sale = $this->completedSale($company, $branch, $this->actingUser($company, $branch, ['fiscal.ver']));
        $this->assertFalse(app(\App\Services\Fiscal\PosEmissionDispatcher::class)->forSale($sale));
    }

    public function test_identity_wizard_updates_company(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->get(route('fiscal.setup', ['step' => 'datos']))->assertOk();

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'Demo Fiscal S.A.',
            'email' => 'fiscal@demo.cr',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'conexion']));

        $company->refresh();
        $this->assertSame('3101000000', $company->identification_number);
        $this->assertSame('Demo Fiscal S.A.', $company->legal_name);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '',
            'legal_name' => '',
        ])->assertSessionHasErrors(['identification_number', 'legal_name']);
    }

    public function test_connection_stages_encrypted_and_masks_output(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
            'api_key' => 'efk_TESTKEY1234',
            'api_secret' => 'efs_TESTSECRET5678',
        ])->assertRedirect();

        $raw = DB::table('company_fiscal_configs')->where('company_id', $company->id)->first();
        $this->assertNotSame('efk_TESTKEY1234', $raw->pending_api_key);
        $this->assertSame('efk_TESTKEY1234', decrypt($raw->pending_api_key, false));
        $this->assertNull($raw->provider_api_key);

        $response = $this->get(route('fiscal.index'));
        $response->assertDontSee('efk_TESTKEY1234');
        $response->assertDontSee('efs_TESTSECRET5678');
        $response->assertSee('Actualización requerida');
    }

    public function test_empty_secret_stages_nothing_and_keeps_active(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_FIRST', 'api_secret' => 'efs_FIRST']);
        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'provider_api_key' => encrypt('efk_FIRST', false),
            'provider_api_secret' => encrypt('efs_FIRST', false),
            'pending_api_key' => null, 'pending_api_secret' => null,
            'last_verified_at' => now(),
        ]);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
            'api_key' => '',
            'api_secret' => '',
        ])->assertRedirect();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('efk_FIRST', $config->provider_api_key);
        $this->assertNotNull($config->last_verified_at);
        $this->assertFalse($config->hasPending());
    }

    public function test_failed_verification_keeps_active_connection(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_GOOD', 'api_secret' => 'efs_GOOD']);
        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'provider_api_key' => encrypt('efk_GOOD', false),
            'provider_api_secret' => encrypt('efs_GOOD', false),
            'pending_api_key' => null, 'pending_api_secret' => null,
            'last_verified_at' => now(),
        ]);

        $service->stageConnection($company, ['api_key' => 'efk_BAD', 'api_secret' => 'efs_BAD']);
        Http::fake(['auth/verify' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->post(route('fiscal.verify'))->assertRedirect(route('fiscal.setup', ['step' => 'verificar']));

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('efk_GOOD', $config->provider_api_key);
        $this->assertTrue($config->hasPending());
        $this->assertSame('invalid_credentials', $config->last_error_code);

        $this->post(route('fiscal.connection.discard'))->assertRedirect();

        $config->refresh();
        $this->assertFalse($config->hasPending());
        $this->assertSame('efk_GOOD', $config->provider_api_key);
    }

    public function test_successful_rotation_activates_pending(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_NEW', 'api_secret' => 'efs_NEW']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);

        $this->post(route('fiscal.verify'))->assertRedirect(route('fiscal.setup', ['step' => 'preferencias']));

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('efk_NEW', $config->provider_api_key);
        $this->assertFalse($config->hasPending());
        $this->assertNotNull($config->last_verified_at);

        $this->assertDatabaseHas('fiscal_config_audits', [
            'company_id' => $company->id,
            'change_type' => \App\Models\FiscalConfigAudit::TYPE_CONNECTION_ACTIVATED,
        ]);
    }

    public function test_disconnect_clears_credentials_and_keeps_history(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $doc = ElectronicDocument::create([
            'company_id' => $company->id, 'provider' => 'facturaencr', 'document_type' => '01',
            'environment' => 'sandbox', 'idempotency_key' => md5('hist' . uniqid()), 'status' => 'accepted',
        ]);
        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_X', 'api_secret' => 'efs_X']);

        $this->post(route('fiscal.disconnect'), ['disconnect_confirm' => '1'])->assertRedirect(route('fiscal.index'));

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertNull($config->provider_api_key);
        $this->assertFalse($config->hasCredentials());
        $this->assertNotNull(ElectronicDocument::find($doc->id));
        $this->assertDatabaseHas('fiscal_config_audits', [
            'company_id' => $company->id,
            'change_type' => \App\Models\FiscalConfigAudit::TYPE_DISCONNECTED,
        ]);

        $this->post(route('fiscal.disconnect'), [])->assertSessionHasErrors('disconnect_confirm');
    }

    public function test_production_requires_explicit_confirmation(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'production',
            'api_key' => 'efk_P',
            'api_secret' => 'efs_P',
        ])->assertSessionHasErrors('production_confirm');

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'production',
            'production_confirm' => '1',
            'api_key' => 'efk_P',
            'api_secret' => 'efs_P',
        ])->assertRedirect();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('production', $config->pending_environment);
        $this->assertSame('sandbox', $config->environment);
    }

    public function test_sandbox_and_production_stay_separate(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_SANDBOX', 'api_secret' => 'efs_SANDBOX']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'))->assertRedirect();

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'production',
            'production_confirm' => '1',
            'api_key' => 'efk_PROD',
            'api_secret' => 'efs_PROD',
        ])->assertRedirect();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('sandbox', $config->environment);
        $this->assertSame('efk_SANDBOX', $config->provider_api_key);
        $this->assertSame('production', $config->pending_environment);
        $this->assertSame('efk_PROD', $config->pending_api_key);

        $response = $this->get(route('fiscal.index'));
        $response->assertDontSee('Producción');
        $response->assertSee('Actualización requerida');
    }

    public function test_verify_ok_marks_ready_without_emitting(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);

        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);

        $this->post(route('fiscal.verify'))
            ->assertRedirect(route('fiscal.setup', ['step' => 'preferencias']))
            ->assertSessionHasNoErrors();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertNotNull($config->last_verified_at);
        $this->assertNull($config->last_error_code);
        $this->assertFalse($config->hasPending());
        $this->assertSame(0, ElectronicDocument::where('company_id', $company->id)->count());
        $this->assertSame(CompanyFiscalConfigService::STATUS_READY, $service->status($company->fresh()));
    }

    public function test_verify_rejected_marks_attention_without_emitting(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_BAD', 'api_secret' => 'efs_BAD']);

        Http::fake(['auth/verify' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->post(route('fiscal.verify'))->assertRedirect();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertNull($config->last_verified_at);
        $this->assertSame('invalid_credentials', $config->last_error_code);
        $this->assertSame(CompanyFiscalConfigService::STATUS_ATTENTION, $service->status($company->fresh()));
        $this->assertSame(0, ElectronicDocument::where('company_id', $company->id)->count());
    }

    public function test_provider_resolution_per_company_with_safe_fallback(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $manager = app(FiscalManager::class);

        $this->assertInstanceOf(\App\Services\Facturaencr\FacturaencrProvider::class, $manager->providerForCompany($company));

        CompanyFiscalConfig::query()->where('company_id', $company->id)->update(['provider' => 'inventado']);

        $this->assertInstanceOf(\App\Services\Facturaencr\FacturaencrProvider::class, $manager->providerForCompany($company));
    }

    public function test_license_quota_is_readonly_from_portal(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 10]);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'api_key' => 'efk_QUOTA',
            'api_secret' => 'efs_QUOTA',
            'fiscal_enabled' => false,
            'fiscal_monthly_quota' => 999,
        ])->assertRedirect();

        $license = \App\Models\CompanyLicense::where('company_id', $company->id)->first();
        $this->assertTrue((bool) $license->fiscal_enabled);
        $this->assertSame(10, (int) $license->fiscal_monthly_quota);
    }

    public function test_history_lists_all_types_and_rejected_attempts(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        foreach (['01' => 'accepted', '04' => 'accepted', '03' => 'rejected', '02' => 'accepted'] as $type => $status) {
            ElectronicDocument::create([
                'company_id' => $company->id, 'provider' => 'facturaencr', 'document_type' => $type,
                'environment' => 'sandbox', 'idempotency_key' => md5($company->id . $type . uniqid()),
                'status' => $status, 'attempt_number' => $type === '03' ? 2 : 1,
                'last_error_code' => $status === 'rejected' ? 'HACIENDA_REJECTED' : null,
            ]);
        }

        $response = $this->get(route('fiscal.history'));
        $response->assertOk();
        $response->assertSee('Factura electrónica');
        $response->assertSee('Tiquete electrónico');
        $response->assertSee('Nota de crédito');
        $response->assertSee('Nota de débito');
        $response->assertSee('HACIENDA_REJECTED');

        $filtered = $this->get(route('fiscal.history', ['type' => '03']));
        $filtered->assertOk();
        $filtered->assertSee('Nota de crédito');
        $filtered->assertDontSee('Tiquete electrónico');
    }

    public function test_cross_company_documents_stay_isolated(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $other = Company::create(['trade_name' => 'O' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        ElectronicDocument::create([
            'company_id' => $other->id, 'provider' => 'facturaencr', 'document_type' => '01',
            'environment' => 'sandbox', 'idempotency_key' => md5('other' . uniqid()), 'status' => 'accepted',
            'clave' => 'CLAVE-AJENA-50xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $this->get(route('fiscal.history'))->assertDontSee('CLAVE-AJENA-50');
        $this->get(route('fiscal.index'))->assertOk();
    }

    public function test_auto_emit_preference_drives_pos_dispatcher(): void
    {
        config(['fiscal.emission.auto_emit' => false]);
        Queue::fake();

        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $user = $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);
        $sale = $this->completedSale($company, $branch, $user);

        $dispatcher = app(\App\Services\Fiscal\PosEmissionDispatcher::class);
        $this->assertFalse($dispatcher->forSale($sale));

        $this->put(route('fiscal.setup.store', ['step' => 'preferencias']), [
            'default_document' => '01',
            'auto_emit_enabled' => '1',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'confirmacion']));

        $this->assertTrue($dispatcher->forSale($sale));
        Queue::assertPushed(\App\Jobs\EmitElectronicDocument::class);
    }

    public function test_portal_falls_back_safe_without_config_row(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);
        CompanyFiscalConfig::query()->where('company_id', $company->id)->delete();

        $this->get(route('fiscal.index'))->assertOk()->assertSee('Incompleto');

        $manager = app(FiscalManager::class);
        $this->assertInstanceOf(\App\Services\Facturaencr\FacturaencrProvider::class, $manager->providerForCompany($company));
    }

    private function completedSale(Company $company, Branch $branch, User $user): Sale
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'C', 'customer_type' => 'individual', 'is_active' => true]);

        return Sale::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'customer_id' => $customer->id, 'sale_number' => 'POS-' . strtoupper(\Illuminate\Support\Str::random(12)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE, 'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED, 'currency_code' => 'CRC', 'exchange_rate' => 1,
            'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 0, 'total' => 1000,
            'paid_total' => 1000, 'balance_due' => 0, 'completed_at' => now(),
        ]);
    }

    public function test_permission_seeder_registers_fiscal_permissions(): void
    {
        $this->assertFalse(Permission::where('name', 'fiscal.ver')->exists());

        (new \Database\Seeders\PermissionSeeder())->run();

        $this->assertTrue(Permission::where('name', 'fiscal.ver')->where('is_active', true)->exists());
        $this->assertTrue(Permission::where('name', 'fiscal.editar')->where('is_active', true)->exists());

        (new \Database\Seeders\PermissionSeeder())->run();

        $this->assertSame(1, Permission::where('name', 'fiscal.ver')->count());
    }

    public function test_tenant_views_hide_provider_brand(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $doc = ElectronicDocument::create([
            'company_id' => $company->id, 'provider' => 'facturaencr', 'document_type' => '03',
            'environment' => 'sandbox', 'idempotency_key' => md5('brand' . uniqid()), 'status' => 'rejected',
            'attempt_number' => 2, 'last_error_code' => 'HACIENDA_REJECTED',
        ]);

        $forbidden = ['FacturaEnCR', 'proveedor facturaencr', 'facturaencr', 'Proveedor tecnico'];

        $urls = [
            route('fiscal.index'),
            route('fiscal.setup', ['step' => 'datos']),
            route('fiscal.setup', ['step' => 'conexion']),
            route('fiscal.setup', ['step' => 'verificar']),
            route('fiscal.history'),
            route('fiscal.documents.show', $doc),
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertOk();

            foreach ($forbidden as $word) {
                $response->assertDontSee($word, false);
            }
        }
    }
    public function test_master_shows_complete_or_manage_action(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $incomplete = $this->get(route('fiscal.index'));
        $incomplete->assertOk();
        $incomplete->assertSee('Configuración incompleta');
        $incomplete->assertSee('Completar configuración');
        $incomplete->assertSee(route('fiscal.setup', ['step' => 'datos']), false);

        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $ready = $this->get(route('fiscal.index'));
        $ready->assertSee('Facturación electrónica configurada');
        $ready->assertSee('Administrar configuración');
        $ready->assertSee('Actualizar conexión');
        $ready->assertSee('Centro de Facturación Electrónica');
        $ready->assertSee('Verificada y lista para emitir');
        $ready->assertDontSee('Sin verificar');
    }

    public function test_viewer_without_edit_hides_actions(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $response = $this->get(route('fiscal.index'));
        $response->assertOk();
        $response->assertDontSee('Completar configuración');
        $response->assertDontSee('Administrar configuración');
        $response->assertDontSee('Actualizar conexión');
        $response->assertDontSee('Resolver');
        $response->assertSee('Incompleto');
        $response->assertSee('Pruebas');
    }

    public function test_viewer_edit_routes_stay_forbidden(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $this->get(route('fiscal.setup', ['step' => 'datos']))->assertForbidden();
        $this->get(route('fiscal.setup', ['step' => 'conexion']))->assertForbidden();
        $this->get(route('fiscal.series'))->assertOk();
        $this->get(route('fiscal.switch'))->assertForbidden();
        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [])->assertForbidden();
        $this->post(route('fiscal.verify'))->assertForbidden();
        $this->post(route('fiscal.disconnect'), ['disconnect_confirm' => '1'])->assertForbidden();
    }

    public function test_editor_resolver_targets_never_403(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        foreach (['datos', 'conexion', 'verificar', 'preferencias', 'confirmacion'] as $step) {
            $this->get(route('fiscal.setup', ['step' => $step]))->assertOk();
        }

        $this->get(route('fiscal.series'))->assertOk();
        $this->get(route('fiscal.history'))->assertOk();
        $this->get(route('fiscal.switch'))->assertForbidden();
    }

    public function test_master_hides_provider_and_masked_secret(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $response = $this->get(route('fiscal.index'));
        $response->assertOk();
        $response->assertDontSee('>Proveedor<');
        $response->assertDontSee('facturaencr');
        $response->assertDontSee('••••');
        $response->assertSee('Conexión fiscal');
        $response->assertSee('Verificada');
        $response->assertSee('Configuración avanzada');
        $response->assertSee('Series fiscales');
        $response->assertSee('Normalmente no necesita modificar esta información');
    }

    public function test_series_empty_is_neutral_not_satisfactory(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $response = $this->get(route('fiscal.index'));
        $response->assertOk();
        $response->assertSee('Las series se registrarán al emitir.');
    }

    public function test_consumption_card_and_badges(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 50]);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        ElectronicDocument::create([
            'company_id' => $company->id, 'provider' => 'facturaencr', 'document_type' => '01',
            'environment' => 'sandbox', 'idempotency_key' => md5('c1' . uniqid()), 'status' => 'accepted',
            'consecutivo' => '00100001010000000001',
        ]);
        \App\Models\FiscalConsumption::create([
            'company_id' => $company->id,
            'electronic_document_id' => ElectronicDocument::latest('id')->first()->id,
            'document_type' => '01', 'period' => now()->startOfMonth()->toDateString(),
            'classification' => 'included',
        ]);

        $response = $this->get(route('fiscal.index'));
        $response->assertOk();
        $response->assertSee('1 de 50 utilizados');
        $response->assertSee('49 disponibles');
        $response->assertSee('progressbar');
        $response->assertSee('Aceptado');
        $response->assertSee('Ver historial');
    }

    public function test_wizard_title_follows_configuration_state(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->get(route('fiscal.setup', ['step' => 'datos']))
            ->assertOk()
            ->assertSee('Conectar facturación')
            ->assertDontSee('Configuración de Facturación Electrónica');

        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $this->get(route('fiscal.setup', ['step' => 'datos']))
            ->assertOk()
            ->assertSee('Configuración de Facturación Electrónica')
            ->assertDontSee('Conectar facturación');
    }

    public function test_step_one_valid_goes_to_step_two_and_invalid_stays(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'Demo S.A.',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'conexion']));

        $this->assertSame('3101000000', $company->fresh()->identification_number);
        $this->assertStringContainsString(
            'Paso 2',
            $this->get(route('fiscal.setup', ['step' => 'conexion']))->getContent()
        );

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '',
            'legal_name' => '',
        ])->assertSessionHasErrors(['identification_number', 'legal_name']);

        $this->assertSame('3101000000', $company->fresh()->identification_number);
    }

    public function test_wizard_chain_and_back_links(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'Demo S.A.',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'conexion']));

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
            'api_key' => 'efk_K',
            'api_secret' => 'efs_S',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'verificar']));

        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'))->assertRedirect(route('fiscal.setup', ['step' => 'preferencias']));

        $this->put(route('fiscal.setup.store', ['step' => 'preferencias']), [
            'default_document' => '04',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'confirmacion']));

        $this->assertSame('04', \App\Models\CompanyFiscalConfig::where('company_id', $company->id)->first()->default_document);

        $this->post(route('fiscal.setup.finish'))->assertRedirect(route('fiscal.index'));

        foreach (['conexion', 'verificar', 'preferencias', 'confirmacion'] as $step) {
            $this->get(route('fiscal.setup', ['step' => $step]))->assertSee('Anterior');
        }
    }

    public function test_step_four_goes_to_review_and_finish_closes(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $this->put(route('fiscal.setup.store', ['step' => 'preferencias']), [
            'default_document' => '04',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'confirmacion']));

        $review = $this->get(route('fiscal.setup', ['step' => 'confirmacion']));
        $review->assertOk();
        $review->assertSee('Revisar y finalizar');
        $review->assertSee('Demo S.A.');
        $review->assertSee('Tiquete electrónico');
        $review->assertSee('Confirmar y finalizar');

        $this->post(route('fiscal.setup.finish'))
            ->assertRedirect(route('fiscal.index'))
            ->assertSessionHas('status');

        $this->get(route('fiscal.setup.finish'))->assertStatus(405);
    }

    public function test_pending_hides_old_verification_date(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_A', 'api_secret' => 'efs_A']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $service->stageConnection($company, ['api_key' => 'efk_B', 'api_secret' => 'efs_B']);

        $html = $this->get(route('fiscal.index'))->getContent();
        $this->assertStringContainsString('Verificación pendiente', $html);
    }

    public function test_wizard_texts_review_location_rule_and_activity(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $conexion = $this->get(route('fiscal.setup', ['step' => 'conexion']))->getContent();
        $this->assertStringContainsString('Ingrese las credenciales de conexión para habilitar la facturación electrónica.', $conexion);
        $this->assertStringNotContainsString('conectar MVS Commerce con Hacienda', $conexion);
        $this->assertStringNotContainsString('La conexión la administra MVS', $conexion);

        $verificar = $this->get(route('fiscal.setup', ['step' => 'verificar']))->getContent();
        $this->assertStringContainsString('correctamente configurada,', $verificar);
        $this->assertStringContainsString('sin emitir ningún documento ni consumir cuota', $verificar);
        $this->assertStringNotContainsString('Comprobamos la conexión con Hacienda', $verificar);
        $this->assertStringNotContainsString('FacturaEnCR', $verificar);

        $prefs = $this->get(route('fiscal.setup', ['step' => 'preferencias']))->getContent();
        $this->assertStringContainsString('al completar una venta en el POS', $prefs);
        $this->assertStringContainsString('Guardar y continuar', $prefs);
        $this->assertStringNotContainsString('Guardar y terminar', $prefs);
        $this->assertSame(2, substr_count($prefs, 'name="default_document"'));
        $this->assertStringContainsString('value="01" checked', $prefs);
        $this->assertStringContainsString('value="04"', $prefs);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $review = $this->get(route('fiscal.setup', ['step' => 'confirmacion']))->getContent();
        $this->assertStringContainsString('Actividad económica', $review);
        $this->assertStringContainsString('Ubicación fiscal', $review);
        $this->assertStringContainsString('Sucursal / Terminal', $review);
        $this->assertMatchesRegularExpression('#<dt>Ubicación fiscal</dt><dd[^>]*>Pendiente</dd>#', $review);
        $this->assertMatchesRegularExpression('#<dt>Conexión fiscal</dt><dd[^>]*>Verificada</dd>#', $review);
        $this->assertStringNotContainsString('facturaencr', $review);
        $this->assertStringNotContainsString('efk_K', $review);
        $this->assertStringNotContainsString('efs_S', $review);

        $index = $this->get(route('fiscal.index'))->getContent();
        $this->assertStringContainsString('Actividad con Hacienda', $index);
        $this->assertStringContainsString('Aún no se han enviado documentos.', $index);
        $this->assertStringNotContainsString('Sin comunicaciones todavía', $index);
        $this->assertStringNotContainsString('Última comunicación con Hacienda', $index);
        $this->assertStringNotContainsString('facturaencr', $index);
        $this->assertStringNotContainsString('efk_K', $index);
        $this->assertStringNotContainsString('efs_S', $index);

        $this->assertSame(CompanyFiscalConfigService::STATUS_READY, $service->status($company->fresh()));
        $this->assertNull($company->fresh()->province_id);
    }

    public function test_emitter_location_is_informational_not_a_blocker(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'Demo S.A.',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'conexion']));

        $this->assertNull($company->fresh()->province_id);
        $this->assertNull($company->fresh()->canton_id);
        $this->assertNull($company->fresh()->district_id);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $this->assertSame(CompanyFiscalConfigService::STATUS_READY, $service->status($company->fresh()));

        $review = $this->get(route('fiscal.setup', ['step' => 'confirmacion']))->getContent();
        $this->assertMatchesRegularExpression('#<dt>Ubicación fiscal</dt><dd[^>]*>Pendiente</dd>#', $review);

        $country = \App\Models\Country::create([
            'name' => 'Costa Rica ' . uniqid(), 'iso2' => strtoupper(substr(md5(uniqid()), 0, 2)),
            'iso3' => strtoupper(substr(md5(uniqid()), 0, 3)), 'phone_code' => '+506',
            'currency' => 'CRC', 'currency_symbol' => '₡', 'is_default' => false, 'is_active' => true,
        ]);
        $province = \App\Models\Province::create(['country_id' => $country->id, 'code' => '1', 'name' => 'San José', 'is_active' => true]);
        $canton = \App\Models\Canton::create(['province_id' => $province->id, 'code' => '101', 'name' => 'San José', 'is_active' => true]);
        $district = \App\Models\District::create(['province_id' => $province->id, 'canton_id' => $canton->id, 'code' => '10101', 'name' => 'Carmen', 'is_active' => true]);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'Demo S.A.',
            'province_id' => $province->id,
            'canton_id' => $canton->id,
            'district_id' => $district->id,
        ])->assertRedirect(route('fiscal.setup', ['step' => 'conexion']));

        $complete = $this->get(route('fiscal.setup', ['step' => 'confirmacion']))->getContent();
        $this->assertMatchesRegularExpression('#<dt>Ubicación fiscal</dt><dd[^>]*>Completa</dd>#', $complete);
    }

    public function test_master_never_claims_ready_without_mandatory_identity(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '',
            'legal_name' => '',
        ])->assertSessionHasErrors(['identification_number', 'legal_name']);

        $service = app(CompanyFiscalConfigService::class);
        $service->stageConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);
        $this->post(route('fiscal.verify'));

        $this->assertSame(CompanyFiscalConfigService::STATUS_INCOMPLETE, $service->status($company->fresh()));

        $index = $this->get(route('fiscal.index'))->getContent();
        $this->assertStringContainsString('Configuración incompleta', $index);
        $this->assertStringContainsString('Faltan datos fiscales de la empresa.', $index);
        $this->assertStringNotContainsString('Verificada y lista para emitir', $index);
    }

    public function test_master_has_single_series_access(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $html = $this->get(route('fiscal.index'))->getContent();
        $this->assertSame(1, substr_count($html, route('fiscal.series')));
        $this->assertStringNotContainsString('>Series<', $html);
        $this->assertStringContainsString('Series fiscales', $html);
    }

    public function test_new_company_fiscal_wizard_starts_fresh(): void
    {
        [$company, $branch] = $this->fiscalContext();
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $response = $this->get(route('fiscal.setup', ['step' => 'datos']));

        $response->assertOk();

        $html = $response->getContent();

        // Identification type, province, canton and district start empty for a brand-new company
        $this->assertStringContainsString('<option value="">Elegir…</option>', $html);
        $this->assertStringNotContainsString('value="01" selected', $html);
        $this->assertStringNotContainsString('value="02" selected', $html);

        $provinceStart = strpos($html, 'id="province_id"');
        $provinceBlock = substr($html, $provinceStart, strpos($html, '</select>', $provinceStart) - $provinceStart);
        $this->assertStringNotContainsString('selected', $provinceBlock);

        // Address stays empty when the company has no stored address
        $this->assertStringContainsString('value="" placeholder="Dirección exacta"', $html);
        $this->assertStringNotContainsString('Test Address', $html);

        // Economic activity placeholder should be present
        $this->assertStringContainsString('Ej. 1071.9', $html);
    }

    public function test_identification_type_dropdown_has_codes_01_to_05(): void
    {
        [$company, $branch] = $this->fiscalContext();
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $response = $this->get(route('fiscal.setup', ['step' => 'datos']));

        $response->assertOk();

        $html = $response->getContent();

        foreach (['01', '02', '03', '04', '05'] as $code) {
            $this->assertStringContainsString('value="' . $code . '"', $html);
        }

        foreach (['Cédula Física', 'Cédula Jurídica', 'DIMEX', 'NITE', 'Extranjero no domiciliado'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertStringContainsString('<option value="">Elegir…</option>', $html);
    }

    public function test_economic_activity_help_text_is_present(): void
    {
        [$company, $branch] = $this->fiscalContext();
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $response = $this->get(route('fiscal.setup', ['step' => 'datos']));

        $response->assertOk();
        $response->assertSee('actividad económica registrada por su empresa ante Hacienda');
        $response->assertSee('Este campo no es el código CABYS de un producto.');
    }

    public function test_connection_requires_credentials_for_new_company(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
        ])->assertSessionHasErrors(['api_key', 'api_secret']);

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertNotNull($config);
        $this->assertNull($config->pending_api_key);
        $this->assertNull($config->pending_api_secret);
        $this->assertNull($config->provider_api_key);

        $errorBag = session('errors');
        $this->assertNotNull($errorBag);
        $this->assertStringContainsString('Registre la llave de conexión', $errorBag->getBag('default')->first('api_key'));
        $this->assertStringContainsString('Registre el secreto de conexión', $errorBag->getBag('default')->first('api_secret'));

        // Sesiones JSON guardan los errores como arreglo; se reinyecta en ese formato
        // porque las pruebas no reenvían la cookie de sesión entre peticiones.
        $this->withSession(['errors' => ['default' => [
            'format' => ':message',
            'messages' => [
                'api_key' => $errorBag->getBag('default')->get('api_key'),
                'api_secret' => $errorBag->getBag('default')->get('api_secret'),
            ],
        ]]])->get(route('fiscal.setup', ['step' => 'conexion']))
            ->assertOk()
            ->assertSee('Registre la llave de conexión')
            ->assertSee('Registre el secreto de conexión')
            ->assertDontSee('efk_');
    }

    public function test_connection_stages_both_credentials_for_new_company(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
            'api_key' => 'efk_INITIAL',
            'api_secret' => 'efs_INITIAL',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'verificar']));

        $raw = DB::table('company_fiscal_configs')->where('company_id', $company->id)->first();
        $this->assertSame('efk_INITIAL', decrypt($raw->pending_api_key, false));
        $this->assertSame('efs_INITIAL', decrypt($raw->pending_api_secret, false));
        $this->assertNull($raw->provider_api_key);
        $this->assertNull($raw->provider_api_secret);
    }

    public function test_connection_empty_input_keeps_active_credentials(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        app(CompanyFiscalConfigService::class)->ensure($company);
        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'provider_api_key' => encrypt('efk_ACTIVE', false),
            'provider_api_secret' => encrypt('efs_ACTIVE', false),
            'last_verified_at' => now(),
        ]);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
            'api_key' => '',
            'api_secret' => '',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'verificar']));

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('efk_ACTIVE', $config->provider_api_key);
        $this->assertSame('efs_ACTIVE', $config->provider_api_secret);
        $this->assertNull($config->pending_api_key);
        $this->assertNull($config->pending_api_secret);
        $this->assertFalse($config->hasPending());
    }

    public function test_staged_rotation_survives_empty_restaging(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        app(CompanyFiscalConfigService::class)->ensure($company);
        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'provider_api_key' => encrypt('efk_ACTIVE', false),
            'provider_api_secret' => encrypt('efs_ACTIVE', false),
            'last_verified_at' => now(),
        ]);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
            'api_key' => 'efk_ROTATED',
            'api_secret' => 'efs_ROTATED',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'verificar']));

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'environment' => 'sandbox',
            'api_key' => '',
            'api_secret' => '',
        ])->assertRedirect(route('fiscal.setup', ['step' => 'verificar']));

        $raw = DB::table('company_fiscal_configs')->where('company_id', $company->id)->first();
        $this->assertSame('efk_ACTIVE', decrypt($raw->provider_api_key, false));
        $this->assertSame('efk_ROTATED', decrypt($raw->pending_api_key, false));
        $this->assertSame('efs_ROTATED', decrypt($raw->pending_api_secret, false));
    }
}
