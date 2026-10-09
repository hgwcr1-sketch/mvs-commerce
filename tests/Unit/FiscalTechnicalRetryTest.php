<?php

namespace Tests\Unit;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\Jobs\EmitElectronicDocument;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\FiscalDocumentCustody;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Facturaencr\FacturaencrClient;
use App\Services\Facturaencr\FacturaencrEmissionService;
use App\Services\Facturaencr\FacturaencrInvoiceMapper;
use App\Services\Fiscal\FiscalManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Mockery;

class FiscalTechnicalRetryTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUS_ERROR_CODE = 'validation_error';

    private const PREVIOUS_ERROR_MESSAGE = 'Hay 2 errores en el payload de Factura: detalle.1.descuento, detalle.9.descuento.';

    public function test_error_without_provider_identity_retries_same_document_and_key_with_one_post(): void
    {
        Http::fake([
            'api.facturaencr.com/*' => Http::response([
                'documentId' => 'doc-retry-1',
                'clave' => str_repeat('9', 50),
                'consecutivo' => '00100001010000000009',
                'status' => 'queued',
            ], 202),
        ]);

        [$company, $customer, $sale, $item] = $this->context();
        $document = $this->existingDocument($sale, $company);

        $result = $this->service()->emit(
            $sale, $company, $customer, [$item], $sale->payments->first(), $this->client(), 'sandbox'
        );

        Http::assertSentCount(1);

        $this->assertSame($document->id, $result->id, 'se reutiliza el MISMO ElectronicDocument');
        $this->assertSame($document->idempotency_key, $result->fresh()->idempotency_key, 'misma idempotency_key');
        $this->assertSame(2, (int) $result->fresh()->attempt_number, 'attempt_number 1 -> 2');
        $this->assertSame('queued', $result->fresh()->status);
        $this->assertSame('doc-retry-1', $result->fresh()->provider_document_id);
        $this->assertNull($result->fresh()->last_error_code);
        $this->assertSame(
            1,
            ElectronicDocument::query()->where('sale_id', $sale->id)->count(),
            'cero duplicación de documentos'
        );
        $this->assertSame(1, FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)->count());
    }

    public function test_accepted_document_is_blocked(): void
    {
        Http::fake();
        [$company, $customer, $sale, $item] = $this->context();
        $document = $this->existingDocument($sale, $company, [
            'status' => 'accepted',
            'provider_document_id' => 'doc-accepted',
            'clave' => str_repeat('1', 50),
            'consecutivo' => '00100001010000000001',
        ]);

        $result = $this->service()->emit(
            $sale, $company, $customer, [$item], $sale->payments->first(), $this->client(), 'sandbox'
        );

        Http::assertSentCount(0);
        $this->assertSame($document->id, $result->id);
        $this->assertSame('accepted', $result->fresh()->status);
        $this->assertSame(1, (int) $result->fresh()->attempt_number);
    }

    public function test_error_with_provider_document_id_is_blocked(): void
    {
        Http::fake();
        [$company, $customer, $sale, $item] = $this->context();
        $document = $this->existingDocument($sale, $company, [
            'provider_document_id' => 'doc-with-identity',
        ]);

        $result = $this->service()->emit(
            $sale, $company, $customer, [$item], $sale->payments->first(), $this->client(), 'sandbox'
        );

        Http::assertSentCount(0);
        $this->assertSame($document->id, $result->id);
        $this->assertSame('error', $result->fresh()->status);
        $this->assertSame(1, (int) $result->fresh()->attempt_number);
        $this->assertSame(self::PREVIOUS_ERROR_CODE, $result->fresh()->last_error_code);
    }

    public function test_rejected_with_fiscal_identity_is_blocked(): void
    {
        Http::fake();
        [$company, $customer, $sale, $item] = $this->context();
        $document = $this->existingDocument($sale, $company, [
            'status' => 'rejected',
            'provider_document_id' => 'doc-rejected',
            'clave' => str_repeat('2', 50),
            'consecutivo' => '00100001010000000002',
        ]);

        $result = $this->service()->emit(
            $sale, $company, $customer, [$item], $sale->payments->first(), $this->client(), 'sandbox'
        );

        Http::assertSentCount(0);
        $this->assertSame($document->id, $result->id);
        $this->assertSame('rejected', $result->fresh()->status);
        $this->assertSame(1, (int) $result->fresh()->attempt_number);
    }

    public function test_retry_payload_keeps_corrected_discount_serialization(): void
    {
        Http::fake([
            'api.facturaencr.com/*' => Http::response([
                'documentId' => 'doc-retry-discount',
                'clave' => str_repeat('7', 50),
                'consecutivo' => '00100001010000000007',
                'status' => 'queued',
            ], 202),
        ]);

        [$company, $customer, $sale, $item] = $this->context(['discount_total' => 100]);
        $this->existingDocument($sale, $company);

        $this->service()->emit(
            $sale, $company, $customer, [$item], $sale->payments->first(), $this->client(), 'sandbox'
        );

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $line = $request['detalle'][0] ?? null;

            return is_array($line['descuento'] ?? null)
                && $line['descuento'] == [['montoDescuento' => 100, 'codigoDescuento' => '07']]
                && $line['precioUnitario'] == 1000;
        });

        $this->assertSame(
            1,
            ElectronicDocument::query()->where('sale_id', $sale->id)->count(),
            'el retry no crea un segundo comprobante'
        );
    }

    public function test_job_dispatches_emission_when_document_is_technical_retryable(): void
    {
        [$company, , $sale] = $this->context();
        $document = $this->existingDocument($sale, $company);

        $provider = Mockery::mock(FiscalProviderInterface::class);
        $provider->shouldReceive('providerCode')->once()->andReturn('facturaencr');

        $manager = Mockery::mock(FiscalManager::class);
        $manager->shouldReceive('provider')->once()->andReturn($provider);
        $manager->shouldReceive('emit')->once()->andReturn(new FiscalEmissionResult(
            state: FiscalEmissionResult::STATE_QUEUED,
            electronicDocumentId: $document->id,
        ));

        (new EmitElectronicDocument($sale->id, Sale::DOCUMENT_ELECTRONIC_INVOICE))->handle($manager);

        $this->assertSame(
            1,
            ElectronicDocument::query()->where('sale_id', $sale->id)->count(),
            'el retry no crea un segundo comprobante'
        );
        $this->assertTrue($document->fresh()->isTechnicalRetryable());
    }

    public function test_job_blocks_emission_when_document_is_final(): void
    {
        [$company, , $sale] = $this->context();
        $this->existingDocument($sale, $company, [
            'status' => 'accepted',
            'provider_document_id' => 'doc-final',
            'clave' => str_repeat('3', 50),
            'consecutivo' => '00100001010000000003',
        ]);

        $provider = Mockery::mock(FiscalProviderInterface::class);
        $provider->shouldReceive('providerCode')->once()->andReturn('facturaencr');

        $manager = Mockery::mock(FiscalManager::class);
        $manager->shouldReceive('provider')->once()->andReturn($provider);
        $manager->shouldNotReceive('emit');

        (new EmitElectronicDocument($sale->id, Sale::DOCUMENT_ELECTRONIC_INVOICE))->handle($manager);

        $this->assertTrue(true);
    }

    private function service(): FacturaencrEmissionService
    {
        return new FacturaencrEmissionService($this->client());
    }

    private function client(): FacturaencrClient
    {
        return new FacturaencrClient('efk_test', 'efs_test');
    }

    private function existingDocument(Sale $sale, Company $company, array $attributes = []): ElectronicDocument
    {
        return ElectronicDocument::create(array_merge([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => (new FacturaencrInvoiceMapper)->idempotencyKey($sale, '01'),
            'status' => 'error',
            'attempt_number' => 1,
            'last_error_code' => self::PREVIOUS_ERROR_CODE,
            'last_error_message' => self::PREVIOUS_ERROR_MESSAGE,
        ], $attributes));
    }

    /**
     * @return array{0: Company, 1: Customer, 2: Sale, 3: SaleItem}
     */
    private function context(array $itemOverrides = []): array
    {
        $company = Company::create([
            'trade_name' => 'Retry Comercio',
            'legal_name' => 'Retry S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '123456789',
            'name' => 'Juan Pérez',
        ]);

        $paymentMethod = PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'cash',
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_system' => true,
            'is_active' => true,
            'affects_cash' => true,
            'requires_reference' => false,
            'allows_change' => true,
        ]);

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sale_number' => 'POS-RETRY-1',
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 900,
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

        $item = SaleItem::create(array_merge([
            'sale_id' => $sale->id,
            'product_code' => 'P01',
            'cabys_code' => '5060101000000',
            'description' => 'Producto con descuento',
            'unit_code' => 'un',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'discount_total' => 100,
            'subtotal' => 900,
            'tax_rate' => 13,
            'tax_code' => '01',
            'tax_rate_code' => '08',
            'tax_total' => 117,
            'total' => 1017,
            'unit_cost' => 500,
        ], $itemOverrides));

        return [$company, $customer, $sale, $item];
    }
}
