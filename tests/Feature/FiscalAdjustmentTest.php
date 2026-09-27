<?php

namespace Tests\Feature;

use App\DTOs\Fiscal\FiscalDocument;
use App\DTOs\Fiscal\FiscalDocumentLine;
use App\DTOs\Fiscal\FiscalDocumentReference;
use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\DTOs\Fiscal\FiscalReferenceException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\User;
use App\Services\Fiscal\FiscalManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_neutral_nc_validates_and_builds_stable_identity(): void
    {
        $document = $this->nc('01');

        $document->validate();

        $this->assertTrue($document->isModifier());
        $this->assertSame(
            md5("9-return-15-03"),
            (new FiscalDocument(9, '03', 'return', '15', $this->receptor(), 'CRC', '1', [$this->line()], $this->totals(), $document->reference))->idempotencyKey()
        );
    }

    public function test_modifier_without_reference_is_rejected(): void
    {
        $this->expectException(FiscalReferenceException::class);

        (new FiscalDocument(1, '03', 'return', '1', $this->receptor(), 'CRC', '1', [$this->line()], $this->totals()))->validate();
    }

    public function test_invalid_cabys_totals_lines_and_receptor_are_rejected(): void
    {
        foreach ([
            fn () => new FiscalDocument(1, '03', 'r', '1', $this->receptor(), 'CRC', '1', [$this->line('ABC')], $this->totals(), $this->reference()),
            fn () => new FiscalDocument(1, '03', 'r', '1', $this->receptor(), 'CRC', '1', [$this->line()], ['total' => 'x'], $this->reference()),
            fn () => new FiscalDocument(1, '03', 'r', '1', $this->receptor(), 'CRC', '1', [], $this->totals(), $this->reference()),
            fn () => new FiscalDocument(1, '03', 'r', '1', ['name' => 'X'], 'CRC', '1', [$this->line()], $this->totals(), $this->reference()),
            fn () => new FiscalDocument(1, '01', 'sale', '1', $this->receptor(), 'CRC', '1', [$this->line()], $this->totals(), $this->reference()),
            fn () => new FiscalDocument(1, '09', 'sale', '1', $this->receptor(), 'CRC', '1', [$this->line()], $this->totals()),
        ] as $factory) {
            try {
                $factory()->validate();
                $this->fail('Validación estructural debió rechazar el documento.');
            } catch (FiscalReferenceException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_reference_requires_emitted_original_same_company_and_allowed_modifier(): void
    {
        [$company, $other] = [Company::create(['trade_name' => 'A' . uniqid(), 'is_active' => true]), Company::create(['trade_name' => 'B' . uniqid(), 'is_active' => true])];

        $accepted = ElectronicDocument::create([
            'company_id' => $company->id, 'provider' => 'fake', 'document_type' => '01',
            'environment' => 'sandbox', 'idempotency_key' => md5('a1'), 'status' => 'accepted', 'clave' => 'K1',
        ]);

        $this->referenceFor($accepted)->validateAgainst($accepted, $company->id, '03');
        $this->referenceFor($accepted)->validateAgainst($accepted, $company->id, '02');

        try {
            $this->referenceFor($accepted)->validateAgainst($accepted, $other->id, '03');
            $this->fail('Referencia cruzada debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('cross_company', $e->reason);
        }

        foreach (['queued', 'error', 'rejected'] as $status) {
            $doc = ElectronicDocument::create([
                'company_id' => $company->id, 'provider' => 'fake', 'document_type' => '01',
                'environment' => 'sandbox', 'idempotency_key' => md5('s' . $status . uniqid()), 'status' => $status, 'clave' => 'KX',
            ]);

            try {
                $this->referenceFor($doc)->validateAgainst($doc, $company->id, '03');
                $this->fail("Estado {$status} debió bloquearse.");
            } catch (FiscalReferenceException $e) {
                $this->assertSame('original_not_emitted', $e->reason);
            }
        }

        $nc = ElectronicDocument::create([
            'company_id' => $company->id, 'provider' => 'fake', 'document_type' => '03',
            'environment' => 'sandbox', 'idempotency_key' => md5('nc' . uniqid()), 'status' => 'accepted', 'clave' => 'K3',
        ]);

        try {
            $this->referenceFor($nc)->validateAgainst($nc, $company->id, '03');
            $this->fail('NC sobre NC debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('invalid_modifier', $e->reason);
        }
    }

    public function test_manager_blocks_cross_company_adjustment_before_provider(): void
    {
        config(['fiscal.provider' => 'fake', 'fiscal.providers.fake' => AdjustmentFakeProvider::class]);
        AdjustmentFakeProvider::reset();

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company);
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted', 'clave' => 'K9',
        ]);

        $foreign = new FiscalDocument(
            999999, '03', 'return', '1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original)
        );

        try {
            app(FiscalManager::class)->emit(new FiscalEmissionRequest(
                $sale, $company, $sale->customer, [], null, $foreign
            ));
            $this->fail('Ajuste cross-company debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('cross_company', $e->reason);
        }

        $this->assertSame(0, AdjustmentFakeProvider::requests());
        Http::assertNothingSent();
    }

    public function test_manager_blocks_missing_original_before_provider(): void
    {
        config(['fiscal.provider' => 'fake', 'fiscal.providers.fake' => AdjustmentFakeProvider::class]);
        AdjustmentFakeProvider::reset();

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company);
        $sale = $this->completedSale($company, $branch, $user);

        $ghost = new FiscalDocumentReference(123456, '01', 'KG', null, null, '01', 'Anulación', 'fake');
        $adjustment = new FiscalDocument(
            $company->id, '03', 'return', '1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $ghost
        );

        try {
            app(FiscalManager::class)->emit(new FiscalEmissionRequest(
                $sale, $company, $sale->customer, [], null, $adjustment
            ));
            $this->fail('Original inexistente debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('original_not_found', $e->reason);
        }

        $this->assertSame(0, AdjustmentFakeProvider::requests());
        Http::assertNothingSent();
    }

    public function test_builder_assembles_total_and_partial_nc_with_original_cap(): void
    {
        $reference = $this->reference();

        $total = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            5, 'return', 'R1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $reference, '1010.0000'
        );
        $total->validate();
        $this->assertSame('03', $total->documentType);

        $partial = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            5, 'return', 'R2', $this->receptor(), 'CRC', '1',
            [$this->line()], ['subtotal' => '500.0000', 'discount_total' => '0.0000', 'tax_total' => '5.0000', 'total' => '505.0000'],
            $reference, '1010.0000'
        );
        $partial->validate();
        $this->assertSame('505.0000', $partial->totals['total']);

        try {
            \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
                5, 'return', 'R3', $this->receptor(), 'CRC', '1',
                [$this->line()], ['subtotal' => '2000.0000', 'discount_total' => '0.0000', 'tax_total' => '20.0000', 'total' => '2020.0000'],
                $reference, '1010.0000'
            );
            $this->fail('NC sobre el total original debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('exceeds_original', $e->reason);
        }

        $debit = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            5, 'debit', 'D1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $reference, '1010.0000'
        );
        $debit->validate();
        $this->assertSame('02', $debit->documentType);
    }

    public function test_facturaencr_mapper_builds_official_payload_and_endpoints(): void
    {
        $company = Company::create(['trade_name' => 'M' . uniqid(), 'is_active' => true]);
        $clave = str_repeat('5', 50);
        $document = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference(1, '01', $clave, null, '2026-09-27T10:00:00-06:00', '01', 'Devolución total', 'facturaencr')
        );

        $mapper = new \App\Services\Facturaencr\FacturaencrAdjustmentMapper();
        $payload = $mapper->map($document, $company, null, '01', ['01']);

        $this->assertSame('03', $payload['tipoDocumento']);
        $this->assertSame('109880401', $payload['receptor']['numeroIdentificacion']);
        $this->assertSame('0111100000100', $payload['detalle'][0]['codigoCabys']);
        $this->assertSame('01', $payload['referencia'][0]['tipoDocumento']);
        $this->assertSame($clave, $payload['referencia'][0]['numero']);
        $this->assertSame('2026-09-27T10:00:00-06:00', $payload['referencia'][0]['fechaEmision']);
        $this->assertSame('01', $payload['referencia'][0]['codigo']);
        $this->assertSame('Devolución total', $payload['referencia'][0]['razon']);
        $this->assertArrayNotHasKey('informacionReferencia', $payload);

        $this->assertSame('documents/nota-credito', $mapper->endpoint('03'));
        $this->assertSame('documents/nota-debito', $mapper->endpoint('02'));

        try {
            $mapper->endpoint('09');
            $this->fail('Tipo no soportado debió bloquearse.');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException) {
            $this->assertTrue(true);
        }

        Http::assertNothingSent();
    }

    public function test_facturaencr_provider_blocks_invalid_reference_without_http_document_or_consumption(): void
    {
        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company);
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted', 'clave' => 'K1',
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original)
        );

        $result = app(FiscalManager::class)->provider('facturaencr')->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertTrue($result->isError());
        $this->assertSame('validation_failed', $result->error->code);
        $this->assertNull($result->electronicDocumentId);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_facturaencr_provider_emits_nc03_via_official_endpoint(): void
    {
        config(['fiscal.provider' => 'facturaencr']);
        $claveOriginal = str_repeat('5', 50);
        $claveNc = str_repeat('6', 50);

        $this->resetHttp();
        Http::fake([
            'documents/nota-credito' => Http::response([
                'documentId' => 'doc-nc-1', 'clave' => $claveNc, 'consecutivo' => '00100001030000000001',
                'status' => 'accepted',
            ], 200),
        ]);

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 5]);
        $sale = $this->completedSale($company, $branch, $user);
        $this->salePayment($sale);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted',
            'clave' => $claveOriginal, 'consecutivo' => 'C-ORIG',
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference($original->id, '01', $claveOriginal, 'C-ORIG', '2026-09-27T10:00:00-06:00', '01', 'Devolución total', 'facturaencr'),
            '1010.0000'
        );

        $result = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        $this->assertNotNull($result->electronicDocumentId);

        Http::assertSent(function ($request) use ($claveOriginal) {
            return str_ends_with($request->url(), 'documents/nota-credito')
                && $request['referencia'][0]['numero'] === $claveOriginal
                && $request['referencia'][0]['codigo'] === '01'
                && $request['tipoDocumento'] === '03'
                && $request['medioPago'] === ['01'];
        });

        $nc = ElectronicDocument::findOrFail($result->electronicDocumentId);
        $this->assertSame('03', $nc->document_type);
        $this->assertSame('facturaencr', $nc->provider);
        $this->assertSame($claveNc, $nc->clave);
        $this->assertSame('return', $nc->source_type);
        $this->assertSame('R1', $nc->source_id);
        $this->assertSame($original->id, (int) $nc->original_document_id);
        $this->assertSame(1, \App\Models\FiscalConsumption::where('electronic_document_id', $nc->id)->count());

        $retry = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));
        $this->assertSame($nc->id, $retry->electronicDocumentId);
        $this->assertSame(1, ElectronicDocument::where('document_type', '03')->count());
        $this->assertDatabaseCount('fiscal_consumptions', 1);
    }

    public function test_facturaencr_provider_emits_nd02_via_official_endpoint(): void
    {
        config(['fiscal.provider' => 'facturaencr']);
        $claveOriginal = str_repeat('7', 50);
        $claveNd = str_repeat('8', 50);

        $this->resetHttp();
        Http::fake([
            'documents/nota-debito' => Http::response([
                'documentId' => 'doc-nd-1', 'clave' => $claveNd, 'consecutivo' => '00100001020000000001',
                'status' => 'accepted',
            ], 200),
        ]);

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 5]);
        $sale = $this->completedSale($company, $branch, $user);
        $this->salePayment($sale, 'card');

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '04', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-04"), 'status' => 'accepted',
            'clave' => $claveOriginal, 'consecutivo' => 'C-ORIG-ND',
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            $company->id, 'debit', 'D1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference($original->id, '04', $claveOriginal, 'C-ORIG-ND', '2026-09-27T11:00:00-06:00', '02', 'Cargo omitido', 'facturaencr'),
            '1010.0000'
        );

        $result = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), 'documents/nota-debito')
                && $request['referencia'][0]['codigo'] === '02'
                && $request['tipoDocumento'] === '02'
                && $request['medioPago'] === ['02'];
        });

        $nd = ElectronicDocument::findOrFail($result->electronicDocumentId);
        $this->assertSame('02', $nd->document_type);
        $this->assertSame($claveNd, $nd->clave);
        $this->assertSame($original->id, (int) $nd->original_document_id);
        $this->assertSame(1, \App\Models\FiscalConsumption::where('electronic_document_id', $nd->id)->count());
    }

    public function test_facturaencr_provider_records_error_without_consumption(): void
    {
        config(['fiscal.provider' => 'facturaencr']);

        $this->resetHttp();
        Http::fake([
            'documents/nota-credito' => Http::response([
                'error' => 'validation_error', 'message' => 'Detalle inválido',
            ], 400),
        ]);

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 5]);
        $sale = $this->completedSale($company, $branch, $user);
        $this->salePayment($sale);
        $claveOriginal = str_repeat('5', 50);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted', 'clave' => $claveOriginal,
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'RERR', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference($original->id, '01', $claveOriginal, null, '2026-09-27T10:00:00-06:00', '01', 'Prueba error', 'facturaencr'),
            '1010.0000'
        );

        $result = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertTrue($result->isError());
        $this->assertNotNull($result->electronicDocumentId);

        $doc = ElectronicDocument::findOrFail($result->electronicDocumentId);
        $this->assertSame('error', $doc->status);
        $this->assertSame('validation_error', $doc->last_error_code);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
    }

    public function test_facturaencr_mapper_blocks_cash_without_medio_pago(): void
    {
        $company = Company::create(['trade_name' => 'G' . uniqid(), 'is_active' => true]);
        $document = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'RGATE', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference(1, '01', str_repeat('5', 50), null, '2026-09-27T10:00:00-06:00', '01', 'Motivo', 'facturaencr')
        );

        try {
            (new \App\Services\Facturaencr\FacturaencrAdjustmentMapper())->map($document, $company, null, '01', []);
            $this->fail('Contado sin medio de pago debió bloquearse (Hacienda -496).');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $this->assertArrayHasKey('medioPago', $e->getErrors());
        }

        try {
            (new \App\Services\Facturaencr\FacturaencrAdjustmentMapper())->map($document, $company, null, '01', ['99']);
            $this->fail('Medio de pago desconocido debió bloquearse.');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $this->assertArrayHasKey('medioPago', $e->getErrors());
        }

        $payload = (new \App\Services\Facturaencr\FacturaencrAdjustmentMapper())->map(
            $document, $company, null, '01', [['tipo' => '01', 'monto' => 6000.0], ['tipo' => '02', 'monto' => 1010.0]]
        );
        $this->assertSame([['tipo' => '01', 'monto' => 6000.0], ['tipo' => '02', 'monto' => 1010.0]], $payload['medioPago']);

        $credit = (new \App\Services\Facturaencr\FacturaencrAdjustmentMapper())->map($document, $company, null, '02', []);
        $this->assertArrayNotHasKey('medioPago', $credit);

        Http::assertNothingSent();
    }

    public function test_facturaencr_provider_blocks_cash_without_derivable_payments(): void
    {
        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company);
        $sale = $this->completedSale($company, $branch, $user);
        $claveOriginal = str_repeat('5', 50);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted', 'clave' => $claveOriginal,
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'RNOPAY', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference($original->id, '01', $claveOriginal, null, '2026-09-27T10:00:00-06:00', '01', 'Sin pagos', 'facturaencr'),
            '1010.0000'
        );

        $result = app(FiscalManager::class)->provider('facturaencr')->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertTrue($result->isError());
        $this->assertSame('validation_failed', $result->error->code);
        $this->assertNull($result->electronicDocumentId);
        $this->assertDatabaseCount('electronic_documents', 1);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_facturaencr_provider_blocks_unmappable_payment_method(): void
    {
        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company);
        $sale = $this->completedSale($company, $branch, $user);
        $this->salePayment($sale, 'loyalty_points');
        $claveOriginal = str_repeat('5', 50);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted', 'clave' => $claveOriginal,
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'RLOYAL', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference($original->id, '01', $claveOriginal, null, '2026-09-27T10:00:00-06:00', '01', 'Puntos', 'facturaencr'),
            '1010.0000'
        );

        $result = app(FiscalManager::class)->provider('facturaencr')->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertTrue($result->isError());
        $this->assertSame('validation_failed', $result->error->code);
        $this->assertNull($result->electronicDocumentId);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
        Http::assertNothingSent();
    }

    public function test_facturaencr_provider_omits_medio_pago_for_credit(): void
    {
        config(['fiscal.provider' => 'facturaencr']);
        $this->resetHttp();
        Http::fake([
            'documents/nota-credito' => Http::response([
                'documentId' => 'doc-nc-credit', 'clave' => str_repeat('6', 50),
                'consecutivo' => '00100001030000000002', 'status' => 'accepted',
            ], 200),
        ]);

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 5]);
        $sale = $this->completedSale($company, $branch, $user);
        $sale->update(['sale_condition' => 'credit']);
        $claveOriginal = str_repeat('5', 50);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted', 'clave' => $claveOriginal,
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'RCREDIT', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference($original->id, '01', $claveOriginal, null, '2026-09-27T10:00:00-06:00', '02', 'Ajuste crédito', 'facturaencr'),
            '1010.0000'
        );

        $result = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale->fresh(), $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), 'documents/nota-credito')
                && ! isset($request['medioPago'])
                && $request['condicionVenta'] === '02';
        });
    }

    public function test_facturaencr_payload_carries_no_location_fields(): void
    {
        $company = Company::create(['trade_name' => 'U' . uniqid(), 'is_active' => true]);
        $clave = str_repeat('5', 50);
        $ref = new FiscalDocumentReference(1, '01', $clave, null, '2026-09-27T10:00:00-06:00', '01', 'Motivo', 'facturaencr');

        foreach (['03', '02'] as $type) {
            $document = $type === '03'
                ? \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote($company->id, 'return', 'RU', $this->receptor(), 'CRC', '1', [$this->line()], $this->totals(), $ref)
                : \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote($company->id, 'debit', 'DU', $this->receptor(), 'CRC', '1', [$this->line()], $this->totals(), $ref);

            $payload = (new \App\Services\Facturaencr\FacturaencrAdjustmentMapper())->map($document, $company, null, '01', ['01']);

            $this->assertArrayNotHasKey('ubicacion', $payload);
            $this->assertArrayNotHasKey('ubicacion', $payload['receptor']);
            $this->assertArrayNotHasKey('provincia', $payload);
            $this->assertArrayNotHasKey('canton', $payload);
        }

        Http::assertNothingSent();
    }

    private function nc(string $originalType): FiscalDocument
    {
        return new FiscalDocument(
            9, '03', 'return', '15', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference(77, $originalType, 'K77', 'C77', '2026-09-01', '01', 'Devolución total', 'fake')
        );
    }

    private function reference(): FiscalDocumentReference
    {
        return new FiscalDocumentReference(1, '01', 'K1', null, null, '01', 'Motivo', 'fake');
    }

    private function referenceFor(ElectronicDocument $original): FiscalDocumentReference
    {
        return new FiscalDocumentReference(
            $original->id, $original->document_type, (string) $original->clave,
            $original->consecutivo, null, '01', 'Devolución de mercancía', $original->provider
        );
    }

    private function receptor(): array
    {
        return ['identification_type' => '01', 'identification_number' => '109880401', 'name' => 'Receptor', 'email' => 'r@x.cr'];
    }

    /**
     * El setUp registra un fake catch-all (CERO HTTP). Las pruebas de
     * emisión real (falsa) necesitan stubs específicos: se cambia el
     * factory por uno limpio para que el catch-all no los opaque.
     */
    private function resetHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
    }

    private function line(string $cabys = '0111100000100'): FiscalDocumentLine
    {
        return new FiscalDocumentLine($cabys, 'Trigo', '1.0000', '1000.0000', '0.0000', '1000.0000', [['codigo' => '01', 'codigoTarifa' => '02']], '10.0000', '1010.0000');
    }

    private function totals(): array
    {
        return ['subtotal' => '1000.0000', 'discount_total' => '0.0000', 'tax_total' => '10.0000', 'total' => '1010.0000'];
    }

    private function posContext(): array
    {
        $company = Company::create(['trade_name' => 'E' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P' . $company->id, 'is_active' => true]);
        $user = User::factory()->create();

        return [$company, $branch, $user];
    }

    private function enableFiscal(Company $company, array $attributes = []): void
    {
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        \App\Models\CompanyLicense::query()->where('company_id', $company->id)->update(array_merge([
            'fiscal_enabled' => true,
        ], $attributes));
    }

    private function completedSale(Company $company, Branch $branch, User $user): Sale
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'C', 'customer_type' => 'individual', 'is_active' => true]);

        return Sale::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'customer_id' => $customer->id, 'sale_number' => 'POS-' . strtoupper(Str::random(12)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE, 'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED, 'currency_code' => 'CRC', 'exchange_rate' => 1,
            'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 0, 'total' => 1000,
            'paid_total' => 1000, 'balance_due' => 0, 'completed_at' => now(),
        ]);
    }

    private function salePayment(Sale $sale, string $methodCode = 'cash', string $amount = '1010.0000'): \App\Models\SalePayment
    {
        $method = \App\Models\PaymentMethod::create([
            'company_id' => $sale->company_id, 'code' => $methodCode,
            'name' => 'M-' . $methodCode, 'type' => $methodCode, 'is_active' => true,
        ]);

        return \App\Models\SalePayment::create([
            'sale_id' => $sale->id, 'payment_method_id' => $method->id,
            'amount' => $amount, 'status' => \App\Models\SalePayment::STATUS_COMPLETED,
            'created_by' => $sale->user_id,
        ]);
    }

    public function test_nc03_local_e2e_creates_single_document_and_consumption(): void
    {
        config(['fiscal.provider' => 'fake', 'fiscal.providers.fake' => AdjustmentEmitFakeProvider::class]);
        AdjustmentEmitFakeProvider::reset();

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 5]);
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted',
            'clave' => 'K-ORIG', 'consecutivo' => 'C-ORIG',
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );

        $result = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        $this->assertNotNull($result->electronicDocumentId);
        $this->assertSame(1, AdjustmentEmitFakeProvider::requests());

        $nc = ElectronicDocument::findOrFail($result->electronicDocumentId);
        $this->assertSame('03', $nc->document_type);
        $this->assertSame($adjustment->idempotencyKey(), $nc->idempotency_key);
        $this->assertSame(1, \App\Models\FiscalConsumption::where('electronic_document_id', $nc->id)->count());
        $this->assertSame('included', \App\Models\FiscalConsumption::where('electronic_document_id', $nc->id)->value('classification'));

        $retry = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));
        $this->assertSame($nc->id, $retry->electronicDocumentId);
        $this->assertSame(1, ElectronicDocument::where('document_type', '03')->count());
        $this->assertDatabaseCount('fiscal_consumptions', 1);

        $status = app(FiscalManager::class)->fetchStatus($nc);
        $this->assertTrue($status->final);
        $this->assertDatabaseCount('fiscal_consumptions', 1);
        Http::assertNothingSent();
    }

    public function test_nc03_quota_overage_and_disabled_gates(): void
    {
        config(['fiscal.provider' => 'fake', 'fiscal.providers.fake' => AdjustmentEmitFakeProvider::class]);
        AdjustmentEmitFakeProvider::reset();

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 1, 'fiscal_overage_enabled' => true, 'fiscal_overage_unit_price' => '7.2500']);
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake',
            'document_type' => '04', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-04"), 'status' => 'accepted', 'clave' => 'K-O4',
        ]);

        $first = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );
        app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale, $company, $sale->customer, [], null, $first));

        $sale2 = $this->completedSale($company, $branch, $user);
        $second = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R2', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );
        $secondResult = app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale2, $company, $sale2->customer, [], null, $second));
        $this->assertNotNull($secondResult->electronicDocumentId);

        $overage = \App\Models\FiscalConsumption::where('classification', 'overage')->sole();
        $this->assertSame('7.2500', $overage->unit_price);

        \App\Models\CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => false]);

        $sale3 = $this->completedSale($company, $branch, $user);
        $third = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R3', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );

        try {
            app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale3, $company, $sale3->customer, [], null, $third));
            $this->fail('Fiscal deshabilitado debió bloquear la NC.');
        } catch (\App\Exceptions\FiscalQuotaException $e) {
            $this->assertSame('fiscal_disabled', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_nd02_local_e2e_creates_single_document_and_consumption(): void
    {
        config(['fiscal.provider' => 'fake', 'fiscal.providers.fake' => AdjustmentEmitFakeProvider::class]);
        AdjustmentEmitFakeProvider::reset();

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 5]);
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted',
            'clave' => 'K-ORIG-ND', 'consecutivo' => 'C-ORIG-ND',
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            $company->id, 'debit', 'D1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );

        $result = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        $this->assertNotNull($result->electronicDocumentId);
        $this->assertSame(1, AdjustmentEmitFakeProvider::requests());

        $nd = ElectronicDocument::findOrFail($result->electronicDocumentId);
        $this->assertSame('02', $nd->document_type);
        $this->assertSame($adjustment->idempotencyKey(), $nd->idempotency_key);
        $this->assertSame('debit', $nd->source_type);
        $this->assertSame('D1', $nd->source_id);
        $this->assertSame($original->id, (int) $nd->original_document_id);
        $this->assertSame($original->id, (int) $nd->originalDocument->id);
        $this->assertTrue($original->adjustments()->where('id', $nd->id)->exists());
        $this->assertSame(1, \App\Models\FiscalConsumption::where('electronic_document_id', $nd->id)->count());
        $this->assertSame('included', \App\Models\FiscalConsumption::where('electronic_document_id', $nd->id)->value('classification'));

        $retry = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));
        $this->assertSame($nd->id, $retry->electronicDocumentId);
        $this->assertSame(1, ElectronicDocument::where('document_type', '02')->count());
        $this->assertDatabaseCount('fiscal_consumptions', 1);

        $status = app(FiscalManager::class)->fetchStatus($nd);
        $this->assertTrue($status->final);
        $this->assertDatabaseCount('fiscal_consumptions', 1);
        Http::assertNothingSent();
    }

    public function test_nd02_quota_overage_disabled_retry_and_tenant_isolation(): void
    {
        config(['fiscal.provider' => 'fake', 'fiscal.providers.fake' => AdjustmentEmitFakeProvider::class]);
        AdjustmentEmitFakeProvider::reset();

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 1, 'fiscal_overage_enabled' => true, 'fiscal_overage_unit_price' => '7.2500']);
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake',
            'document_type' => '04', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-04"), 'status' => 'accepted', 'clave' => 'K-O4-ND',
        ]);

        $first = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            $company->id, 'debit', 'D1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );
        app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale, $company, $sale->customer, [], null, $first));

        $sale2 = $this->completedSale($company, $branch, $user);
        $second = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            $company->id, 'debit', 'D2', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );
        $secondResult = app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale2, $company, $sale2->customer, [], null, $second));
        $this->assertNotNull($secondResult->electronicDocumentId);
        $this->assertSame('7.2500', \App\Models\FiscalConsumption::where('classification', 'overage')->sole()->unit_price);

        $retry = app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale2, $company, $sale2->customer, [], null, $second));
        $this->assertSame($secondResult->electronicDocumentId, $retry->electronicDocumentId);
        $this->assertSame(2, ElectronicDocument::where('document_type', '02')->count());
        $this->assertDatabaseCount('fiscal_consumptions', 2);

        $other = Company::create(['trade_name' => 'X' . uniqid(), 'is_active' => true]);
        $cross = new FiscalDocument(
            $other->id, '02', 'debit', 'DX', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original)
        );

        try {
            app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale2, $other, $sale2->customer, [], null, $cross));
            $this->fail('ND cross-company debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('cross_company', $e->reason);
        }

        \App\Models\CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => false]);

        $sale3 = $this->completedSale($company, $branch, $user);
        $third = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            $company->id, 'debit', 'D3', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );

        try {
            app(FiscalManager::class)->emit(new FiscalEmissionRequest($sale3, $company, $sale3->customer, [], null, $third));
            $this->fail('Fiscal deshabilitado debió bloquear la ND.');
        } catch (\App\Exceptions\FiscalQuotaException $e) {
            $this->assertSame('fiscal_disabled', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_facturaencr_mapper_builds_official_nd_payload(): void
    {
        [$company, $branch, $user] = $this->posContext();
        $sale = $this->completedSale($company, $branch, $user);
        $claveOriginal = str_repeat('9', 50);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'facturaencr',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted', 'clave' => $claveOriginal,
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            $company->id, 'debit', 'D1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(),
            new FiscalDocumentReference($original->id, '01', $claveOriginal, null, null, '02', 'Cargo omitido', 'facturaencr')
        );

        $mapper = new \App\Services\Facturaencr\FacturaencrAdjustmentMapper();
        $payload = $mapper->map($adjustment, $company, $original, '01', ['01']);
        $this->assertSame('02', $payload['tipoDocumento']);
        $this->assertSame($claveOriginal, $payload['referencia'][0]['numero']);
        $this->assertSame('02', $payload['referencia'][0]['codigo']);
        $this->assertNotEmpty($payload['referencia'][0]['fechaEmision']);

        Http::assertNothingSent();
    }

    public function test_adjustment_reference_negatives_and_frozen_snapshot(): void
    {
        [$company, $branch, $user] = $this->posContext();
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5('neg' . uniqid()), 'status' => 'accepted', 'clave' => 'K-NEG',
        ]);

        $mismatch = new FiscalDocumentReference(
            $original->id, '01', 'K-DISTINTA', null, null, '01', 'Motivo', 'fake'
        );
        try {
            $mismatch->validateAgainst($original, $company->id, '03');
            $this->fail('Clave distinta debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('mismatch', $e->reason);
        }

        $missingReason = new FiscalDocumentReference(
            $original->id, '01', 'K-NEG', null, null, '', '', 'fake'
        );
        try {
            $missingReason->validateAgainst($original, $company->id, '03');
            $this->fail('Motivo vacío debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('missing_reason', $e->reason);
        }

        $nc = ElectronicDocument::create([
            'company_id' => $company->id, 'provider' => 'fake', 'document_type' => '03',
            'environment' => 'sandbox', 'idempotency_key' => md5('ncneg' . uniqid()), 'status' => 'accepted', 'clave' => 'K-NC',
        ]);
        try {
            $this->referenceFor($nc)->validateAgainst($nc, $company->id, '02');
            $this->fail('ND sobre NC debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('invalid_modifier', $e->reason);
        }

        try {
            (new FiscalDocument($company->id, '09', 'return', 'R9', $this->receptor(), 'CRC', '1', [$this->line()], $this->totals()))->validate();
            $this->fail('Tipo modificador inválido debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('unsupported_type', $e->reason);
        }

        $frozenTotals = $this->totals();
        $frozenReceptor = $this->receptor();
        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'RFROZEN', $frozenReceptor, 'CRC', '1',
            [$this->line()], $frozenTotals, $this->referenceFor($original), '1010.0000'
        );

        $sale->update(['total' => 99999]);
        $sale->customer->update(['name' => 'Mutado']);

        $this->assertSame('1010.0000', $adjustment->totals['total']);
        $this->assertSame('Receptor', $adjustment->receptor['name']);
        $this->assertSame('K-NEG', $adjustment->reference->clave);
        $adjustment->validate();
        Http::assertNothingSent();
    }

    public function test_fiscal_lines_cover_iva_exento_multi_tax_discount_bcmath(): void
    {
        $tax = new \App\Services\Fiscal\FiscalTaxService();

        foreach ([
            [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13]],
            [['codigo' => '01', 'codigoTarifa' => '02']],
            [['codigo' => '01', 'codigoTarifa' => '03']],
            [['codigo' => '01', 'codigoTarifa' => '04']],
            [['codigo' => '01', 'codigoTarifa' => '01', 'tarifa' => 0]],
            [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13, 'exoneracion' => ['tipoDocumento' => '15', 'numeroDocumento' => 'E-1', 'nombreInstitucion' => 'Inst', 'fechaEmision' => '2026-09-01', 'porcentajeExoneracion' => 50]]],
            [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13], ['codigo' => '99', 'codigoTarifaOtro' => 'OT-01']],
        ] as $taxes) {
            $tax->validateSnapshot(['source' => 'frozen', 'source_version' => '1', 'taxes' => $taxes], '03');
            $tax->validateSnapshot(['source' => 'frozen', 'source_version' => '1', 'taxes' => $taxes], '02');
            $this->assertTrue(true);
        }

        try {
            $tax->validateSnapshot(['source' => 'frozen', 'source_version' => '1', 'taxes' => [['codigo' => '99', 'codigoTarifaOtro' => 'OT-01']]], '03');
            $this->fail('Sin familia IVA debió bloquearse: 0 no se infiere como exento.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        foreach ([0, 8, null] as $ambiguous) {
            try {
                $tax->resolveLegacyTaxRate($ambiguous === null ? null : (float) $ambiguous, '03');
                $this->fail('Tasa legada ambigua debió bloquearse.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $company = Company::create(['trade_name' => 'T' . uniqid(), 'is_active' => true]);
        $lineDiscount = new FiscalDocumentLine('0111100000100', 'Trigo desc', '2.0000', '1000.0000', '100.0000', '1900.0000', [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13]], '247.0000', '2147.0000');
        $lineMulti = new FiscalDocumentLine('0111100000100', 'Trigo multi', '1.0000', '500.0000', '0.0000', '500.0000', [['codigo' => '01', 'codigoTarifa' => '02'], ['codigo' => '99', 'codigoTarifaOtro' => 'OT-01']], '5.0000', '505.0000');
        $document = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'RMULTI', $this->receptor(), 'CRC', '1',
            [$lineDiscount, $lineMulti],
            ['subtotal' => '2400.0000', 'discount_total' => '100.0000', 'tax_total' => '252.0000', 'total' => '2652.0000'],
            new FiscalDocumentReference(1, '01', str_repeat('4', 50), null, '2026-09-27T10:00:00-06:00', '02', 'Corrección parcial', 'facturaencr'), '9999.0000'
        );
        $document->validate();

        $payload = (new \App\Services\Facturaencr\FacturaencrAdjustmentMapper())->map($document, $company, null, '01', ['01']);
        $this->assertCount(2, $payload['detalle']);
        $this->assertSame(100.0, $payload['detalle'][0]['descuento']);
        $this->assertCount(2, $payload['detalle'][1]['impuesto']);
        $this->assertSame(0, bccomp('2652.0000', $document->totals['total'], 4));

        try {
            (new FiscalDocument($company->id, '03', 'r', '1', $this->receptor(), 'CRC', '1', [$this->line('123')], $this->totals(), $this->reference()))->validate();
            $this->fail('CABYS inválido debió bloquearse.');
        } catch (FiscalReferenceException $e) {
            $this->assertSame('invalid_cabys', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_adjustment_documents_are_observable_without_secrets(): void
    {
        config(['fiscal.provider' => 'fake', 'fiscal.providers.fake' => AdjustmentEmitFakeProvider::class]);
        AdjustmentEmitFakeProvider::reset();

        [$company, $branch, $user] = $this->posContext();
        $this->enableFiscal($company, ['fiscal_monthly_quota' => 5]);
        $sale = $this->completedSale($company, $branch, $user);

        $original = ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted',
            'clave' => 'K-OBS', 'consecutivo' => 'C-OBS',
        ]);

        $adjustment = \App\Services\Fiscal\FiscalAdjustmentBuilder::debitNote(
            $company->id, 'debit', 'DOBS', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->referenceFor($original), '1010.0000'
        );
        $result = app(FiscalManager::class)->emit(new FiscalEmissionRequest(
            $sale, $company, $sale->customer, [], null, $adjustment
        ));

        $doc = ElectronicDocument::findOrFail($result->electronicDocumentId);
        $this->assertSame('debit', $doc->source_type);
        $this->assertSame('DOBS', $doc->source_id);
        $this->assertSame($original->id, (int) $doc->original_document_id);
        $this->assertSame('fake', $doc->provider);
        $this->assertSame($adjustment->idempotencyKey(), $doc->idempotency_key);
        $this->assertSame('02', $doc->document_type);
        $this->assertSame('accepted', $doc->status);
        $this->assertNull($doc->last_error_code);
        $this->assertNull($doc->last_error_message);

        foreach (['token', 'secret', 'password', 'api_key', 'apikey'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $doc->getAttributes());
        }

        Http::assertNothingSent();
    }
}

class AdjustmentFakeProvider implements FiscalProviderInterface
{
    private static int $calls = 0;

    public static function requests(): int
    {
        return self::$calls;
    }

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function providerCode(): string
    {
        return 'fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        self::$calls++;

        throw new \RuntimeException('No debe emitir en estas pruebas.');
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        return new FiscalDocumentStatus(
            state: FiscalEmissionResult::STATE_ERROR,
            final: false,
        );
    }
}

class AdjustmentEmitFakeProvider implements FiscalProviderInterface
{
    private static int $calls = 0;

    public static function requests(): int
    {
        return self::$calls;
    }

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function providerCode(): string
    {
        return 'fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        self::$calls++;

        $adjustment = $request->adjustment;

        if ($adjustment === null) {
            throw new \RuntimeException('Este fake solo emite ajustes neutrales.');
        }

        $existing = ElectronicDocument::query()
            ->where('company_id', $request->sale->company_id)
            ->where('sale_id', $request->sale->id)
            ->where('provider', $this->providerCode())
            ->where('document_type', $adjustment->documentType)
            ->first();

        if ($existing !== null) {
            return new FiscalEmissionResult(
                state: FiscalEmissionResult::STATE_ACCEPTED,
                electronicDocumentId: $existing->id,
                providerReference: $existing->provider_document_id,
                fiscalReference: $existing->clave,
            );
        }

        $document = ElectronicDocument::create([
            'company_id' => $request->sale->company_id,
            'sale_id' => $request->sale->id,
            'source_type' => $adjustment->sourceType,
            'source_id' => $adjustment->sourceId,
            'original_document_id' => $adjustment->reference->electronicDocumentId,
            'provider' => $this->providerCode(),
            'document_type' => $adjustment->documentType,
            'environment' => 'sandbox',
            'idempotency_key' => $adjustment->idempotencyKey(),
            'provider_document_id' => 'fake-nc-' . $request->sale->id . '-' . $adjustment->sourceId,
            'status' => 'accepted',
        ]);

        return new FiscalEmissionResult(
            state: FiscalEmissionResult::STATE_ACCEPTED,
            electronicDocumentId: $document->id,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
        );
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        return new FiscalDocumentStatus(
            state: FiscalEmissionResult::STATE_ACCEPTED,
            final: true,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
        );
    }
}
