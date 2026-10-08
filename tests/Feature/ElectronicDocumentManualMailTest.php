<?php

namespace Tests\Feature;

use App\Jobs\SendElectronicDocumentMail;
use App\Mail\ComprobanteElectronicoAceptadoMail;
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
use App\Services\Fiscal\FiscalCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ElectronicDocumentManualMailTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    public function test_manual_send_delivers_with_registered_email_and_real_attachments(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $user = $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
            ->assertRedirect(route('fiscal.documents.show', $document))
            ->assertSessionHas('status');

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame('manual', $delivery->delivery_type);
        $this->assertSame($user->id, $delivery->user_id);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('receptor@example.com', $delivery->recipient);
        $this->assertSame($company->id, $delivery->company_id);
        $this->assertNotNull($delivery->sent_at);

        $base = $document->consecutivo;
        $this->assertContains($base.'.pdf', $delivery->attachments);
        $this->assertContains($base.'-xml-enviado.xml', $delivery->attachments);
        $this->assertContains($base.'-xml-firmado.xml', $delivery->attachments);
        $this->assertContains($base.'-respuesta-hacienda.json', $delivery->attachments);

        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, function (ComprobanteElectronicoAceptadoMail $mail) {
            return $mail->hasTo('receptor@example.com');
        });

        $this->get(route('fiscal.documents.show', $document))
            ->assertOk()
            ->assertSee('Entrega al cliente')
            ->assertSee('Historial de entregas')
            ->assertSee('Enviado')
            ->assertSee('Manual')
            ->assertSee('Reenviar comprobante');
    }

    public function test_resend_creates_new_delivery_without_reemitting(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $clave = $document->clave;
        $consecutivo = $document->consecutivo;

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com']);
        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com']);

        $this->assertSame(2, ElectronicDocumentMailDelivery::query()->count());
        $this->assertSame(
            2,
            ElectronicDocumentMailDelivery::query()->where('delivery_type', 'manual')->count()
        );
        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 2);

        $this->assertSame(1, ElectronicDocument::count());
        $fresh = $document->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame($clave, $fresh->clave);
        $this->assertSame($consecutivo, $fresh->consecutivo);
        $this->assertSame(0, FiscalConsumption::query()->count());
    }

    public function test_alternative_email_is_used_for_this_send_only(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'otro@example.com'])
            ->assertRedirect(route('fiscal.documents.show', $document));

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame('otro@example.com', $delivery->recipient);
        $this->assertSame('sent', $delivery->status);

        $this->assertSame('receptor@example.com', $document->fresh()->sale->customer->fresh()->email);
        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, function (ComprobanteElectronicoAceptadoMail $mail) {
            return $mail->hasTo('otro@example.com') && ! $mail->hasTo('receptor@example.com');
        });
    }

    public function test_invalid_email_is_rejected_without_delivery(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $this->from(route('fiscal.documents.show', $document))
            ->post(route('fiscal.documents.mail', $document), ['recipient' => 'no-es-correo'])
            ->assertSessionHasErrors('recipient');

        $this->assertSame(0, ElectronicDocumentMailDelivery::query()->count());
        Mail::assertNothingSent();
        $this->assertSame('accepted', $document->fresh()->status);
    }

    public function test_foreign_company_document_returns_404_and_history_is_scoped(): void
    {
        Mail::fake();
        [$companyA, $branchA] = $this->fiscalContext();
        $this->actingUser($companyA, $branchA, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $documentA = $this->acceptedDocument($companyA, $branchA);

        [$companyB, $branchB] = $this->fiscalContext();
        $documentB = $this->acceptedDocument($companyB, $branchB);

        $this->get(route('fiscal.documents.show', $documentB))->assertNotFound();
        $this->post(route('fiscal.documents.mail', $documentB), ['recipient' => 'x@example.com'])->assertNotFound();
        $this->assertSame(0, ElectronicDocumentMailDelivery::query()->count());

        $this->post(route('fiscal.documents.mail', $documentA), ['recipient' => 'receptor@example.com']);

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame($companyA->id, $delivery->company_id);
        $this->assertSame($documentA->id, $delivery->electronic_document_id);

        $this->get(route('fiscal.documents.show', $documentA))
            ->assertOk()
            ->assertSee('receptor@example.com');
    }

    public function test_viewer_with_fiscal_ver_sees_history_but_cannot_send(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver']);
        $document = $this->acceptedDocument($company, $branch);

        $this->get(route('fiscal.documents.show', $document))
            ->assertOk()
            ->assertSee('Entrega al cliente')
            ->assertSee('Historial de entregas')
            ->assertDontSee('Enviar comprobante');

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
            ->assertForbidden();

        $this->assertSame(0, ElectronicDocumentMailDelivery::query()->count());
        Mail::assertNothingSent();
    }

    public function test_admin_with_super_admin_role_sees_and_sends_by_default(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $document = $this->acceptedDocument($company, $branch);

        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
            'is_super_admin' => true,
        ]);
        Permission::firstOrCreate(
            ['name' => 'fiscal.enviar_comprobante'],
            ['label' => 'Enviar/reenviar comprobantes', 'module' => 'Facturación Electrónica', 'is_active' => true],
        );
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        $this->get(route('fiscal.documents.show', $document))
            ->assertOk()
            ->assertSee('Enviar comprobante');

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
            ->assertRedirect(route('fiscal.documents.show', $document));

        $this->assertSame(1, ElectronicDocumentMailDelivery::query()->count());
        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 1);
        $this->assertSame(1, ElectronicDocument::count());
        $this->assertSame(0, FiscalConsumption::query()->count());
    }

    public function test_vendor_with_send_permission_sees_and_sends_without_fiscal_editar(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $this->get(route('fiscal.documents.show', $document))
            ->assertOk()
            ->assertSee('Enviar comprobante');

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
            ->assertRedirect(route('fiscal.documents.show', $document));

        $this->assertSame(1, ElectronicDocumentMailDelivery::query()->count());
        Mail::assertSent(ComprobanteElectronicoAceptadoMail::class, 1);
        $this->assertSame(1, ElectronicDocument::count());
        $this->assertSame(0, FiscalConsumption::query()->count());
    }

    public function test_smtp_failure_is_visible_and_document_stays_accepted(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
            ->assertRedirect(route('fiscal.documents.show', $document));

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('SMTP down', (string) $delivery->last_error);
        $this->assertSame('accepted', $document->fresh()->status);

        $this->get(route('fiscal.documents.show', $document))
            ->assertOk()
            ->assertSee('Falló')
            ->assertSee('SMTP down');
    }

    public function test_attachments_only_include_real_custody_files(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);

        $jsonOnly = $this->acceptedDocument($company, $branch, 'receptor@example.com', false, false);
        $this->post(route('fiscal.documents.mail', $jsonOnly), ['recipient' => 'receptor@example.com']);

        $deliveryJson = ElectronicDocumentMailDelivery::query()
            ->where('electronic_document_id', $jsonOnly->id)
            ->sole();
        $this->assertContains($jsonOnly->consecutivo.'.pdf', $deliveryJson->attachments);
        $this->assertContains($jsonOnly->consecutivo.'-respuesta-hacienda.json', $deliveryJson->attachments);
        $this->assertFalse(
            collect($deliveryJson->attachments)->contains(fn (string $name) => str_contains($name, '.xml')),
            'No debe adjuntarse XML inexistente en la custodia'
        );

        $withXml = $this->acceptedDocument($company, $branch, 'receptor@example.com', true, true);
        $this->post(route('fiscal.documents.mail', $withXml), ['recipient' => 'receptor@example.com']);

        $deliveryXml = ElectronicDocumentMailDelivery::query()
            ->where('electronic_document_id', $withXml->id)
            ->sole();
        $this->assertContains($withXml->consecutivo.'-xml-enviado.xml', $deliveryXml->attachments);
        $this->assertContains($withXml->consecutivo.'-xml-firmado.xml', $deliveryXml->attachments);

        $this->assertSame(0, FiscalConsumption::query()->count());
    }

    public function test_manual_send_never_creates_documents_or_consumes_quota(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $clave = $document->clave;
        $consecutivo = $document->consecutivo;
        $createdAt = $document->created_at;

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com']);

        $this->assertSame(1, ElectronicDocument::query()->count());
        $this->assertSame(0, FiscalConsumption::query()->count());
        $fresh = $document->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame($clave, $fresh->clave);
        $this->assertSame($consecutivo, $fresh->consecutivo);
        $this->assertTrue($fresh->created_at->equalTo($createdAt));
        $this->assertSame(1, ElectronicDocumentMailDelivery::query()->count());
    }

    public function test_historical_accepted_without_xml_recover_via_harvest_on_manual_resend(): void
    {
        Mail::fake();
        Http::fake([
            'https://api.facturaencr.com/v2/efactura/documents/doc-history-114/xml' => Http::response(
                '<FacturaElectronica><Clave>506010100000000000010000000000000000114</Clave></FacturaElectronica>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'https://api.facturaencr.com/v2/efactura/documents/doc-history-114/xml-respuesta' => Http::response(
                '<MensajeHacienda><Respuesta>ACEPTADO</Respuesta></MensajeHacienda>',
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        [$company, $branch] = $this->fiscalContext();
        
        // Insert fiscal config with credentials for harvestArtifacts to work
        DB::table('company_fiscal_configs')->insert([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'provider_api_key' => encrypt('efk_TESTKEY', false),
            'provider_api_secret' => encrypt('efk_TESTSECRET', false),
            'auto_emit_enabled' => true,
            'fiscal_start_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);

        // Documento histórico accepted SIN xml_signed ni hacienda_response_xml en custodia
        // (legacy: solo payload/response con JSON, sin artefactos descargados)
        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $this->makeSale($company, $branch)->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'history-114',
            'provider_document_id' => 'doc-history-114',
            'clave' => '506010100000000000010000000000000000114',
            'consecutivo' => '001-001-000114',
            'status' => 'accepted',
        ]);

        // Custodia SOLO con payload/response JSON legacy, SIN columnas xml_signed/hacienda_response_xml
        FiscalDocumentCustody::create([
            'electronic_document_id' => $document->id,
            'payload' => ['clave' => $document->clave, 'detalle' => []],
            'response' => ['status' => 'accepted'],
        ]);

        $consumptionsBefore = \App\Models\FiscalConsumption::count();

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
            ->assertRedirect(route('fiscal.documents.show', $document))
            ->assertSessionHas('status');

        // Verificar que NO se hizo POST (solo GET para artefactos)
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/xml'));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/xml-respuesta'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame('sent', $delivery->status);

        // Verificar que se adjuntaron los XML recuperados por harvestArtifacts
        $base = $document->consecutivo;
        $this->assertContains($base.'-xml-firmado.xml', $delivery->attachments);
        $this->assertContains($base.'-respuesta-hacienda.xml', $delivery->attachments);

        // Verificar cero consumo
        $this->assertSame($consumptionsBefore, \App\Models\FiscalConsumption::count());

        // Verificar que la custodia ahora tiene los artefactos
        $custody = \App\Models\FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)
            ->sole();
        $this->assertStringContainsString('<FacturaElectronica>', $custody->xml_signed);
        $this->assertStringContainsString('<MensajeHacienda>', $custody->hacienda_response_xml);

        // Misma clave/consecutivo/estado
        $fresh = $document->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame($document->clave, $fresh->clave);
        $this->assertSame($document->consecutivo, $fresh->consecutivo);
        $this->assertSame(1, ElectronicDocument::count());
    }

    public function test_historical_accepted_without_provider_doc_id_skips_harvest_but_sends_available(): void
    {
        Mail::fake();
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);

        // Documento accepted SIN provider_document_id (no se puede harvest)
        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $this->makeSale($company, $branch)->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'history-no-id',
            'provider_document_id' => null,
            'clave' => '506010100000000000010000000000000000115',
            'consecutivo' => '001-001-000115',
            'status' => 'accepted',
        ]);

        FiscalDocumentCustody::create([
            'electronic_document_id' => $document->id,
            'payload' => ['clave' => $document->clave],
            'response' => ['status' => 'accepted'],
        ]);

        $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
            ->assertRedirect();

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame('sent', $delivery->status);
        $this->assertContains($document->consecutivo.'.pdf', $delivery->attachments);
        $this->assertFalse(collect($delivery->attachments)->contains(fn (string $n) => str_contains($n, '.xml')));

        // Sin HTTP hacia proveedor (no hay provider_document_id)
        Http::assertNothingSent();
    }

    public function test_real_mailer_attaches_pdf_only(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $document = $this->acceptedDocument($company, $branch);

        $mailable = new ComprobanteElectronicoAceptadoMail($document, $company, [
            ['name' => 'unico.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 unico'],
        ]);

        $event = $this->sendViaRealArrayMailer(fn () => Mail::to('receptor@example.com')->send($mailable));

        $this->assertSame(['unico.pdf'], $this->attachmentFilenames($event));
    }

    public function test_real_mailer_attaches_pdf_and_both_xmls(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $document = $this->acceptedDocument($company, $branch);

        $mailable = new ComprobanteElectronicoAceptadoMail($document, $company, [
            ['name' => 'a.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 a'],
            ['name' => 'a-xml-enviado.xml', 'mime' => 'application/xml', 'content' => '<DocumentoEnviado/>'],
            ['name' => 'a-xml-firmado.xml', 'mime' => 'application/xml', 'content' => '<RespuestaStatu/>'],
        ]);

        $event = $this->sendViaRealArrayMailer(fn () => Mail::to('receptor@example.com')->send($mailable));

        $this->assertSame(['a.pdf', 'a-xml-enviado.xml', 'a-xml-firmado.xml'], $this->attachmentFilenames($event));
    }

    public function test_real_mailer_attaches_pdf_and_json_response(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $document = $this->acceptedDocument($company, $branch);

        $mailable = new ComprobanteElectronicoAceptadoMail($document, $company, [
            ['name' => 'b.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 b'],
            ['name' => 'b-respuesta.json', 'mime' => 'application/json', 'content' => '{"status":"accepted"}'],
        ]);

        $event = $this->sendViaRealArrayMailer(fn () => Mail::to('receptor@example.com')->send($mailable));

        $this->assertSame(['b.pdf', 'b-respuesta.json'], $this->attachmentFilenames($event));
    }

    public function test_missing_or_empty_attachment_entries_are_omitted(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $document = $this->acceptedDocument($company, $branch);

        $mailable = new ComprobanteElectronicoAceptadoMail($document, $company, [
            ['name' => 'ok.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 ok'],
            ['name' => 'vacio.xml', 'mime' => 'application/xml', 'content' => ''],
            ['mime' => 'application/xml', 'content' => '<SinNombre/>'],
            ['name' => 'sin-contenido.pdf', 'mime' => 'application/pdf'],
        ]);

        $event = $this->sendViaRealArrayMailer(fn () => Mail::to('receptor@example.com')->send($mailable));

        $this->assertSame(['ok.pdf'], $this->attachmentFilenames($event));
    }

    public function test_manual_send_with_real_mailer_builds_attachments_without_new_document(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->actingUser($company, $branch, ['fiscal.ver', 'fiscal.enviar_comprobante']);
        $document = $this->acceptedDocument($company, $branch);

        $event = $this->sendViaRealArrayMailer(function () use ($document) {
            $this->post(route('fiscal.documents.mail', $document), ['recipient' => 'receptor@example.com'])
                ->assertRedirect(route('fiscal.documents.show', $document));
        });

        $delivery = ElectronicDocumentMailDelivery::query()->sole();
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_SENT, $delivery->status);
        $this->assertContains($document->consecutivo.'.pdf', $delivery->attachments);
        $this->assertContains($document->consecutivo.'.pdf', $this->attachmentFilenames($event));

        $this->assertSame(1, ElectronicDocument::count());
        $this->assertSame(0, FiscalConsumption::count());
    }

    public function test_automatic_job_uses_same_attachment_logic(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $document = $this->acceptedDocument($company, $branch);

        $delivery = ElectronicDocumentMailDelivery::create([
            'company_id' => $company->id,
            'electronic_document_id' => $document->id,
            'recipient' => 'receptor@example.com',
            'delivery_type' => ElectronicDocumentMailDelivery::TYPE_AUTOMATIC,
            'status' => ElectronicDocumentMailDelivery::STATUS_PENDING,
        ]);

        $event = $this->sendViaRealArrayMailer(fn () => (new SendElectronicDocumentMail($delivery->id))->handle());

        $delivery->refresh();
        $this->assertSame(ElectronicDocumentMailDelivery::STATUS_SENT, $delivery->status);
        $this->assertNull($delivery->user_id);
        $this->assertNotNull($delivery->sent_at);

        $names = $this->attachmentFilenames($event);
        $this->assertContains($document->consecutivo.'.pdf', $names);
        $this->assertContains($document->consecutivo.'-xml-enviado.xml', $names);
        $this->assertContains($document->consecutivo.'-xml-firmado.xml', $names);
        $this->assertContains($document->consecutivo.'-respuesta-hacienda.json', $names);

        $this->assertSame(1, ElectronicDocument::count());
        $this->assertSame(0, FiscalConsumption::count());
    }

    private function sendViaRealArrayMailer(callable $send): MessageSent
    {
        config(['mail.default' => 'array']);
        Event::fake([MessageSent::class]);

        $send();

        $captured = null;
        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use (&$captured) {
            $captured = $event;

            return true;
        });

        $this->assertNotNull($captured, 'El envío real no produjo MessageSent');

        return $captured;
    }

    /**
     * @return array<int, string|null>
     */
    private function attachmentFilenames(MessageSent $event): array
    {
        return array_map(
            static fn ($part) => $part->getFilename(),
            $event->message->getAttachments()
        );
    }

    /**
     * @return array{0: Company, 1: Branch}
     */
    private function fiscalContext(): array
    {
        $company = Company::create([
            'trade_name' => 'M'.uniqid(),
            'legal_name' => 'MVS S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P'.uniqid(), 'is_active' => true]);

        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true]);

        return [$company, $branch];
    }

    private function actingUser(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

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

    /**
     * Documento aceptado con custodia real, sin disparar el envío
     * automático (se crea en estado queued y solo después pasa a
     * accepted, por lo que la prueba controla 100% el envío manual).
     */
    private function acceptedDocument(
        Company $company,
        Branch $branch,
        string $email = 'receptor@example.com',
        bool $payloadWithXml = true,
        bool $responseWithXml = true,
    ): ElectronicDocument {
        $this->seq++;
        $clave = '506'.sprintf('%047d', $this->seq);
        $consecutivo = '001-001-'.sprintf('%06d', $this->seq);

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '109'.sprintf('%06d', $this->seq),
            'name' => 'Juan Pérez',
            'email' => $email,
        ]);

        $paymentMethod = PaymentMethod::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'cash'],
            [
                'name' => 'Efectivo',
                'type' => 'cash',
                'is_system' => true,
                'is_active' => true,
                'affects_cash' => true,
                'requires_reference' => false,
                'allows_change' => true,
            ],
        );

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sale_number' => 'POS-CE2-'.$company->id.'-'.$this->seq,
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

        SaleItem::create([
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

        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'cemail2-'.$company->id.'-'.$this->seq,
            'clave' => $clave,
            'consecutivo' => $consecutivo,
            'status' => 'queued',
        ]);

        $payload = ['clave' => $clave];
        if ($payloadWithXml) {
            $payload['xml'] = '<r:DocumentoEnviado>OK</r:DocumentoEnviado>';
        }

        $response = ['status' => 'accepted', 'clave' => $clave];
        if ($responseWithXml) {
            $response['xml'] = '<r:RespuestaStatu>Aceptado</r:RespuestaStatu>';
        }

        app(FiscalCustodyService::class)->record($document, $payload, $response);
        $document->update(['status' => 'accepted']);

        return $document->fresh();
    }

    private function makeSale(Company $company, Branch $branch): \App\Models\Sale
    {
        $customer = \App\Models\Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '109880401',
            'name' => 'Juan Pérez',
            'email' => 'receptor@example.com',
        ]);

        $paymentMethod = \App\Models\PaymentMethod::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'cash'],
            [
                'name' => 'Efectivo',
                'type' => 'cash',
                'is_system' => true,
                'is_active' => true,
                'affects_cash' => true,
                'requires_reference' => false,
                'allows_change' => true,
            ],
        );

        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        return \App\Models\Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sale_number' => 'POS-HIST-'.$company->id.'-'.uniqid(),
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
    }
}
