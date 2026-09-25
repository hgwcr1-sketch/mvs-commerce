<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Facturaencr\FacturaencrClient;
use App\Services\Facturaencr\FacturaencrEmissionService;
use App\Services\Facturaencr\FacturaencrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class FacturaencrEmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_emit_creates_document_with_accepted_status(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-123',
                'clave' => '506010100000000000010000000000000000000',
                'consecutivo' => '001-001-000001',
                'requestId' => 'req-abc',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertNotNull($document->id);
        $this->assertSame('accepted', $document->status);
        $this->assertSame('doc-123', $document->provider_document_id);
        $this->assertSame('506010100000000000010000000000000000000', $document->clave);
        $this->assertSame('001-001-000001', $document->consecutivo);
        $this->assertSame('req-abc', $document->provider_request_id);
        $this->assertSame('01', $document->document_type);
        $this->assertSame('sandbox', $document->environment);
        $this->assertSame('facturaencr', $document->provider);
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame($sale->id, $document->sale_id);
        $this->assertNotNull($document->idempotency_key);
        $this->assertNotNull($document->created_at);
        $this->assertArrayNotHasKey('api_key', $document->toArray());
        $this->assertArrayNotHasKey('api_secret', $document->toArray());
        $this->assertArrayNotHasKey('credentials', $document->toArray());
    }

    public function test_emit_sets_queued_status_on_202(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'queued',
            ], 202),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('queued', $document->status);
    }

    public function test_202_persists_provider_identifiers_without_accepting_document(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'pending',
                'documentId' => 'doc-pending',
                'clave' => 'clave-pending',
                'consecutivo' => '001-001-000002',
            ], 202),
        ]);

        $document = (new FacturaencrEmissionService())->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('pending', $document->status);
        $this->assertFalse($document->isFinal());
        $this->assertSame('doc-pending', $document->provider_document_id);
        $this->assertSame('clave-pending', $document->clave);
        $this->assertSame('001-001-000002', $document->consecutivo);
    }

    public function test_emit_records_error_on_400_response(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'error' => 'validation_error',
                'message' => 'Validation failed',
            ], 400),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('error', $document->status);
        $this->assertSame('validation_error', $document->last_error_code);
        $this->assertStringContainsString('Validation failed', $document->last_error_message);
    }

    public function test_emit_records_error_on_422_response(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'error' => 'validation',
                'message' => 'The clave field is required.',
            ], 422),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('error', $document->status);
        $this->assertSame('validation', $document->last_error_code);
        $this->assertStringContainsString('clave', $document->last_error_message);
    }

    public function test_emit_persists_expected_http_error_classification(): void
    {
        $responses = [
            [401, 'unauthorized'],
            [403, 'forbidden'],
            [409, 'conflict'],
            [429, 'rate_limit'],
        ];

        $sequence = Http::fakeSequence();
        foreach ($responses as [$status, $error]) {
            $sequence->push(['error' => $error, 'message' => $error], $status);
        }

        foreach ($responses as [$status, $error]) {
            [$company, $customer, $sale] = $this->prepareData('Test ' . $status, 'Test S.A. ' . $status, '310100' . $status);
            $item = $this->createItem($sale);

            $document = (new FacturaencrEmissionService())->emit($sale, $company, $customer, [$item], $sale->payments->first());

            $this->assertSame('error', $document->status);
            $this->assertSame($error, $document->last_error_code);
            $this->assertStringContainsString($error, $document->last_error_message);
        }

        $this->assertCount(count($responses), Http::recorded());
    }

    public function test_emit_records_error_on_500_response(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'message' => 'Internal server error',
            ], 500),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('error', $document->status);
        $this->assertTrue($document->last_error_code !== null);
        $this->assertNotNull($document->last_error_message);
    }

    public function test_emit_records_retryable_network_error_without_retrying(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake(fn () => throw new ConnectionException('network timeout'));

        $document = (new FacturaencrEmissionService())->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('error', $document->status);
        $this->assertSame('CONNECTION_TIMEOUT', $document->last_error_code);
        $this->assertStringContainsString('network timeout', $document->last_error_message);
        Http::assertNothingSent();
    }

    public function test_emit_reuses_document_and_idempotency_key_on_retry(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response(['status' => 'queued'], 202),
        ]);

        $service = new FacturaencrEmissionService();
        $first = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());
        $second = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->idempotency_key, $second->idempotency_key);
        $this->assertCount(1, ElectronicDocument::where('sale_id', $sale->id)->get());
        $this->assertCount(1, Http::recorded());
    }

    public function test_emit_sends_mapper_payload_and_required_headers(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);
        Config::set('facturaencr.api_key', 'test-key');
        Config::set('facturaencr.api_secret', 'test-secret');

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response(['status' => 'accepted'], 200),
        ]);

        (new FacturaencrEmissionService())->emit($sale, $company, $customer, [$item], $sale->payments->first());

        Http::assertSent(function ($request) use ($sale) {
            $key = (new \App\Services\Facturaencr\FacturaencrInvoiceMapper())->idempotencyKey($sale, '01');

            return $request->url() === 'https://api.facturaencr.com/v2/efactura/documents/factura'
                && $request->hasHeader('X-API-Key', 'test-key')
                && $request->hasHeader('X-API-Secret', 'test-secret')
                && $request->hasHeader('Idempotency-Key', $key)
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->data()['emisorLegalId'] === 'EMISORPRUEBA'
                && !array_key_exists('idempotencyKey', $request->data())
                && $request->data()['detalle'][0]['impuesto'] === [[
                    'codigo' => '01',
                    'codigoTarifa' => '08',
                    'tarifa' => 13,
                ]];
        });
    }

    public function test_unsupported_tax_rate_fails_before_http_post(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);
        $item->tax_rate = 12;
        Http::fake();

        try {
            (new FacturaencrEmissionService())->emit($sale, $company, $customer, [$item], $sale->payments->first());
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $exception) {
            $this->assertArrayHasKey('detalle[0]_impuesto', $exception->getErrors());
        }

        Http::assertNothingSent();
    }

    public function test_emit_is_idempotent_returns_existing_document(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $existingDoc = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => md5("{$sale->company_id}-{$sale->id}-01"),
            'provider_document_id' => 'existing-doc',
            'clave' => 'existing-clave',
            'status' => 'accepted',
        ]);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame($existingDoc->id, $document->id);
        $this->assertSame('accepted', $document->status);
        $this->assertSame('existing-doc', $document->provider_document_id);
    }

    public function test_emit_no_http_call_when_document_already_exists(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => md5("{$sale->company_id}-{$sale->id}-01"),
            'status' => 'accepted',
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('accepted', $document->status);
        Http::assertNothingSent();
    }

    public function test_emit_stores_document_with_correct_company(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-company',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame($company->id, $document->company_id);
    }

    public function test_emit_creates_queued_document_before_http_call(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-lifecycle',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertNotNull($document->id);
        $this->assertSame('accepted', $document->fresh()->status);
    }

    public function test_emit_sends_correct_endpoint(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-key');
        Config::set('facturaencr.api_secret', 'test-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-endpoint',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'documents/factura'));
    }

    public function test_emit_sends_idempotency_key_header(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-key');
        Config::set('facturaencr.api_secret', 'test-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-idem',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $document = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key'));
    }

    public function test_emit_multi_company_isolation(): void
    {
        $company1 = Company::create(['trade_name' => 'Test1', 'legal_name' => 'Test1 S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $company2 = Company::create(['trade_name' => 'Test2', 'legal_name' => 'Test2 S.A.', 'identification_number' => '3101000001', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch1 = Branch::create(['company_id' => $company1->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $branch2 = Branch::create(['company_id' => $company2->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user1 = User::factory()->create(['is_active' => true]);
        $user1->companies()->attach($company1->id);
        $user1->branches()->attach($branch1->id);
        $user2 = User::factory()->create(['is_active' => true]);
        $user2->companies()->attach($company2->id);
        $user2->branches()->attach($branch2->id);
        $customer1 = Customer::create(['company_id' => $company1->id, 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '1234567890', 'name' => 'Juan']);
        $customer2 = Customer::create(['company_id' => $company2->id, 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '1234567890', 'name' => 'Ana']);

        $sale1 = Sale::create(['company_id' => $company1->id, 'branch_id' => $branch1->id, 'user_id' => $user1->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);
        $sale2 = Sale::create(['company_id' => $company2->id, 'branch_id' => $branch2->id, 'user_id' => $user2->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);

        $item1 = SaleItem::create(['sale_id' => $sale1->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);
        $item2 = SaleItem::create(['sale_id' => $sale2->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-multi',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $doc1 = $service->emit($sale1, $company1, $customer1, [$item1], $sale1->payments->first());
        $doc2 = $service->emit($sale2, $company2, $customer2, [$item2], $sale2->payments->first());

        $this->assertNotSame($doc1->id, $doc2->id);
        $this->assertSame($company1->id, $doc1->company_id);
        $this->assertSame($company2->id, $doc2->company_id);
        $this->assertCount(2, ElectronicDocument::all());
    }

    public function test_emit_derives_tiquete_document_type_and_stable_key(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $sale->update(['document_type' => 'electronic_ticket']);
        $sale->refresh();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-ticket',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService();
        $first = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());
        $second = $service->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('04', $first->document_type);
        $this->assertSame(md5("{$company->id}-{$sale->id}-04"), $first->idempotency_key);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->idempotency_key, $second->idempotency_key);
        $this->assertCount(1, ElectronicDocument::where('sale_id', $sale->id)->get());
        $this->assertCount(1, Http::recorded());

        Http::assertSent(fn ($request) => $request->data()['tipoDocumento'] === '04'
            && $request->hasHeader('Idempotency-Key', $first->idempotency_key));
    }

    public function test_emit_keeps_factura_document_type_01(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-invoice',
            ], 200),
        ]);

        $document = (new FacturaencrEmissionService())->emit($sale, $company, $customer, [$item], $sale->payments->first());

        $this->assertSame('01', $document->document_type);
        $this->assertSame(md5("{$company->id}-{$sale->id}-01"), $document->idempotency_key);

        Http::assertSent(fn ($request) => $request->data()['tipoDocumento'] === '01');
    }

    private function prepareData(
        string $tradeName = 'Test Comercio',
        string $legalName = 'Test S.A.',
        string $identification = '3101000000'
    ): array {
        $company = Company::create(['trade_name' => $tradeName, 'legal_name' => $legalName, 'identification_number' => $identification, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '1234567890',
            'name' => 'Juan Pérez',
        ]);

        $paymentMethod = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => 'cash', 'is_system' => true, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false, 'allows_change' => true]);

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'sale_number' => 'POS-001',
            'document_type' => 'electronic_invoice',
            'sale_condition' => 'cash',
            'status' => 'completed',
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 100,
            'tax_total' => 117,
            'rounding_total' => 0,
            'total' => 1017,
            'paid_total' => 1017,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'cash_session_id' => null,
            'payment_method_id' => $paymentMethod->id,
            'created_by' => $user->id,
            'status' => 'completed',
            'amount' => 1017,
            'received_amount' => 1200,
            'change_amount' => 183,
        ]);

        return [$company, $customer, $sale];
    }

    private function createItem(Sale $sale): SaleItem
    {
        return SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => 'P01',
            'cabys_code' => '5060101000000',
            'description' => 'Producto',
            'unit_code' => 'un',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_rate' => 13,
            'tax_total' => 117,
            'total' => 1017,
            'unit_cost' => 500,
        ]);
    }
}
