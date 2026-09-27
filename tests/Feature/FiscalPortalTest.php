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

    public function test_connection_stores_encrypted_and_masks_output(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'api_key' => 'efk_TESTKEY1234',
            'api_secret' => 'efs_TESTSECRET5678',
        ])->assertRedirect();

        $raw = DB::table('company_fiscal_configs')->where('company_id', $company->id)->first();
        $this->assertNotSame('efk_TESTKEY1234', $raw->provider_api_key);
        $this->assertNotSame('efs_TESTSECRET5678', $raw->provider_api_secret);
        $this->assertSame('efk_TESTKEY1234', decrypt($raw->provider_api_key, false));

        $response = $this->get(route('fiscal.index'));
        $response->assertDontSee('efk_TESTKEY1234');
        $response->assertDontSee('efs_TESTSECRET5678');
        $response->assertSee('••••1234');
    }

    public function test_empty_secret_keeps_existing_and_rotation_resets_verification(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->updateConnection($company, ['api_key' => 'efk_FIRST', 'api_secret' => 'efs_FIRST']);
        CompanyFiscalConfig::query()->where('company_id', $company->id)->update([
            'last_verified_at' => now(), 'last_error_code' => null, 'last_error_message' => null,
        ]);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'api_key' => '',
            'api_secret' => '',
        ])->assertRedirect();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('efk_FIRST', $config->provider_api_key);
        $this->assertNotNull($config->last_verified_at);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'api_key' => 'efk_SECOND',
            'api_secret' => '',
        ])->assertRedirect();

        $config->refresh();
        $this->assertSame('efk_SECOND', $config->provider_api_key);
        $this->assertSame('efs_FIRST', $config->provider_api_secret);
        $this->assertNull($config->last_verified_at);
    }

    public function test_sandbox_and_production_stay_separate(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->updateConnection($company, ['api_key' => 'efk_SANDBOX', 'api_secret' => 'efs_SANDBOX']);

        $this->put(route('fiscal.setup.store', ['step' => 'conexion']), [
            'provider' => 'facturaencr',
            'environment' => 'production',
            'api_key' => 'efk_PROD',
            'api_secret' => 'efs_PROD',
        ])->assertRedirect();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('production', $config->environment);
        $this->assertSame('efk_PROD', $config->provider_api_key);
        $this->assertSame('efs_PROD', $config->provider_api_secret);

        $response = $this->get(route('fiscal.index'));
        $response->assertSee('Producción');
    }

    public function test_verify_ok_marks_ready_without_emitting(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $service = app(CompanyFiscalConfigService::class);
        $service->updateConnection($company, ['api_key' => 'efk_K', 'api_secret' => 'efs_S']);

        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);

        $this->post(route('fiscal.verify'))
            ->assertRedirect(route('fiscal.setup', ['step' => 'verificar']))
            ->assertSessionHasNoErrors();

        $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertNotNull($config->last_verified_at);
        $this->assertNull($config->last_error_code);
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
        $service->updateConnection($company, ['api_key' => 'efk_BAD', 'api_secret' => 'efs_BAD']);

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
        ])->assertRedirect(route('fiscal.index'));

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
}
