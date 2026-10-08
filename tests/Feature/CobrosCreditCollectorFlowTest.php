<?php

namespace Tests\Feature;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BLOQUE 9C — el COBRADOR es la autoridad: prepara, CONTINÚA o NO REALIZA
 * y entrega con firma, sin aprobación individual del dueño (supervisión).
 * Ejecutar esta clase POR SEPARADO.
 */
class CobrosCreditCollectorFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
        $company = Company::create(['trade_name' => 'Cred '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

        // Política de AUTORIDAD: esta clase ejercita la modalidad
        // collector_authorized (default owner_required protege el resto).
        \App\Models\CobrosCollectorAuthorityPolicy::set(
            (int) $company->id,
            (int) $collector->id,
            \App\Models\CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED,
            (int) $owner->id
        );

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
        $this->assertNotEmpty($m);

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

    /** Sesión del cobrador dueño de la solicitud. */
    private function asCollector(CobrosRoute $route, User $collector, ?int $activeRoute = null): void
    {
        $this->actingAs($collector)->withSession([
            'active_company_id' => $route->company_id,
            'active_cobros_route_id' => $activeRoute ?? $route->id,
        ]);
    }

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

    public function test_collector_prepares_and_decides_without_owner_approval(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        // Index muestra la entrada de preparación.
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.index'))
            ->assertOk()
            ->assertSee('PREPARAR PRÉSTAMO')
            ->assertSee(route('cobros.collector.credit-requests.prepare', $request->id));

        // Pantalla de preparación con decisiones del cobrador.
        $html = $this->get(route('cobros.collector.credit-requests.prepare', $request->id))->assertOk()->getContent();
        $this->assertStringContainsString('CONTINUAR CON EL PRÉSTAMO', $html);
        $this->assertStringContainsString('NO REALIZAR EL PRÉSTAMO', $html);
        $this->assertStringNotContainsString(route('cobros.owner.creditos.approve', $request->id), $html);

        // Preview con el MISMO motor financiero.
        $this->asCollector($route, $collector);
        $this->postJson(route('cobros.collector.credit-requests.preview'), $this->terms())
            ->assertOk()
            ->assertJsonStructure(['principal', 'interest', 'total_due', 'installment_count', 'normal_amount', 'last_amount', 'rows']);

        // CONTINUAR: el dueño NUNCA interviene en esta decisión.
        $this->asCollector($route, $collector);
        $response = $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms());
        $response->assertRedirect();

        $this->assertSame(CobrosCreditRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame(1, CobrosCredit::count());
        $credit = CobrosCredit::firstOrFail();
        $this->assertSame($collector->id, (int) $credit->collector_user_id);
        $this->assertSame(route('cobros.collector.creditos.entrega', $credit->id), $response->headers->get('Location'));

        // Idempotencia: repetir CONTINUAR no duplica el préstamo.
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms());
        $this->assertSame(1, CobrosCredit::count());
    }

    public function test_collector_delivery_receipt_and_portfolio_regression(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms())->assertRedirect();
        $credit = CobrosCredit::firstOrFail();

        // Entrega: lenguaje cotidiano, sin "Prestamista".
        $html = $this->get(route('cobros.collector.creditos.entrega', $credit->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Prestamista', $html);
        $this->assertStringContainsString('Cobrador', $html);

        // Firma obligatoria.
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.creditos.disburse', $credit->id))->assertSessionHasErrors('signature');

        // Entrega firmada: CxC + calendario + aceptación (motor 9A.2 intacto).
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])
            ->assertRedirect(route('cobros.collector.creditos.comprobante', $credit->id));
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_credit_installments', 4);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);

        // Comprobante + PDF del cobrador.
        $receipt = $this->get(route('cobros.collector.creditos.comprobante', $credit->id))->assertOk();
        $this->assertStringNotContainsString('Prestamista', $receipt->getContent());
        $this->get(route('cobros.collector.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]))->assertDownload();

        // REGRESIÓN: el cliente con crédito sigue entrando a Mi cartera.
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.portfolio'))
            ->assertOk()
            ->assertSee('Maria Solis');

        // Supervisión informativa del dueño (sin botón obligatorio de aprobación).
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.creditos.show', $credit->id))->assertOk();
    }

    public function test_no_realizar_keeps_request_and_creates_no_financial_rows(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.not-realizar', $request->id), [
            'motivo' => 'condiciones_no_acordadas',
        ])->assertRedirect(route('cobros.collector.credit-requests.index'));

        $fresh = $request->fresh();
        $this->assertSame(CobrosCreditRequest::STATUS_REJECTED, $fresh->status);
        $this->assertStringContainsString('No se acordaron las condiciones', $fresh->latestLog()->notes);
        $this->assertSame(0, CobrosCredit::count());
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('cobros_credit_installments', 0);

        // Motivo obligatorio.
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.not-realizar', $request->id), [])
            ->assertSessionHasErrors('motivo');
    }

    public function test_prepare_is_private_to_owner_collector_route_and_tenant(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        // Otro cobrador de la misma empresa (otra ruta): 403.
        $admin2 = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Sur', 'active' => true]);
        $collectorB = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $routeB->users()->attach($collectorB->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($routeB, $company, $admin2, now()->addMonth());

        $this->asCollector($routeB, $collectorB);
        $this->get(route('cobros.collector.credit-requests.prepare', $request->id))->assertForbidden('A-prepare-otro-cobrador');
        $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms())->assertForbidden('B-continuar-otro-cobrador');
        $this->post(route('cobros.collector.credit-requests.not-realizar', $request->id), ['motivo' => 'otro'])->assertForbidden('C-norealizar-otro-cobrador');

        // Nota: la sesión de ruta activa se normaliza por CobrosRouteContext
        // (el cobrador nunca puede operar una ruta ajena), por lo que la
        // "otra ruta" queda cubierta por el caso A/B/C de collectorB.

        // Otro tenant: 404 (IDOR).
        $companyX = Company::create(['trade_name' => 'Ajena '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $this->withSession(['active_company_id' => $companyX->id]);
        $collectorX = $this->member($companyX, ['cobros.acceder', 'cobros.cobrar']);
        $routeX = CobrosRoute::create(['company_id' => $companyX->id, 'name' => 'Ruta X', 'active' => true]);
        $routeX->users()->attach($collectorX->id, ['active' => true]);
        $this->asCollector($routeX, $collectorX);
        $this->get(route('cobros.collector.credit-requests.prepare', $request->id))->assertNotFound('F-prepare-otro-tenant');
        $this->get(route('cobros.collector.creditos.entrega', 999999))->assertNotFound('G-entrega-inexistente');
    }

    /** 9C-B: collector_authorized — al enviar, el paso siguiente es del COBRADOR
     *  (nunca copy "Pendiente de revisión" del dueño); el listado lo dirige a verificar. */
    public function test_sent_request_points_collector_to_his_own_next_step_not_owner_review_copy(): void
    {
        [$company, $collector, $route, $owner] = $this->context();

        $this->asCollector($route, $collector);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk()
            ->assertSee('Después continuará con la revisión de la información del cliente')
            ->assertDontSee('<strong>Pendiente de revisión</strong>', false);

        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->assertNotEmpty($m);
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload() + ['review_token' => $m[1]])
            ->assertSessionHas('success', 'Solicitud enviada · Continúe con la revisión de la información del cliente.');

        $id = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail()->id;

        // Continúa en SU pantalla de evaluación (no en el circuito del dueño).
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.evaluate', $id))->assertOk();

        // El listado muestra SU paso (verificar), no espera del dueño.
        $this->get(route('cobros.collector.credit-requests.index'))->assertOk()
            ->assertSee('Lista para verificar')
            ->assertDontSee('Pendiente de revisión');
    }

    /** Tests 1,3,4,5,6,7,8,9 — CONTINUAR → evaluación → consulta con
     *  consentimiento → confirmación manual → Verificada → PREPARAR. */
    public function test_collector_evaluation_screen_flows_from_continue_to_verified(): void
    {
        try {
        [$company, $collector, $route, $owner] = $this->context();

        // 1) pantalla de revisión: botón CONTINUAR A REVISIÓN (no ENVIAR A REVISIÓN).
        $this->asCollector($route, $collector);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk()->assertSee('CONTINUAR A REVISIÓN')->assertDontSee('ENVIAR A REVISIÓN');
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->assertNotEmpty($m);

        $this->post(route('cobros.collector.credit-requests.store'), $this->payload() + ['review_token' => $m[1]])
            ->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail()->id;

        // 3) NO queda Verificada automáticamente.
        $this->assertSame(CobrosCreditRequest::STATUS_PENDING, CobrosCreditRequest::findOrFail($id)->status);

        // 4+5) pantalla de evaluación: sin PREPARAR, con documentos.
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.evaluate', $id))->assertOk()
            ->assertSee('Información para evaluar la solicitud')
            ->assertSee('no aprueba ni rechaza automáticamente')
            ->assertSee('Foto identificación — Frente')
            ->assertSee('Foto identificación — Reverso')
            ->assertSee('REVISAR Y CONFIRMAR DATOS')
            ->assertDontSee('PREPARAR PRÉSTAMO');
        $this->get(route('cobros.collector.credit-requests.photo', [$id, 'front']))->assertOk();

        // 6) consulta SOLO con consentimiento (sin autorización NO consulta).
        $noConsent = $this->getJson(route('cobros.collector.credit-requests.bureau-check', ['identification' => '109870545']));
        $this->assertTrue($noConsent->isRedirect(), 'sin consentimiento debe redirigir con error de validación');
        $consent = $this->getJson(route('cobros.collector.credit-requests.bureau-check', [
            'identification' => '109870545', 'autorizacion' => 'acepto',
        ]));
        $this->assertSame(200, $consent->getStatusCode(), substr((string) $consent->getContent(), 0, 400));

        // 7) la consulta NO verifica automáticamente.
        $this->assertSame(CobrosCreditRequest::STATUS_PENDING, CobrosCreditRequest::findOrFail($id)->status);

        // 8) REVISAR Y CONFIRMAR DATOS → Verificada.
        $this->post(route('cobros.collector.credit-requests.verify', $id), ['motivo' => 'Revisión con el cliente'])
            ->assertRedirect();
        $this->assertSame(CobrosCreditRequest::STATUS_VERIFIED, CobrosCreditRequest::findOrFail($id)->status);

        // 9) recién entonces PREPARAR PRÉSTAMO.
        $this->get(route('cobros.collector.credit-requests.index'))->assertOk()->assertSee('PREPARAR PRÉSTAMO');
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage()."\n".$e->getTraceAsString(), 0, $e);
        }
    }

    /** Tests 2,10,11 — owner_required: botón ENVIAR A REVISIÓN, sin pantalla
     *  de evaluación del cobrador; otro cobrador 403; otro tenant 404. */
    public function test_owner_required_review_button_and_evaluate_privacy(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        \App\Models\CobrosCollectorAuthorityPolicy::set(
            (int) $company->id, (int) $collector->id,
            \App\Models\CobrosCollectorAuthorityPolicy::OWNER_REQUIRED, (int) $owner->id
        );

        // 2) owner_required: ENVIAR A REVISIÓN.
        $this->asCollector($route, $collector);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk()->assertSee('ENVIAR A REVISIÓN')->assertDontSee('CONTINUAR A REVISIÓN');
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload() + ['review_token' => $m[1]])
            ->assertRedirect(route('cobros.collector.credit-requests.index'));
        $id = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail()->id;
        $this->assertSame(CobrosCreditRequest::STATUS_PENDING, CobrosCreditRequest::findOrFail($id)->status);

        // 11) owner_required: el cobrador NO accede a la pantalla de evaluación.
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.evaluate', $id))->assertForbidden();

        // 10) otro cobrador de la empresa → 403.
        $admin2 = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        \App\Models\CobrosCollectorAuthorityPolicy::set(
            (int) $company->id, (int) $collector->id,
            \App\Models\CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED, (int) $owner->id
        );
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Sur', 'active' => true]);
        $collectorB = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $routeB->users()->attach($collectorB->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($routeB, $company, $admin2, now()->addMonth());
        $this->asCollector($routeB, $collectorB);
        $this->get(route('cobros.collector.credit-requests.evaluate', $id))->assertForbidden();
        $this->get(route('cobros.collector.credit-requests.photo', [$id, 'front']))->assertForbidden();

        // Otro tenant → aislado (404).
        $companyX = Company::create(['trade_name' => 'Ajena '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $this->withSession(['active_company_id' => $companyX->id]);
        $collectorX = $this->member($companyX, ['cobros.acceder', 'cobros.cobrar']);
        $routeX = CobrosRoute::create(['company_id' => $companyX->id, 'name' => 'Ruta X', 'active' => true]);
        $routeX->users()->attach($collectorX->id, ['active' => true]);
        $this->asCollector($routeX, $collectorX);
        $this->get(route('cobros.collector.credit-requests.evaluate', $id))->assertNotFound();
    }
}
