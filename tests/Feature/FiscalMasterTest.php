<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ElectronicDocument;
use App\Models\FiscalDocumentCustody;
use App\Models\FiscalSeries;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Fiscal\FiscalSeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FiscalMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'M' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
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

    private function enableFiscal(Company $company): void
    {
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        \App\Models\CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true, 'fiscal_monthly_quota' => 50]);
    }

    private function acceptedDoc(Company $company, ?int $saleId, string $type, string $consecutivo, string $status = 'accepted'): ElectronicDocument
    {
        return ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $saleId, 'provider' => 'facturaencr',
            'document_type' => $type, 'environment' => 'sandbox', 'attempt_number' => 1,
            'idempotency_key' => md5($company->id . $type . $consecutivo . uniqid()), 'status' => $status,
            'clave' => str_repeat('5', 50), 'consecutivo' => $consecutivo,
        ]);
    }

    public function test_series_parse_consecutivo(): void
    {
        $service = app(FiscalSeriesService::class);

        $parts = $service->parseConsecutivo('05001034030000000002');
        $this->assertSame(['branch' => '050', 'terminal' => '01034', 'type' => '03', 'sequence' => 2], $parts);

        foreach (['123', '0500103403000000000X', '', '050010340300000000021'] as $bad) {
            try {
                $service->parseConsecutivo($bad);
                $this->fail("Consecutivo inválido debió bloquearse: {$bad}.");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_series_observe_and_next_sequence(): void
    {
        [$company] = $this->context();
        $service = app(FiscalSeriesService::class);

        $this->assertSame(1, $service->nextSequence($company->id, 'sandbox', '050', '01034', '03'));

        $doc = $this->acceptedDoc($company, null, '03', '05001034030000000007');
        $series = $service->observe($doc);

        $this->assertSame(7, (int) $series->last_sequence);
        $this->assertSame('05001034030000000007', $series->last_consecutivo);
        $this->assertSame(8, $service->nextSequence($company->id, 'sandbox', '050', '01034', '03'));

        $older = $this->acceptedDoc($company, null, '03', '05001034030000000003');
        $service->observe($older);
        $this->assertSame(7, (int) $series->fresh()->last_sequence);
    }

    public function test_series_import_validates_and_never_goes_backwards(): void
    {
        [$company] = $this->context();
        $service = app(FiscalSeriesService::class);

        $series = $service->import($company->id, 'sandbox', '001', '00001', '01', '00100001010000000009');
        $this->assertSame(9, (int) $series->last_sequence);

        try {
            $service->import($company->id, 'sandbox', '001', '00001', '01', '00100001010000000004');
            $this->fail('Importar serie anterior debió bloquearse.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            $service->import($company->id, 'sandbox', '001', '00001', '01', '00100001040000000004');
            $this->fail('Consecutivo de otra serie debió bloquearse.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->assertSame(9, (int) $series->fresh()->last_sequence);
    }

    public function test_series_claim_next_is_sequential(): void
    {
        [$company] = $this->context();
        $service = app(FiscalSeriesService::class);

        $this->assertSame(1, $service->claimNext($company->id, 'sandbox', '002', '00007', '04'));
        $this->assertSame(2, $service->claimNext($company->id, 'sandbox', '002', '00007', '04'));
        $this->assertSame(1, $service->claimNext($company->id, 'production', '002', '00007', '04'));
    }

    public function test_series_have_no_provider_column_and_survive_connection_updates(): void
    {
        $this->assertFalse(Schema::hasColumn('fiscal_series', 'provider'));

        [$company] = $this->context();
        app(FiscalSeriesService::class)->import($company->id, 'sandbox', '001', '00001', '01', '00100001010000000009');

        app(\App\Services\Fiscal\CompanyFiscalConfigService::class)->stageConnection($company, [
            'provider' => 'facturaencr', 'environment' => 'sandbox',
        ]);

        $this->assertSame(1, FiscalSeries::where('company_id', $company->id)->count());
        $this->assertSame(9, (int) FiscalSeries::first()->last_sequence);
    }

    public function test_switch_blocked_with_active_documents(): void
    {
        [$company] = $this->context();
        $this->acceptedDoc($company, null, '01', '00100001010000000001', 'queued');

        $check = app(\App\Services\Fiscal\FiscalProviderSwitchService::class)->canSwitch($company, 'facturaencr', ['api_key' => 'k', 'api_secret' => 's']);

        $this->assertFalse($check['ok']);
        $codes = array_column($check['blockers'], 'code');
        $this->assertContains('active_documents', $codes);
        $this->assertArrayHasKey('audit', $check);
        $this->assertSame($company->id, $check['audit']['company_id']);
    }

    public function test_switch_ok_when_clean_and_target_verified(): void
    {
        [$company] = $this->context();
        config(['fiscal.providers.fake2' => MasterFakeProvider::class]);
        Http::fake(['auth/verify' => Http::response(['ok' => true], 200)]);

        $check = app(\App\Services\Fiscal\FiscalProviderSwitchService::class)->canSwitch($company, 'fake2', ['api_key' => 'k', 'api_secret' => 's']);

        $this->assertTrue($check['ok']);
        $this->assertSame([], $check['blockers']);
    }

    public function test_switch_unknown_target_blocked(): void
    {
        [$company] = $this->context();

        $check = app(\App\Services\Fiscal\FiscalProviderSwitchService::class)->canSwitch($company, 'inventado');

        $this->assertFalse($check['ok']);
        $this->assertContains('unknown_target', array_column($check['blockers'], 'code'));
    }

    public function test_custody_payload_is_immutable_and_response_updates(): void
    {
        [$company] = $this->context();
        $doc = $this->acceptedDoc($company, null, '01', '00100001010000000001');
        $service = app(\App\Services\Fiscal\FiscalCustodyService::class);

        $service->record($doc, ['a' => 1], ['status' => 'queued']);
        $service->record($doc, ['a' => 2], ['status' => 'accepted']);

        $custody = FiscalDocumentCustody::where('electronic_document_id', $doc->id)->first();
        $this->assertSame(['a' => 1], $custody->payload);
        $this->assertSame(['status' => 'accepted'], $custody->response);
    }

    public function test_onboarding_stores_fiscal_profile_with_valid_codes(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.editar']);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'Demo S.A.',
            'economic_activity' => '1071.9',
            'fiscal_branch_code' => '001',
            'fiscal_terminal_code' => '00001',
        ])->assertRedirect();

        $config = \App\Models\CompanyFiscalConfig::where('company_id', $company->id)->first();
        $this->assertSame('1071.9', $config->economic_activity);
        $this->assertSame('001', $config->fiscal_branch_code);
        $this->assertSame('00001', $config->fiscal_terminal_code);

        $this->put(route('fiscal.setup.store', ['step' => 'datos']), [
            'identification_type' => '02',
            'identification_number' => '3101000000',
            'legal_name' => 'Demo S.A.',
            'fiscal_branch_code' => '01',
            'fiscal_terminal_code' => 'abcde',
        ])->assertSessionHasErrors(['fiscal_branch_code', 'fiscal_terminal_code']);
    }

    public function test_master_dashboard_renders_control_data(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        $company->update(['identification_number' => '3101000000', 'legal_name' => 'Demo S.A.']);
        app(\App\Services\Fiscal\CompanyFiscalConfigService::class)->ensure($company);
        \App\Models\CompanyFiscalConfig::query()->where('company_id', $company->id)->update(['economic_activity' => '1071.9']);
        $this->actingUser($company, $branch, ['fiscal.ver']);
        $this->acceptedDoc($company, null, '01', '00100001010000000001');

        $response = $this->get(route('fiscal.index'));

        $response->assertOk();
        $response->assertSee('PRUEBAS — SIN VALOR FISCAL');
        $response->assertSee('Demo S.A.');
        $response->assertSee('1071.9');
        $response->assertSee('Aceptados');
        $response->assertSee('Diagnóstico');
    }

    public function test_production_banner_is_distinct(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        app(\App\Services\Fiscal\CompanyFiscalConfigService::class)->ensure($company);
        \App\Models\CompanyFiscalConfig::query()->where('company_id', $company->id)->update(['environment' => 'production']);
        $this->actingUser($company, $branch, ['fiscal.ver']);

        $response = $this->get(route('fiscal.index'));

        $response->assertOk();
        $response->assertSee('AMBIENTE PRODUCCIÓN');
        $response->assertDontSee('PRUEBAS — SIN VALOR FISCAL');
    }

    public function test_switch_checklist_page_shows_blockers(): void
    {
        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, ['fiscal.editar']);
        $this->acceptedDoc($company, null, '01', '00100001010000000001', 'queued');

        $response = $this->get(route('fiscal.switch', ['to' => 'facturaencr']));

        $response->assertOk();
        $response->assertSee('Aún no se puede cambiar');
    }

    public function test_series_and_document_pages_require_permission(): void
    {
        [$company, $branch] = $this->context();
        $this->actingUser($company, $branch, []);

        $this->get(route('fiscal.series'))->assertForbidden();

        $other = Company::create(['trade_name' => 'O' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $doc = $this->acceptedDoc($other, null, '01', '00100001010000000001');
        $this->actingUser($company, $branch, ['fiscal.ver']);
        $this->get(route('fiscal.documents.show', $doc))->assertNotFound();
    }

    public function test_emit_records_custody_and_observes_series(): void
    {
        config(['fiscal.provider' => 'facturaencr']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'documents/nota-credito' => Http::response([
                'documentId' => 'doc-cust', 'clave' => str_repeat('4', 50),
                'consecutivo' => '00100001030000000001', 'status' => 'accepted',
            ], 200),
        ]);

        [$company, $branch] = $this->context();
        $this->enableFiscal($company);
        $user = User::factory()->create(['is_active' => true]);
        $customer = \App\Models\Customer::create(['company_id' => $company->id, 'name' => 'C', 'customer_type' => 'individual', 'is_active' => true]);
        $sale = \App\Models\Sale::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'customer_id' => $customer->id, 'sale_number' => 'POS-' . strtoupper(\Illuminate\Support\Str::random(12)),
            'document_type' => \App\Models\Sale::DOCUMENT_ELECTRONIC_INVOICE, 'sale_condition' => 'cash',
            'status' => \App\Models\Sale::STATUS_COMPLETED, 'currency_code' => 'CRC', 'exchange_rate' => 1,
            'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 0, 'total' => 1000,
            'paid_total' => 1000, 'balance_due' => 0, 'completed_at' => now(),
        ]);
        $method = \App\Models\PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true]);
        \App\Models\SalePayment::create(['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'amount' => '1010.0000', 'status' => 'completed', 'created_by' => $user->id]);

        $original = $this->acceptedDoc($company, $sale->id, '01', '00100001010000000001');
        $ref = new \App\DTOs\Fiscal\FiscalDocumentReference(
            $original->id, '01', (string) $original->clave, $original->consecutivo,
            '2026-09-27T10:00:00-06:00', '01', 'Motivo', 'facturaencr'
        );
        $line = new \App\DTOs\Fiscal\FiscalDocumentLine('0111100000100', 'Trigo', '1.0000', '1000.0000', '0.0000', '1000.0000', [['codigo' => '01', 'codigoTarifa' => '02']], '10.0000', '1010.0000');
        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'sale', (string) $sale->id,
            ['identification_type' => '01', 'identification_number' => '109880401', 'name' => 'R'],
            'CRC', '1', [$line],
            ['subtotal' => '1000.0000', 'discount_total' => '0.0000', 'tax_total' => '10.0000', 'total' => '1010.0000'],
            $ref, '1010.0000', 1
        );

        $result = app(\App\Services\Fiscal\FiscalManager::class)->emit(new \App\DTOs\Fiscal\FiscalEmissionRequest(
            $sale, $company, $customer, [], null, $adjustment
        ));

        $this->assertSame(\App\DTOs\Fiscal\FiscalEmissionResult::STATE_ACCEPTED, $result->state);

        $custody = FiscalDocumentCustody::where('electronic_document_id', $result->electronicDocumentId)->first();
        $this->assertNotNull($custody);
        $this->assertSame('03', $custody->payload['tipoDocumento']);
        $this->assertSame('01', $custody->payload['referencia'][0]['codigo']);
        $this->assertSame('accepted', $custody->response['status']);

        $series = FiscalSeries::where('company_id', $company->id)->where('document_type', '03')->first();
        $this->assertNotNull($series);
        $this->assertSame(1, (int) $series->last_sequence);
    }
}

class MasterFakeProvider implements \App\Contracts\Fiscal\FiscalProviderInterface, \App\Contracts\Fiscal\FiscalConnectionVerifiable
{
    public function providerCode(): string
    {
        return 'fake2';
    }

    public function emit(\App\DTOs\Fiscal\FiscalEmissionRequest $request): \App\DTOs\Fiscal\FiscalEmissionResult
    {
        throw new \RuntimeException('No debe emitir en estas pruebas.');
    }

    public function fetchStatus(\App\Models\ElectronicDocument $document): \App\DTOs\Fiscal\FiscalDocumentStatus
    {
        throw new \RuntimeException('No debe consultar en estas pruebas.');
    }

    public function verifyConnection(array $credentials): \App\DTOs\Fiscal\FiscalConnectionResult
    {
        return \App\DTOs\Fiscal\FiscalConnectionResult::ok();
    }
}
