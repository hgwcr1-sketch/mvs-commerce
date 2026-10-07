<?php

namespace Tests\Feature;

use App\Mail\ComprobanteElectronicoAceptadoMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentMailDelivery;
use App\Models\FiscalConsumption;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Facturaencr\FacturaencrEmissionService;
use App\Services\Fiscal\ElectronicDocumentMailDispatcher;
use App\Services\Fiscal\FiscalCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ElectronicDocumentAcceptedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_accepted_invoice_sends_one_automatic_email_with_available_attachments(): void
    {
        Mail::fake();
        [$company, , , , $sale] = $this->context('receptor@example.com');
        $document = $this->emitAccepted($company, $sale, true);

        $this->assertSame('accepted', $document->status);

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame(ElectronicDocumentMailDelivery::TYPE_AUTOMATIC, $delivery->delivery_type);
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('receptor@example.com', $delivery->recipient);
        $this->assertNull($delivery->user_id);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame($company->id, $delivery->company_id);
        $this->assertSame($document->id, $delivery->electronic_document_id);

        $base = $document->consecutivo;
        $this->assertContains($base.'.pdf', $delivery->attachments);
        $this->assertContains($base.'-xml-firmado.xml', $delivery->attachments);
        $this->assertContains($base.'-respuesta-hacienda.json', $delivery->attachments);

        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, function (ComprobanteElectronicoAceptadoMail $mail) use ($delivery) {
            return $mail->hasTo($delivery->recipient)
                && str_contains($mail->envelope()->subject, 'Comprobante electrónico aceptado')
                && str_contains($mail->envelope()->subject, 'Factura electrónica')
                && $mail->attachmentNames() === $delivery->attachments
                && str_contains($mail->render(), 'Procesado mediante MVS Commerce')
                && str_contains($mail->render(), 'Aceptado');
        });

        $this->assertSame(1, ElectronicDocument::count());
        $this->assertSame(0, FiscalConsumption::count());
    }

    public function test_all_document_types_share_the_generic_accepted_flow(): void
    {
        Mail::fake();

        foreach (['01' => 'Factura electrónica', '04' => 'Tiquete electrónico', '03' => 'Nota de crédito', '02' => 'Nota de débito'] as $type => $label) {
            [$company, , , , $sale] = $this->context('tipo'.$type.'@example.com', ['trade_name' => 'Empresa '.$type]);
            $document = $this->acceptedDocument($company, $sale, $type, '50601010000000000001000000000000000000'.$type);

            app(FiscalCustodyService::class)->record($document, ['clave' => $document->clave], ['status' => 'accepted', 'xml' => '<r:Ok/>']);

            $delivery = ElectronicDocumentMailDelivery::query()
                ->where('electronic_document_id', $document->id)
                ->sole();

            $this->assertSame(ElectronicDocumentMailDelivery::STATUS_SENT, $delivery->status);
            $this->assertSame('tipo'.$type.'@example.com', $delivery->recipient);
        }

        $this->assertSame(4, ElectronicDocumentMailDelivery::count());

        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 4);

        $subjects = Mail::sent(ComprobanteElectronicoAceptadoMail::class)
            ->map(fn (ComprobanteElectronicoAceptadoMail $mail) => $mail->envelope()->subject)
            ->all();

        foreach (['Factura electrónica', 'Tiquete electrónico', 'Nota de crédito', 'Nota de débito'] as $label) {
            $this->assertTrue(
                collect($subjects)->contains(fn (string $subject) => str_contains($subject, $label)),
                'Falta asunto con '.$label
            );
        }
    }

    public function test_polling_and_repeated_custody_never_duplicate_the_delivery(): void
    {
        Mail::fake();
        [$company, , , , $sale] = $this->context('poll@example.com');

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response([
                'status' => 'pending',
                'documentId' => 'doc-poll',
                'clave' => '506010100000000000010000000000000000001',
            ], 202),
            'api.facturaencr.com/v2/efactura/documents/doc-poll' => Http::response([
                'status' => 'accepted',
                'documentId' => 'doc-poll',
                'clave' => '506010100000000000010000000000000000001',
                'consecutivo' => '001-001-0000001',
                'xml' => '<r:RespuestaStatu>OK</r:RespuestaStatu>',
            ], 200),
        ]);

        $service = new FacturaencrEmissionService;
        $document = $service->emit($sale, $company, $sale->customer, [$this->item($sale)], $sale->payments->first());

        $this->assertSame('pending', $document->status);
        $this->assertSame(0, ElectronicDocumentMailDelivery::count());
        Mail::assertNothingSent();

        $service->syncStatus($document);
        $this->assertSame('accepted', $document->fresh()->status);
        $this->assertSame(1, ElectronicDocumentMailDelivery::count());

        $service->syncStatus($document->fresh());

        $custody = app(FiscalCustodyService::class);
        $fresh = $document->fresh();
        $custody->record($fresh, ['clave' => $fresh->clave, 'xml' => '<r:Ya/>'], ['status' => 'accepted']);
        $custody->recordResponse($fresh, ['status' => 'accepted']);
        app(ElectronicDocumentMailDispatcher::class)->dispatchAccepted($fresh);

        $this->assertSame(1, ElectronicDocumentMailDelivery::count());
        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 1);
        $this->assertSame(1, ElectronicDocument::count());
    }

    public function test_missing_receiver_email_is_skipped_without_sending(): void
    {
        Mail::fake();
        [$company, , , , $sale] = $this->context(null);
        $document = $this->emitAccepted($company, $sale, true);

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertNull($delivery->recipient);
        $this->assertSame('Sin correo registrado', $delivery->message);
        $this->assertNull($delivery->sent_at);
        Mail::assertNothingSent();
        $this->assertSame('accepted', $document->fresh()->status);
    }

    public function test_missing_xml_still_sends_the_available_files(): void
    {
        Mail::fake();
        [$company, , , , $sale] = $this->context('sinxml@example.com');
        $document = $this->emitAccepted($company, $sale, false);

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_SENT, $delivery->status);
        $this->assertContains($document->consecutivo.'.pdf', $delivery->attachments);
        $this->assertContains($document->consecutivo.'-respuesta-hacienda.json', $delivery->attachments);
        $this->assertFalse(collect($delivery->attachments)->contains(fn (string $name) => str_contains($name, 'xml')));
        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 1);
    }

    public function test_smtp_failure_marks_failed_without_touching_the_accepted_document(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        [$company, , , , $sale] = $this->context('fallo@example.com');
        $document = $this->emitAccepted($company, $sale, true);

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('SMTP down', (string) $delivery->last_error);
        $this->assertNotNull($delivery->last_error);
        $this->assertSame('accepted', $document->fresh()->status);
        $this->assertSame(1, ElectronicDocument::count());
        $this->assertSame(0, FiscalConsumption::count());
    }

    public function test_company_isolation_keeps_recipients_and_files_separated(): void
    {
        Mail::fake();
        [$companyA, , , , $saleA] = $this->context('una@example.com', ['trade_name' => 'Empresa A', 'identification_number' => '3101000001']);
        [$companyB, , , , $saleB] = $this->context('dos@example.com', ['trade_name' => 'Empresa B', 'identification_number' => '3101000002']);

        $docA = $this->acceptedDocument($companyA, $saleA, '01', '506010100000000000010000000000000000001');
        $docB = $this->acceptedDocument($companyB, $saleB, '01', '506010100000000000010000000000000000002');

        $custody = app(FiscalCustodyService::class);
        $custody->record($docA, ['clave' => $docA->clave], ['status' => 'accepted', 'xml' => '<r:A/>']);
        $custody->record($docB, ['clave' => $docB->clave], ['status' => 'accepted', 'xml' => '<r:B/>']);

        $this->assertSame(2, ElectronicDocumentMailDelivery::count());

        $deliveryA = ElectronicDocumentMailDelivery::query()->where('electronic_document_id', $docA->id)->sole();
        $deliveryB = ElectronicDocumentMailDelivery::query()->where('electronic_document_id', $docB->id)->sole();

        $this->assertSame($companyA->id, $deliveryA->company_id);
        $this->assertSame('una@example.com', $deliveryA->recipient);
        $this->assertSame($companyB->id, $deliveryB->company_id);
        $this->assertSame('dos@example.com', $deliveryB->recipient);

        $baseA = $docA->consecutivo;
        $baseB = $docB->consecutivo;
        $this->assertNotSame($baseA, $baseB);
        foreach ($deliveryA->attachments as $name) {
            $this->assertStringStartsWith($baseA, $name);
            $this->assertStringNotContainsString($baseB, $name);
        }
        foreach ($deliveryB->attachments as $name) {
            $this->assertStringStartsWith($baseB, $name);
            $this->assertStringNotContainsString($baseA, $name);
        }
        $this->assertSame([], array_intersect($deliveryA->attachments, $deliveryB->attachments));

        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 2);
    }

    public function test_pending_and_rejected_documents_never_send(): void
    {
        Mail::fake();
        [$company, , , , $sale] = $this->context('nunca@example.com');
        $custody = app(FiscalCustodyService::class);

        $pending = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'cemail-pending',
            'clave' => '506010100000000000010000000000000000003',
            'status' => 'pending',
        ]);
        $rejected = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '04',
            'environment' => 'sandbox',
            'idempotency_key' => 'cemail-rejected',
            'clave' => '506010100000000000010000000000000000004',
            'status' => 'rejected',
        ]);

        $custody->record($pending, ['clave' => $pending->clave], ['status' => 'pending']);
        $custody->record($rejected, ['clave' => $rejected->clave], ['status' => 'rejected']);

        $this->assertSame(0, ElectronicDocumentMailDelivery::count());
        Mail::assertNothingSent();
    }

    public function test_dispatch_is_idempotent_and_keeps_the_automatic_audit_row(): void
    {
        Mail::fake();
        [$company, , , , $sale] = $this->context('idem@example.com');
        $document = $this->acceptedDocument($company, $sale, '01', '506010100000000000010000000000000000005');
        $custody = app(FiscalCustodyService::class);
        $custody->record($document, ['clave' => $document->clave], ['status' => 'accepted', 'xml' => '<r:Ok/>']);

        $dispatcher = app(ElectronicDocumentMailDispatcher::class);
        $this->assertNull($dispatcher->dispatchAccepted($document->fresh()));
        $this->assertNull($dispatcher->dispatchAccepted($document->fresh()));

        $this->assertSame(1, ElectronicDocumentMailDelivery::count());
        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 1);

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame(ElectronicDocumentMailDelivery::TYPE_AUTOMATIC, $delivery->delivery_type);
        $this->assertNull($delivery->user_id);
        $this->assertSame('idem@example.com', $delivery->recipient);
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_SENT, $delivery->status);
        $this->assertIsArray($delivery->attachments);
        $this->assertNotEmpty($delivery->attachments);
        $this->assertSame(0, FiscalConsumption::count());
    }

    /**
     * @return array{0: Company, 1: Branch, 2: User, 3: Customer, 4: Sale}
     */
    private function context(?string $email, array $companyAttrs = []): array
    {
        $company = Company::create(array_merge([
            'trade_name' => 'Test Comercio',
            'legal_name' => 'Test S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ], $companyAttrs));

        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '109880401',
            'name' => 'Juan Pérez',
            'email' => $email,
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
            'sale_number' => 'POS-CE-'.$company->id,
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

        return [$company, $branch, $user, $customer, $sale];
    }

    private function item(Sale $sale): SaleItem
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
            'fiscal_snapshot' => [
                'source' => 'test',
                'source_version' => '1',
                'taxes' => [
                    ['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13],
                ],
            ],
        ]);
    }

    private function emitAccepted(Company $company, Sale $sale, bool $withXml): ElectronicDocument
    {
        $response = [
            'status' => 'accepted',
            'documentId' => 'doc-cemail',
            'clave' => '506010100000000000010000000000000000010',
            'consecutivo' => '001-001-0000010',
            'requestId' => 'req-cemail',
        ];

        if ($withXml) {
            $response['xml'] = '<r:RespuestaStatu>Aceptado</r:RespuestaStatu>';
        }

        Http::fake([
            'api.facturaencr.com/v2/efactura/documents/factura' => Http::response($response, 200),
        ]);

        return (new FacturaencrEmissionService)->emit($sale, $company, $sale->customer, [$this->item($sale)], $sale->payments->first());
    }

    private function acceptedDocument(Company $company, Sale $sale, string $type, string $clave): ElectronicDocument
    {
        return ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => $type,
            'environment' => 'sandbox',
            'idempotency_key' => 'cemail-'.$company->id.'-'.$type,
            'clave' => $clave,
            'consecutivo' => '001-001-'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT),
            'status' => 'accepted',
        ]);
    }
}
