<?php

namespace Tests\Unit;

use App\Contracts\Fiscal\FiscalProviderInterface;
use App\DTOs\Fiscal\FiscalDocumentStatus;
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
use App\Services\Facturaencr\FacturaencrProvider;
use App\Services\Fiscal\FiscalManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class FiscalManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_manager_resolves_default_provider_from_config(): void
    {
        $this->assertSame('facturaencr', config('fiscal.provider'));

        $provider = (new FiscalManager())->provider();

        $this->assertInstanceOf(FiscalProviderInterface::class, $provider);
        $this->assertSame('facturaencr', $provider->providerCode());
    }

    public function test_container_binds_fiscal_provider_interface_through_manager(): void
    {
        $provider = app(FiscalProviderInterface::class);

        $this->assertInstanceOf(FiscalProviderInterface::class, $provider);
        $this->assertSame('facturaencr', $provider->providerCode());
    }

    public function test_manager_emits_through_resolved_provider(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-mgr',
                'clave' => '506010100000000000010000000000000000000',
            ], 200),
        ]);

        $result = (new FiscalManager())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        $this->assertSame('doc-mgr', $result->providerReference);
        $this->assertNotNull($result->electronicDocumentId);
        $this->assertSame(
            'facturaencr',
            ElectronicDocument::find($result->electronicDocumentId)->provider
        );
    }

    public function test_manager_fetch_status_resolves_provider_from_document(): void
    {
        [$company, , $sale] = $this->prepareData();
        $document = $this->createDocument($company, $sale, [
            'provider' => 'facturaencr',
            'provider_document_id' => 'doc-42',
            'clave' => '506010100000000000010000000000000000001',
            'status' => 'accepted',
        ]);

        $status = (new FiscalManager())->fetchStatus($document);

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $status->state);
        $this->assertTrue($status->final);
        $this->assertSame('506010100000000000010000000000000000001', $status->fiscalReference);
        Http::assertNothingSent();
    }

    public function test_manager_selects_alternate_provider_from_config(): void
    {
        Config::set('fiscal.provider', 'fake');
        Config::set('fiscal.providers.fake', FakeFiscalProvider::class);

        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $manager = new FiscalManager();
        $provider = $manager->provider();
        $this->assertNotInstanceOf(FacturaencrProvider::class, $provider);
        $this->assertSame('fake', $provider->providerCode());

        $result = $manager->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item])
        );

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        $this->assertSame('fake-1', $result->providerReference);
        $this->assertCount(1, $provider->emitted);
        Http::assertNothingSent();
    }

    public function test_consumer_depends_on_manager_not_on_concrete_provider(): void
    {
        Config::set('fiscal.provider', 'fake');
        Config::set('fiscal.providers.fake', FakeFiscalProvider::class);

        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $manager = new FiscalManager();
        $consumer = new FiscalConsumer($manager);

        $result = $consumer->run(new FiscalEmissionRequest($sale, $company, $customer, [$item]));

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $result->state);
        $this->assertNotInstanceOf(FacturaencrProvider::class, $manager->provider());
        Http::assertNothingSent();
    }

    public function test_manager_routes_status_to_document_provider(): void
    {
        Config::set('fiscal.providers.fake', FakeFiscalProvider::class);

        [$company, , $sale] = $this->prepareData();
        $document = $this->createDocument($company, $sale, [
            'provider' => 'fake',
            'provider_document_id' => 'doc-fake',
            'status' => 'queued',
        ]);

        $manager = new FiscalManager();
        $provider = $manager->provider('fake');
        $status = $manager->fetchStatus($document);

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $status->state);
        $this->assertSame($document->id, $provider->fetchedDocumentIds[0]);
        Http::assertNothingSent();
    }

    public function test_manager_keeps_errors_neutral(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'error' => 'validation_error',
                'message' => 'Invalid input',
            ], 400),
        ]);

        $result = (new FiscalManager())->emit(
            new FiscalEmissionRequest($sale, $company, $customer, [$item], $sale->payments->first())
        );

        $this->assertTrue($result->isError());
        $this->assertSame('validation', $result->error->category);
        $this->assertFalse($result->error->retryable);
    }

    public function test_existing_facturaencr_document_remains_compatible(): void
    {
        [$company, , $sale] = $this->prepareData();
        $document = $this->createDocument($company, $sale, [
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'document_type' => '01',
            'idempotency_key' => 'compat-key',
            'provider_document_id' => 'doc-compat',
            'clave' => '506010100000000000010000000000000000002',
            'consecutivo' => '001-001-000007',
            'status' => 'accepted',
        ]);

        $status = (new FiscalManager())->fetchStatus($document);

        $this->assertSame(FiscalEmissionResult::STATE_ACCEPTED, $status->state);
        Http::assertNothingSent();

        $document->refresh();
        $this->assertSame('facturaencr', $document->provider);
        $this->assertSame('sandbox', $document->environment);
        $this->assertSame('01', $document->document_type);
        $this->assertSame('compat-key', $document->idempotency_key);
        $this->assertSame('506010100000000000010000000000000000002', $document->clave);
        $this->assertSame('001-001-000007', $document->consecutivo);
        $this->assertSame('accepted', $document->status);
    }

    public function test_manager_throws_for_unconfigured_provider(): void
    {
        [$company, , $sale] = $this->prepareData();
        $document = $this->createDocument($company, $sale, [
            'provider' => 'desconocido',
            'status' => 'queued',
        ]);

        try {
            (new FiscalManager())->fetchStatus($document);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('desconocido', $e->getMessage());
        }
    }

    private function createDocument(Company $company, Sale $sale, array $attributes): ElectronicDocument
    {
        return ElectronicDocument::create(array_merge([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'mgr-' . $sale->id,
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

class FakeFiscalProvider implements FiscalProviderInterface
{
    public array $emitted = [];

    public array $fetchedDocumentIds = [];

    public function providerCode(): string
    {
        return 'fake';
    }

    public function emit(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        $this->emitted[] = $request;

        return new FiscalEmissionResult(
            state: FiscalEmissionResult::STATE_ACCEPTED,
            providerReference: 'fake-1',
            fiscalReference: 'fiscal-fake-1',
        );
    }

    public function fetchStatus(ElectronicDocument $document): FiscalDocumentStatus
    {
        $this->fetchedDocumentIds[] = $document->id;

        return new FiscalDocumentStatus(
            state: FiscalEmissionResult::STATE_ACCEPTED,
            final: true,
            providerReference: $document->provider_document_id,
            fiscalReference: $document->clave,
        );
    }
}

class FiscalConsumer
{
    public function __construct(private readonly FiscalManager $manager)
    {
    }

    public function run(FiscalEmissionRequest $request): FiscalEmissionResult
    {
        return $this->manager->emit($request);
    }
}
