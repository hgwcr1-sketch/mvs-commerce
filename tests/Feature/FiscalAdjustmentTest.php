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

    public function test_facturaencr_mapper_builds_payload_and_marks_endpoint_pending(): void
    {
        $company = Company::create(['trade_name' => 'M' . uniqid(), 'is_active' => true]);
        $document = \App\Services\Fiscal\FiscalAdjustmentBuilder::creditNote(
            $company->id, 'return', 'R1', $this->receptor(), 'CRC', '1',
            [$this->line()], $this->totals(), $this->reference()
        );

        $mapper = new \App\Services\Facturaencr\FacturaencrAdjustmentMapper();
        $payload = $mapper->map($document, $company);

        $this->assertSame('03', $payload['tipoDocumento']);
        $this->assertSame('109880401', $payload['receptor']['numeroIdentificacion']);
        $this->assertSame('0111100000100', $payload['detalle'][0]['codigoCabys']);
        $this->assertSame('K1', $payload['informacionReferencia']['numero']);

        try {
            $mapper->endpoint('03');
            $this->fail('El endpoint 03 debió marcarse pendiente.');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException) {
            $this->assertTrue(true);
        }

        Http::assertNothingSent();
    }

    public function test_facturaencr_provider_blocks_nc_without_http_document_or_consumption(): void
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
        $this->assertSame('adjustment_endpoint_pending', $result->error->code);
        $this->assertNull($result->electronicDocumentId);
        $this->assertDatabaseCount('fiscal_consumptions', 0);
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
