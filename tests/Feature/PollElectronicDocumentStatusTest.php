<?php

namespace Tests\Feature;

use App\Jobs\EmitElectronicDocument;
use App\Jobs\PollElectronicDocumentStatus;
use App\Jobs\SendElectronicDocumentMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentMailDelivery;
use App\Models\FiscalConsumption;
use App\Models\FiscalDocumentCustody;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\FiscalManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PollElectronicDocumentStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_emission_success_schedules_status_polling(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        [$customer, $sale] = $this->makeEmitableSale($company, $branch);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'queued',
                'documentId' => 'doc-emit-poll',
                'clave' => '506010100000000000010000000000000000115',
                'consecutivo' => '00100001010000000115',
            ], 202),
        ]);
        Bus::fake();

        (new EmitElectronicDocument($sale->id, Sale::DOCUMENT_ELECTRONIC_INVOICE))
            ->handle(app(FiscalManager::class));

        $document = ElectronicDocument::query()->where('sale_id', $sale->id)->sole();

        $this->assertSame('queued', $document->status);
        Bus::assertDispatched(
            PollElectronicDocumentStatus::class,
            fn (PollElectronicDocumentStatus $job): bool => $job->electronicDocumentId === $document->id
                && $job->attempt === 1,
        );
        Http::assertSentCount(1);
    }

    public function test_queued_polling_get_updates_same_document_and_reschedules(): void
    {
        [$company] = $this->fiscalContext();
        $document = $this->makeQueuedDoc($company, ['provider_document_id' => 'doc-poll-1']);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/doc-poll-1' => Http::response([
                'status' => 'queued',
                'documentId' => 'doc-poll-1',
                'clave' => $document->clave,
                'consecutivo' => $document->consecutivo,
            ], 200),
        ]);
        Queue::fake();

        $identity = [
            'id' => $document->id,
            'clave' => $document->clave,
            'consecutivo' => $document->consecutivo,
            'provider_document_id' => $document->provider_document_id,
            'idempotency_key' => $document->idempotency_key,
        ];

        (new PollElectronicDocumentStatus($document->id, 1))->handle(app(FiscalManager::class));

        $document->refresh();
        $this->assertSame('queued', $document->status);
        $this->assertSame($identity['clave'], $document->clave);
        $this->assertSame($identity['consecutivo'], $document->consecutivo);
        $this->assertSame($identity['provider_document_id'], $document->provider_document_id);
        $this->assertSame($identity['idempotency_key'], $document->idempotency_key);
        $this->assertSame(1, ElectronicDocument::query()->count());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'documents/doc-poll-1'));

        Queue::assertPushed(
            PollElectronicDocumentStatus::class,
            fn (PollElectronicDocumentStatus $job): bool => $job->electronicDocumentId === $document->id
                && $job->attempt === 2,
        );
    }

    public function test_polling_accepted_persists_same_identity_and_never_reemits(): void
    {
        [$company] = $this->fiscalContext();
        $document = $this->makeQueuedDoc($company, ['provider_document_id' => 'doc-poll-2']);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/doc-poll-2' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-poll-2',
                'clave' => $document->clave,
                'consecutivo' => $document->consecutivo,
            ], 200),
        ]);
        Queue::fake();

        (new PollElectronicDocumentStatus($document->id, 1))->handle(app(FiscalManager::class));

        $document->refresh();
        $this->assertSame('accepted', $document->status);
        $this->assertNull($document->last_error_code);
        $this->assertSame('doc-poll-2', $document->provider_document_id);
        $this->assertSame(1, ElectronicDocument::query()->count());
        $this->assertSame(1, (int) $document->attempt_number);

        // Un segundo polling sobre estado final: sin HTTP, sin reemisión,
        // sin nueva programación.
        (new PollElectronicDocumentStatus($document->id, 2))->handle(app(FiscalManager::class));

        Http::assertSentCount(1);
        Queue::assertPushed(PollElectronicDocumentStatus::class, 0);
        $this->assertSame(1, ElectronicDocument::query()->count());
        $this->assertSame('accepted', $document->fresh()->status);
    }

    public function test_accepted_polling_dispatches_ce_mail_exactly_once(): void
    {
        [$company, $branch] = $this->fiscalContext();
        [$customer, $sale] = $this->makeEmitableSale($company, $branch);
        $document = $this->makeQueuedDoc($company, [
            'sale_id' => $sale->id,
            'provider_document_id' => 'doc-poll-3',
        ]);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/doc-poll-3' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-poll-3',
                'clave' => $document->clave,
                'consecutivo' => $document->consecutivo,
            ], 200),
        ]);
        Queue::fake();

        (new PollElectronicDocumentStatus($document->id, 1))->handle(app(FiscalManager::class));

        $deliveries = ElectronicDocumentMailDelivery::query()
            ->where('electronic_document_id', $document->id)
            ->get();
        $this->assertCount(1, $deliveries);
        $this->assertSame(ElectronicDocumentMailDelivery::TYPE_AUTOMATIC, $deliveries[0]->delivery_type);
        $this->assertSame($customer->email, $deliveries[0]->recipient);
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_PENDING, $deliveries[0]->status);
        Queue::assertPushed(SendElectronicDocumentMail::class, 1);

        // Estado final: ni nuevo polling ni segunda entrega automática.
        (new PollElectronicDocumentStatus($document->id, 2))->handle(app(FiscalManager::class));

        $this->assertSame(
            1,
            ElectronicDocumentMailDelivery::query()->where('electronic_document_id', $document->id)->count(),
        );
        Queue::assertPushed(SendElectronicDocumentMail::class, 1);
        Queue::assertPushed(PollElectronicDocumentStatus::class, 0);
    }

    public function test_rejected_final_stops_polling(): void
    {
        [$company] = $this->fiscalContext();
        $document = $this->makeQueuedDoc($company, ['provider_document_id' => 'doc-poll-4']);

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/doc-poll-4' => Http::response([
                'status' => 'rejected',
                'documentId' => 'doc-poll-4',
                'haciendaMessage' => 'Rechazado por Hacienda.',
            ], 200),
        ]);
        Queue::fake();

        (new PollElectronicDocumentStatus($document->id, 1))->handle(app(FiscalManager::class));

        $document->refresh();
        $this->assertSame('rejected', $document->status);
        $this->assertSame('HACIENDA_REJECTED', $document->last_error_code);
        $this->assertSame('Rechazado por Hacienda.', $document->last_error_message);

        Http::assertSentCount(1);
        Queue::assertPushed(PollElectronicDocumentStatus::class, 0);
        $this->assertSame(1, ElectronicDocument::query()->count());
    }

    public function test_polling_timeout_keeps_fiscal_identity_and_warns(): void
    {
        [$company] = $this->fiscalContext();
        $document = $this->makeQueuedDoc($company, ['provider_document_id' => 'doc-poll-5']);
        $identity = [
            'clave' => $document->clave,
            'consecutivo' => $document->consecutivo,
            'provider_document_id' => $document->provider_document_id,
            'idempotency_key' => $document->idempotency_key,
        ];

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/doc-poll-5' => Http::response([
                'status' => 'queued',
                'documentId' => 'doc-poll-5',
                'clave' => $document->clave,
                'consecutivo' => $document->consecutivo,
            ], 200),
        ]);
        Queue::fake();
        Log::spy();

        for ($attempt = 1; $attempt <= PollElectronicDocumentStatus::MAX_ATTEMPTS; $attempt++) {
            (new PollElectronicDocumentStatus($document->id, $attempt))->handle(app(FiscalManager::class));
        }

        $document->refresh();
        $this->assertSame('queued', $document->status);
        $this->assertSame($identity['clave'], $document->clave);
        $this->assertSame($identity['consecutivo'], $document->consecutivo);
        $this->assertSame($identity['provider_document_id'], $document->provider_document_id);
        $this->assertSame($identity['idempotency_key'], $document->idempotency_key);
        $this->assertSame(1, ElectronicDocument::query()->count());

        // Cada intento consulta una sola vez y solo reprograma hasta el límite.
        Http::assertSentCount(PollElectronicDocumentStatus::MAX_ATTEMPTS);
        $this->assertSame(
            PollElectronicDocumentStatus::MAX_ATTEMPTS - 1,
            Queue::pushed(PollElectronicDocumentStatus::class)->count(),
        );
        Log::shouldHaveReceived('warning')
            ->with('fiscal.polling.timeout', \Mockery::type('array'));
    }

    public function test_refresh_uses_company_credentials_and_only_get(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->insertFiscalConfig($company);
        $this->actingUser($company, $branch);

        $document = $this->makeQueuedDoc($company, ['provider_document_id' => 'doc-refresh-1']);
        $consumptionsBefore = FiscalConsumption::query()->count();
        $claveBefore = $document->clave;

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/doc-refresh-1' => Http::response([
                'status' => 'queued',
                'documentId' => 'doc-refresh-1',
                'clave' => $document->clave,
                'consecutivo' => $document->consecutivo,
            ], 200),
        ]);

        $this->post(route('fiscal.documents.refresh', $document))
            ->assertRedirect(route('fiscal.documents.show', $document));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'documents/doc-refresh-1')
            && $request->hasHeader('X-API-Key', 'efk_POLLKEY')
            && $request->hasHeader('X-API-Secret', 'efk_POLLSECRET'));

        $this->assertSame($consumptionsBefore, FiscalConsumption::query()->count());
        $this->assertSame(1, ElectronicDocument::query()->count());
        $document->refresh();
        $this->assertSame('queued', $document->status);
        $this->assertSame($claveBefore, $document->clave);
        $this->assertSame('doc-refresh-1', $document->provider_document_id);
    }

    public function test_refresh_final_accepted_short_circuits_without_http(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $document = $this->makeQueuedDoc($company, [
            'provider_document_id' => 'doc-refresh-2',
            'status' => 'accepted',
        ]);

        $this->post(route('fiscal.documents.refresh', $document))
            ->assertRedirect(route('fiscal.documents.show', $document))
            ->assertSessionHas('status');

        Http::assertNothingSent();
        $this->assertSame('accepted', $document->fresh()->status);
    }

    public function test_refresh_is_isolated_per_company_and_never_queries_foreign_documents(): void
    {
        [$companyA, $branchA] = $this->fiscalContext();
        [$companyB] = $this->fiscalContext();
        $this->enableFiscal($companyA);
        $this->insertFiscalConfig($companyA);
        $this->actingUser($companyA, $branchA);

        $foreign = $this->makeQueuedDoc($companyB, ['provider_document_id' => 'doc-foreign']);

        $this->post(route('fiscal.documents.refresh', $foreign))->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame('queued', $foreign->fresh()->status);
    }

    private function fiscalContext(): array
    {
        $company = Company::create([
            'trade_name' => 'POLL'.uniqid(),
            'legal_name' => 'POLL S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P'.uniqid(),
            'is_active' => true,
        ]);

        return [$company, $branch];
    }

    private function enableFiscal(Company $company): void
    {
        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true]);
    }

    private function insertFiscalConfig(Company $company): void
    {
        DB::table('company_fiscal_configs')->insert([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'provider_api_key' => encrypt('efk_POLLKEY', false),
            'provider_api_secret' => encrypt('efk_POLLSECRET', false),
            'auto_emit_enabled' => true,
            'fiscal_start_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingUser(Company $company, Branch $branch): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

        $permission = Permission::firstOrCreate(
            ['name' => 'fiscal.ver'],
            ['label' => 'fiscal.ver', 'module' => 'Facturación Electrónica', 'is_active' => true],
        );
        $role->permissions()->attach($permission->id);

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);

        return $user;
    }

    private function makeQueuedDoc(Company $company, array $attributes = []): ElectronicDocument
    {
        $document = ElectronicDocument::create(array_merge([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'poll-'.md5(uniqid('p', true)),
            'provider_document_id' => null,
            'clave' => '506010100000000000010000000000000000101',
            'consecutivo' => '00100001010000000101',
            'attempt_number' => 1,
            'status' => 'queued',
        ], $attributes));

        FiscalDocumentCustody::create([
            'electronic_document_id' => $document->id,
            'payload' => ['clave' => $document->clave, 'detalle' => [['unidadMedida' => 'Unid']]],
            'response' => ['status' => 'queued'],
        ]);

        return $document;
    }

    private function makeEmitableSale(Company $company, Branch $branch): array
    {
        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '109880401',
            'name' => 'Cliente Poll',
            'email' => 'cliente.poll@example.com',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

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
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('poll', true)),
            'sale_number' => 'POS-POLL-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 130,
            'rounding_total' => 0,
            'total' => 1130,
            'paid_total' => 1130,
            'balance_due' => 0,
            'completed_at' => now(),
            'customer_id' => $customer->id,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'cash_session_id' => null,
            'payment_method_id' => $paymentMethod->id,
            'created_by' => $user->id,
            'status' => 'completed',
            'amount' => 1130,
            'received_amount' => 1130,
            'change_amount' => 0,
        ]);

        $item = SaleItem::create([
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
            'tax_total' => 130,
            'total' => 1130,
            'unit_cost' => 500,
        ]);

        $item->update([
            'tax_code' => '01',
            'tax_rate_code' => '08',
            'fiscal_source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
            'fiscal_source_version' => 'v4.4',
            'fiscal_snapshot' => [
                'source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
                'source_version' => 'v4.4',
                'taxes' => [
                    ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13.0],
                ],
            ],
        ]);

        return [$customer->fresh(), $sale];
    }
}
