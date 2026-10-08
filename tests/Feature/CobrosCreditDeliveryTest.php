<?php

namespace Tests\Feature;

use App\Http\Controllers\Cobros\OwnerCreditDeliveryController;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditAcceptance;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CobrosCreditDeliveryService;
use App\Services\Cobros\InstallmentScheduleService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BLOQUE 9A.2 — entrega con firma: evidencia, PDF y WhatsApp.
 * Ejecutar esta clase POR SEPARADO (no combinar con otras de GD en un proceso).
 */
class CobrosCreditDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // dompdf pesa en el mismo proceso de pruebas.
        ini_set('memory_limit', '768M');
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: User} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Entrega '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);

        return [$company, $collector, $route, $owner];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_type' => 'nuevo',
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'name' => 'Maria Solis',
            'phone' => '88888888',
            'address' => 'San Ramon, calle 1',
            'amount_requested' => '1500.50',
            'notes' => 'Primera solicitud',
        ], $overrides);
    }

    private function sendAsCollector(array $payload, CobrosRoute $route, User $collector): TestResponse
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $payload + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->assertNotEmpty($m, 'token de revisión ausente');

        return $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $m[1]]);
    }

    private function verifiedRequest(Company $company, User $owner, CobrosRoute $route, User $collector): CobrosCreditRequest
    {
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail()->id;

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.verify', $id), ['motivo' => 'Datos confirmados'])->assertRedirect();

        return CobrosCreditRequest::forCompany($company->id)->findOrFail($id);
    }

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'principal' => '1000.00',
            'interest_type' => 'percent',
            'interest_value' => '12.5',
            'installment_count' => 4,
            'frequency' => 'monthly',
            'first_due_date' => now()->addWeek()->toDateString(),
            'late_fee_type' => 'none',
            'late_fee_value' => '0',
            'late_fee_grace_days' => 0,
        ], $overrides);
    }

    /** Solicitud verificada + crédito aprobado, listo para entregar. */
    private function approved(array $overrides = []): array
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms($overrides))->assertRedirect();

        return [$company, $collector, $route, $owner, CobrosCredit::firstOrFail()];
    }

    /** Firma PNG válida (lienzo con tinta) en el formato esperado. */
    private function signaturePng(): string
    {
        $image = imagecreatetruecolor(400, 120);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        $ink = imagecolorallocate($image, 15, 23, 42);
        imagesetthickness($image, 3);
        imageline($image, 20, 80, 200, 40, $ink);
        imageline($image, 200, 40, 380, 90, $ink);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function test_empty_signature_does_not_allow_delivery_and_clear_leaves_canvas_empty(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        // 1) Firma vacía: el servidor la rechaza y NO entrega nada.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id))->assertSessionHasErrors('signature');
        $this->assertDatabaseCount('cobros_credit_acceptances', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertSame(CobrosCredit::STATUS_APPROVED, $credit->fresh()->status);

        // 2) "Limpiar firma" deja el lienzo realmente vacío (ink=false + clearRect)
        //    y se puede volver a firmar: mismos escuchadores activos.
        $html = $this->get(route('cobros.owner.creditos.entrega', $credit->id))->assertOk()->getContent();
        $this->assertStringContainsString('LIMPIAR FIRMA', $html);
        $this->assertStringContainsString('ink = false', $html);
        $this->assertStringContainsString('clearRect', $html);
        $this->assertStringContainsString('pointerdown', $html);

        // 3) Tras limpiar (firma vacía otra vez), volver a firmar sí permite confirmar.
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => ''])->assertSessionHasErrors('signature');
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $this->assertSame(CobrosCredit::STATUS_DISBURSED, $credit->fresh()->status);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);
    }

    public function test_delivery_screen_shows_plain_conditions_and_no_lender_word(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $html = $this->get(route('cobros.owner.creditos.entrega', $credit->id))->assertOk()->getContent();

        $this->assertStringContainsString('Confirmar entrega del préstamo', $html);
        $this->assertStringContainsString('Revise con el cliente las condiciones antes de entregar el dinero.', $html);
        $this->assertStringContainsString('Maria Solis', $html);
        $this->assertStringContainsString('Firma del cliente', $html);
        $this->assertStringContainsString('FIRMAR Y CONFIRMAR ENTREGA', $html);
        $this->assertStringContainsString('Calendario de pagos', $html);
        $this->assertStringContainsString('Cobrador', $html);
        $this->assertStringNotContainsString('Prestamista', $html);
    }

    public function test_signature_evidence_snapshot_and_sha256_are_persisted_and_immutable(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();

        $acceptance = CobrosCreditAcceptance::firstOrFail();
        $this->assertNotNull($acceptance->customer_id);
        $this->assertNotNull($acceptance->delivered_by);
        $this->assertSame($route->id, $acceptance->cobros_route_id);
        $this->assertStringStartsWith('cobros/credit-acceptance/', $acceptance->signature_path);
        $this->assertMatchesRegularExpression('/^AC-[A-F0-9]{8}$/', $acceptance->reference);

        // Snapshot EXACTO de las condiciones aceptadas (sin recalcular nada).
        $this->assertSame('1000.0000', (string) $acceptance->terms_snapshot['terms']['principal']);
        $this->assertSame('1125.0000', (string) $acceptance->terms_snapshot['terms']['total_due']);
        $this->assertSame('Maria Solis', $acceptance->terms_snapshot['customer']['name']);
        $this->assertSame('Ruta Norte', $acceptance->terms_snapshot['route']['name']);

        // SHA-256 estable: recomputable en cualquier momento desde el snapshot.
        $service = app(CobrosCreditDeliveryService::class);
        $this->assertSame(64, strlen($acceptance->terms_hash));
        $this->assertSame($acceptance->terms_hash, $service->hashSnapshot($acceptance->terms_snapshot));
        $this->assertSame($acceptance->terms_hash, $acceptance->fresh()->terms_hash);

        // Inmutable: un reintento jamás cambia firma, snapshot ni hash.
        $before = $acceptance->toArray();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $after = CobrosCreditAcceptance::firstOrFail()->toArray();
        $this->assertEquals($before, $after);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);
    }

    public function test_delivery_creates_cxc_calendar_assignment_once_and_retry_duplicates_nothing(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();

        $this->assertDatabaseCount('cobros_credits', 1);
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_credit_installments', 4);
        $this->assertDatabaseCount('cobros_assignments', 1);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);

        $account = \App\Models\AccountReceivable::firstOrFail();
        $this->assertSame('1125.0000', (string) $account->original_amount);
        $this->assertSame($credit->first_due_date->toDateString(), $account->due_date->toDateString());
        $this->assertNull($account->sale_id);
        $this->assertSame($route->id, (int) $account->cobros_route_id);

        // Retry: nada se duplica.
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $this->assertDatabaseCount('cobros_credits', 1);
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_credit_installments', 4);
        $this->assertDatabaseCount('cobros_assignments', 1);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);
    }

    public function test_receipt_screen_pdf_endpoint_and_whatsapp_message(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();

        // Pantalla posterior: botones grandes y WhatsApp con teléfono normalizado.
        $html = $this->get(route('cobros.owner.creditos.comprobante', $credit->id))->assertOk()->getContent();
        $this->assertStringContainsString('Préstamo entregado correctamente', $html);
        $this->assertStringContainsString('VER COMPROBANTE', $html);
        $this->assertStringContainsString('DESCARGAR PDF', $html);
        $this->assertStringContainsString('ABRIR WHATSAPP', $html);
        $this->assertStringContainsString('Descargue el comprobante y adjúntelo en WhatsApp.', $html);
        $this->assertMatchesRegularExpression('/wa\.me\/5068{8}/', $html);

        // Mensaje preparado (wa.me codifica el texto; se descodifica para leerlo).
        preg_match('/href="(https:\/\/wa\.me\/[^"]+)"/', $html, $match);
        $this->assertNotEmpty($match, 'enlace wa.me ausente');
        $message = urldecode($match[1]);
        $this->assertStringContainsString('Queda confirmado su préstamo.', $message);
        $this->assertStringContainsString('Monto recibido:', $message);
        $this->assertStringContainsString('Total a pagar:', $message);
        $this->assertStringContainsString('Pagos: 4 de', $message);
        $this->assertStringContainsString('Referencia del préstamo: '.$credit->publicReference(), $message);

        // PDF privado: descarga real con la firma embebida como imagen.
        $pdf = $this->get(route('cobros.owner.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]))->assertDownload();
        $content = (string) $pdf->baseResponse->getContent();
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertStringContainsString('/Image', $content);
    }

    public function test_pdf_uses_accepted_snapshot_full_calendar_and_never_shows_lender(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();

        $controller = app(OwnerCreditDeliveryController::class);
        $data = $controller->payload($credit->fresh(), app(InstallmentScheduleService::class));
        $data['acceptance'] = CobrosCreditAcceptance::firstOrFail();
        $data['signature'] = $controller->signatureImage($data['acceptance']);

        $html = view('cobros.pdf.credit-acceptance', $data)->render();

        // Condiciones aceptadas (snapshot) + datos del crédito correcto.
        $this->assertStringContainsString('MVS CobrOS', $html);
        $this->assertStringContainsString('Comprobante de préstamo y aceptación', $html);
        $this->assertStringContainsString('Referencia del préstamo: '.$credit->publicReference(), $html);
        $this->assertStringNotContainsString('Crédito #'.$credit->id, $html);
        $this->assertStringContainsString('Maria Solis', $html);
        $this->assertStringContainsString('₡1.125,00', $html);   // total aceptado
        $this->assertStringContainsString('12.5% sobre el monto prestado', $html);
        $this->assertStringContainsString('Cobrador', $html);
        $this->assertStringContainsString($collector->name, $html);
        $this->assertStringNotContainsString('Prestamista', $html);

        // Calendario COMPLETO.
        foreach ($credit->installments as $installment) {
            $this->assertStringContainsString($installment->due_date->format('d/m/Y'), $html);
        }

        // Firma real físicamente dentro del PDF + texto de aceptación + referencia.
        $this->assertStringContainsString('ACEPTACIÓN DEL CLIENTE', $html);
        $this->assertStringContainsString(CobrosCreditDeliveryService::ACCEPTANCE_TEXT, $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString(CobrosCreditAcceptance::firstOrFail()->reference, $html);
        $this->assertStringContainsString('Aceptado el', $html);
    }

    public function test_delivery_evidence_is_private_and_foreign_tenant_gets_404(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        // El cobrador NUNCA entrega ni consulta el comprobante (solo portal_dueno).
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.owner.creditos.entrega', $credit->id))->assertForbidden();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertForbidden();
        $this->get(route('cobros.owner.creditos.comprobante_pdf', $credit->id))->assertForbidden();

        // Otro tenant: 404 (IDOR), jamás 200/403.
        session()->forget('active_company_id');
        [$otherCompany] = $this->context();
        $foreignOwner = $this->member($otherCompany, ['cobros.acceder', 'cobros.portal_dueno']);
        $this->actingAs($foreignOwner)->withSession(['active_company_id' => $otherCompany->id]);
        $this->get(route('cobros.owner.creditos.entrega', $credit->id))->assertNotFound();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertNotFound();
        $this->get(route('cobros.owner.creditos.comprobante', $credit->id))->assertNotFound();
        $this->get(route('cobros.owner.creditos.comprobante_pdf', $credit->id))->assertNotFound();
    }

    public function test_receipt_without_phone_keeps_working_and_relations_feed_future_expediente(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();

        // Sin teléfono: el flujo NO se rompe y se ofrece solo descargar.
        $credit->fresh()->customer->update(['phone' => null]);
        $html = $this->get(route('cobros.owner.creditos.comprobante', $credit->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('ABRIR WHATSAPP', $html);
        $this->assertStringContainsString('DESCARGAR PDF', $html);

        // Relaciones reales para el futuro Expediente (Bloque 10): sin copias.
        $fresh = CobrosCredit::firstOrFail();
        $this->assertNotNull($fresh->acceptance);
        $this->assertNotNull($fresh->acceptance->credit);
        $this->assertNotNull($fresh->acceptance->customer);
        $this->assertNotNull($fresh->acceptance->collector);
        $this->assertNotNull($fresh->acceptance->route);
        $this->assertNotNull($fresh->request);
        $this->assertNotNull($fresh->account);
        $this->assertCount(4, $fresh->installments);
        $this->assertSame($fresh->acceptance->credit_id, $fresh->id);
    }

    /** Regresión owner_required: el cobrador ASIGNADO entrega con firma un
     *  crédito ya autorizado por el dueño (el form apunta a SU endpoint,
     *  nunca a owner.* = portal_dueno); otros cobran 403. */
    public function test_owner_required_assigned_collector_delivers_and_intruder_gets_403(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved(['installment_count' => 10]);
        $session = ['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id];

        // Pantalla de entrega del cobrador: FIRMAR Y CONFIRMAR ENTREGA publica
        // a la ruta del COBRADOR (causa real del 403: action owner.* con
        // middleware permission:cobros.portal_dueno).
        $this->actingAs($collector)->withSession($session);
        $html = $this->get(route('cobros.collector.creditos.entrega', $credit->id))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('cobros.collector.creditos.disburse', $credit->id).'"', $html);
        $this->assertStringNotContainsString('action="'.route('cobros.owner.creditos.disburse', $credit->id).'"', $html);

        // Firma + entrega del cobrador asignado (dueño ya autorizó).
        $this->actingAs($collector)->withSession($session);
        $this->post(route('cobros.collector.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])
            ->assertRedirect(route('cobros.collector.creditos.comprobante', $credit->id));

        // 7) exactamente 10 cuotas desde el snapshot; 8) UNA CxC; 9) firma persistida.
        $fresh = CobrosCredit::firstOrFail();
        $this->assertSame(CobrosCredit::STATUS_DISBURSED, $fresh->status);
        $this->assertCount(10, $fresh->installments);
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);
        $this->assertNotNull($fresh->acceptance->signature_path);

        // 10) doble submit: no duplica crédito, CxC, cuotas ni aceptación.
        $this->actingAs($collector)->withSession($session);
        $this->post(route('cobros.collector.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])
            ->assertRedirect(route('cobros.collector.creditos.comprobante', $credit->id));
        $this->assertDatabaseCount('cobros_credits', 1);
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);
        $this->assertCount(10, CobrosCredit::firstOrFail()->installments);

        // 4) otro cobrador (misma ruta) → 403 por propiedad.
        $intruder = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $route->users()->attach($intruder->id, ['active' => true]);
        $this->actingAs($intruder)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->post(route('cobros.collector.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])
            ->assertForbidden();
        $this->actingAs($intruder)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.creditos.entrega', $credit->id))->assertForbidden();

        // 11) owner_required intacto: el cobrador NO puede autoautorizar.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $credit->credit_request_id), $this->terms())
            ->assertForbidden();
    }

    /** PDF con 10 cuotas: UNA sola hoja (aceptación y firma no saltan a
     *  página 2) y con firma embebida. */
    public function test_pdf_with_ten_installments_fits_one_page_with_signature(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved(['installment_count' => 10]);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();

        $pdf = $this->get(route('cobros.owner.creditos.comprobante_pdf', $credit->id))->assertOk();
        $content = (string) $pdf->baseResponse->getContent();

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertStringContainsString('/Image', $content);       // firma completa
        $this->assertStringContainsString('/Count 1', $content);     // exactamente 1 página
        $this->assertStringNotContainsString('/Count 2', $content);  // sin página 2
    }

    /** 9C referencia pública: no secuencial, única, estable y persistida. */
    public function test_public_credit_reference_is_stable_unique_and_never_sequential(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        $ref = $credit->publicReference();
        // 1) formato estable, sin secuencia ni derivado del id.
        $this->assertMatchesRegularExpression('/^CR-[A-Z0-9]{6}$/', $ref);
        $this->assertFalse(ctype_digit(substr($ref, 3)), 'nunca un entero puro');
        $this->assertNotSame('CR-'.str_pad((string) $credit->id, 6, '0', STR_PAD_LEFT), $ref);
        $this->assertNotSame('CR-'.str_pad((string) $credit->id, 6, ' ', STR_PAD_LEFT), $ref);

        // 2) única (generaciones consecutivas nunca colisionan ni igualan la actual).
        $other = \App\Models\CobrosCredit::generateUniquePublicReference();
        $this->assertNotSame($ref, $other);
        $this->assertMatchesRegularExpression('/^CR-[A-Z0-9]{6}$/', $other);

        // 3) permanece igual al recargar/regenerar.
        $this->assertSame($ref, $credit->fresh()->publicReference());
        $this->assertSame($ref, \App\Models\CobrosCredit::findOrFail($credit->id)->publicReference());
    }

    /** 7) préstamo existente sin referencia (previo a migración) la obtiene
     *  y todo el flujo sigue funcionando. */
    public function test_existing_credit_without_reference_gains_one_and_still_works(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        \App\Models\CobrosCredit::query()->where('id', $credit->id)->update(['public_reference' => null]);
        $existing = \App\Models\CobrosCredit::findOrFail($credit->id);
        $this->assertNull($existing->public_reference);

        $ref = $existing->publicReference();
        $this->assertMatchesRegularExpression('/^CR-[A-Z0-9]{6}$/', $ref);
        $this->assertSame($ref, \App\Models\CobrosCredit::findOrFail($credit->id)->public_reference);

        // El detalle del préstamo sigue funcionando con la referencia.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.creditos.show', $credit->id))->assertOk()
            ->assertSee('Referencia del préstamo', false)
            ->assertSee($ref, false)
            ->assertDontSee('Crédito #'.$credit->id, false);
    }

    /** 9C CTA/estado: APPROVED → "Entregar préstamo"; DISBURSED →
     *  estado humano "Préstamo entregado" + "VER PRÉSTAMO" (ruta existente). */
    public function test_disbursed_credit_shows_human_state_and_ver_prestamo_instead_of_entregar_cta(): void
    {
        [$company, $collector, $route, $owner, $credit] = $this->approved();

        // 15) APPROVED pendiente → CTA "Entregar préstamo" en Mis solicitudes.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.index'))->assertOk()
            ->assertSee('Entregar préstamo')
            ->assertSee('Aprobada');

        // Desembolso real (flujo ya validado, idempotente).
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();

        // 16–19) listado del cobrador: sin "Entregar préstamo", estado humano y CTA a ruta existente.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.index'))->assertOk()
            ->assertDontSee('Entregar préstamo')
            ->assertSee('Préstamo entregado')
            ->assertSee('VER PRÉSTAMO')
            ->assertSee(route('cobros.collector.creditos.comprobante', $credit->id), false);

        // Listado del dueño: badge "Préstamo entregado" en lugar de "Aprobada".
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))->assertOk()
            ->assertSee('Préstamo entregado')
            ->assertDontSee('Aprobada</span>');
    }
}
