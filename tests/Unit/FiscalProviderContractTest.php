<?php

namespace Tests\Unit;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
use App\DTOs\Fiscal\FiscalError;
use App\DTOs\Fiscal\FiscalEmissionRequest;
use App\DTOs\Fiscal\FiscalEmissionResult;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Facturaencr\FacturaencrEmissionService;
use App\Services\Facturaencr\FacturaencrProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class FiscalProviderContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_provider_implements_mvs_fiscal_contract(): void
    {
        $provider = new FacturaencrProvider();

        $this->assertInstanceOf(FiscalProviderInterface::class, $provider);
        $this->assertSame('facturaencr', $provider->providerCode());
    }

    public function test_emit_returns_neutral_result_on_accepted_response(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-123',
                'clave' => '506010100000000000010000000000000000000',
                'consecutivo' => '001-001-000001',
            ], 200),
        ]);

        $result = (new FacturaencrProvider())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertInstanceOf(FiscalEmissionResult::class, $result);
        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        $this->assertTrue($result->isFinal());
        $this->assertFalse($result->isError());
        $this->assertNull($result->error);
        $this->assertSame('doc-123', $result->providerReference);
        $this->assertSame('506010100000000000010000000000000000000', $result->fiscalReference);
        $this->assertSame(
            ElectronicDocument::where('sale_id', $sale->id)->first()->id,
            $result->electronicDocumentId
        );
    }

    public function test_emit_translates_provider_lifecycle_states_to_mvs_pending(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'signing',
                'documentId' => 'doc-signing',
            ], 202),
        ]);

        $result = (new FacturaencrProvider())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertSame(FiscalEmissionResult::STATE_PENDING, $result->state);
        $this->assertFalse($result->isFinal());
        $this->assertFalse($result->isError());
        $this->assertSame('signing', ElectronicDocument::where('sale_id', $sale->id)->first()->status);
    }

    public function test_emit_translates_validation_exception_to_fiscal_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale, 12);
        Http::fake();

        $result = (new FacturaencrProvider())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertTrue($result->isError());
        $this->assertSame(FiscalEmissionResult::STATE_ERROR, $result->state);
        $this->assertInstanceOf(FiscalError::class, $result->error);
        $this->assertSame(FiscalError::CATEGORY_VALIDATION, $result->error->category);
        $this->assertFalse($result->error->retryable);
        $this->assertSame('validation_failed', $result->error->code);
        $this->assertArrayHasKey('detalle[0]_impuesto', $result->error->context ?? []);
        Http::assertNothingSent();
    }

    public function test_emit_translates_http_validation_error_to_neutral_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'error' => 'validation_error',
                'message' => 'Validation failed',
            ], 400),
        ]);

        $result = (new FacturaencrProvider())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertTrue($result->isError());
        $this->assertSame('validation_error', $result->error->code);
        $this->assertSame(FiscalError::CATEGORY_VALIDATION, $result->error->category);
        $this->assertFalse($result->error->retryable);
    }

    public function test_emit_marks_rate_limit_error_as_retryable(): void
    {
        Config::set('facturaencr.max_retries', 0);
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'error' => 'rate_limit',
                'message' => 'Rate limited',
            ], 429),
        ]);

        $result = (new FacturaencrProvider())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertTrue($result->isError());
        $this->assertSame(FiscalError::CATEGORY_RATE_LIMIT, $result->error->category);
        $this->assertTrue($result->error->retryable);
    }

    public function test_emit_marks_connection_error_as_network_retryable(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake(fn () => throw new ConnectionException('network timeout'));

        $result = (new FacturaencrProvider())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertTrue($result->isError());
        $this->assertSame('CONNECTION_TIMEOUT', $result->error->code);
        $this->assertSame(FiscalError::CATEGORY_NETWORK, $result->error->category);
        $this->assertTrue($result->error->retryable);
        $this->assertStringContainsString('network timeout', $result->error->message);
    }

    public function test_emit_translates_scope_mismatch_to_validation_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);
        $otherCompany = Company::create([
            'trade_name' => 'Otra',
            'legal_name' => 'Otra S.A.',
            'identification_number' => '3101009999',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Http::fake();

        $result = (new FacturaencrProvider())->emit(
            new FiscalEmissionRequest($sale, $otherCompany, $customer, [$item])
        );

        $this->assertTrue($result->isError());
        $this->assertSame(FiscalError::CATEGORY_VALIDATION, $result->error->category);
        $this->assertSame('invalid_request', $result->error->code);
        Http::assertNothingSent();
    }

    public function test_fetch_status_returns_neutral_status_without_http_for_final_document(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $document = $this->createDocument($company, $sale, [
            'provider_document_id' => 'doc-final',
            'clave' => '506010100000000000010000000000000000001',
            'status' => 'accepted',
        ]);

        $status = (new FacturaencrProvider())->fetchStatus($document);

        $this->assertInstanceOf(FiscalDocumentStatus::class, $status);
        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $status->state);
        $this->assertTrue($status->final);
        $this->assertSame('doc-final', $status->providerReference);
        $this->assertSame('506010100000000000010000000000000000001', $status->fiscalReference);
        $this->assertNull($status->message);
        Http::assertNothingSent();
    }

    public function test_fetch_status_rejected_carries_provider_message(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $document = $this->createDocument($company, $sale, [
            'provider_document_id' => 'doc-rejected',
            'status' => 'queued',
        ]);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/doc-rejected' => Http::response([
                'status' => 'rejected',
                'haciendaMessage' => 'Clave duplicada -400',
            ], 200),
        ]);

        $status = (new FacturaencrProvider())->fetchStatus($document);

        $this->assertSame(FiscalEmissionResult::STATE_REJECTED, $status->state);
        $this->assertTrue($status->final);
        $this->assertStringContainsString('Clave duplicada', $status->message);
    }

    private function createDocument(Company $company, Sale $sale, array $attributes): ElectronicDocument
    {
        return ElectronicDocument::create(array_merge([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'contract-' . $sale->id,
            'status' => 'queued',
        ], $attributes));
    }

    private function prepareData(): array
    {
        $company = Company::create([
            'trade_name' => 'Test Comercio',
            'legal_name' => 'Test S.A.',
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
            'identification' => '1234567890',
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

    private function createItem(Sale $sale, float $taxRate = 13): SaleItem
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
            'tax_rate' => $taxRate,
            'tax_total' => $taxRate === 13 ? 117 : 120,
            'total' => $taxRate === 13 ? 1017 : 1120,
            'unit_cost' => 500,
        ]);
    }
}
