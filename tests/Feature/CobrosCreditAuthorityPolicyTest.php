<?php

namespace Tests\Feature;

use App\Models\CobrosCollectorAuthorityPolicy;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\CobrosAssignment;
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
 * Política de AUTORIDAD de préstamos por cobrador:
 * owner_required (default SEGURO) vs collector_authorized.
 * Una sola autoridad por decisión; la política aplicada se congela en
 * terms_snapshot. Ejecutar POR SEPARADO.
 */
class CobrosCreditAuthorityPolicyTest extends TestCase
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

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: User, 4: User} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Auth '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);

        return [$company, $collector, $route, $owner, $admin];
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
            'notes' => 'Solicitud',
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

    private function verifiedRequest(Company $company, User $owner, CobrosRoute $route, User $collector, array $payload = []): CobrosCreditRequest
    {
        $this->sendAsCollector($payload ?: $this->payload(), $route, $collector)->assertRedirect();
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

    private function asCollector(CobrosRoute $route, User $collector): void
    {
        $this->actingAs($collector)->withSession([
            'active_company_id' => $route->company_id,
            'active_cobros_route_id' => $route->id,
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

    private function setPolicy(int $companyId, int $collectorId, string $policy, ?int $actorId = null): void
    {
        CobrosCollectorAuthorityPolicy::set($companyId, $collectorId, $policy, $actorId);
    }

    /** 1 + 5 + 6: default owner_required y el cobrador NO puede autorizar/entregar. */
    public function test_default_policy_is_owner_required_and_collector_cannot_decide(): void
    {
        [$company, $collector, $route, $owner] = $this->context();

        // 1) default SEGURO sin fila explícita.
        $this->assertSame('owner_required', CobrosCollectorAuthorityPolicy::policyFor((int) $company->id, (int) $collector->id));
        $this->assertDatabaseMissing('cobros_collector_authority_policies', ['company_id' => $company->id, 'collector_user_id' => $collector->id]);

        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        // 5) no puede autorizar (ni dar por no realizado).
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms())
            ->assertForbidden('5-continuar-owner-required');
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.not-realizar', $request->id), ['motivo' => 'otro'])
            ->assertForbidden('5-norealizar-owner-required');
        $this->assertSame(0, CobrosCredit::count());

        // 6) no puede entregar antes de autorización: no existe crédito.
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.creditos.disburse', 999999), ['signature' => $this->signaturePng()])
            ->assertNotFound('6-entregar-sin-autorizacion');

        // El dueño SÍ ve la solicitud (7).
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.show', $request->id))->assertOk();
    }

    /** 2 + 3 + 4: configuración por el dueño, tenant-scoped y auditable. */
    public function test_owner_configures_policy_tenant_scoped_and_collector_cannot_change_it(): void
    {
        [$company, $collector, $route, $owner] = $this->context();

        // 2) el dueño configura collector_authorized.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->patch(route('cobros.owner.collectors.authority', $collector), ['authority' => 'collector_authorized'])
            ->assertRedirect(route('cobros.owner.collectors.show', $collector));
        $row = CobrosCollectorAuthorityPolicy::query()
            ->where('company_id', $company->id)->where('collector_user_id', $collector->id)->firstOrFail();
        $this->assertSame('collector_authorized', $row->authority_policy);
        $this->assertSame($owner->id, (int) $row->changed_by, 'auditoría: quién cambió');
        $this->assertNotNull($row->changed_at, 'auditoría: cuándo');
        $this->assertSame('collector_authorized', CobrosCollectorAuthorityPolicy::policyFor((int) $company->id, (int) $collector->id));

        // 4) el cobrador NO puede cambiar su propia política.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->patch(route('cobros.owner.collectors.authority', $collector), ['authority' => 'owner_required'])
            ->assertForbidden('4-cobrador-cambia-la-propia');
        $this->assertSame('collector_authorized', CobrosCollectorAuthorityPolicy::policyFor((int) $company->id, (int) $collector->id));

        // 3) otro tenant: 404 y la fila original queda intacta.
        session()->forget('active_company_id');
        [$companyB, $collectorB, $routeB, $ownerB] = $this->context();
        $this->actingAs($ownerB)->withSession(['active_company_id' => $companyB->id]);
        $this->patch(route('cobros.owner.collectors.authority', $collector), ['authority' => 'owner_required'])
            ->assertNotFound('3-otro-tenant');
        $this->assertSame('collector_authorized', CobrosCollectorAuthorityPolicy::policyFor((int) $company->id, (int) $collector->id));
        $this->assertSame('owner_required', CobrosCollectorAuthorityPolicy::policyFor((int) $companyB->id, (int) $collectorB->id));

        // Opción inválida rechazada.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->patch(route('cobros.owner.collectors.authority', $collector), ['authority' => 'siempre'])
            ->assertSessionHasErrors('authority');
    }

    /** 8 + 9 + 10 + 15 + 27: owner_required — dueño define, autoriza, entrega del cobrador y badge. */
    public function test_owner_required_flow_authorizes_and_collector_delivers_without_second_approval(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        // 27) el dueño recibe el pendiente accionable.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))->assertOk()->assertSee('data-bell-badge', false);

        // 8) el dueño define/ajusta condiciones.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $define = $this->get(route('cobros.owner.creditos.define', $request->id));
        $define->assertOk()->assertSee('REVISAR Y AUTORIZAR PRÉSTAMO');

        // 27) atender la solicitud limpia SU badge.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))->assertOk()->assertDontSee('data-bell-badge', false);

        // 9) el dueño autoriza (única autoridad económica).
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())
            ->assertRedirect(route('cobros.owner.creditos.show', CobrosCredit::firstOrFail()->id));
        $credit = CobrosCredit::firstOrFail();
        $this->assertSame($owner->id, (int) $credit->approved_by);

        // 15) política congelada en el snapshot de ESTA decisión.
        $snapshot = $credit->terms_snapshot;
        $this->assertSame('owner_required', $snapshot['authority_policy']);
        $this->assertSame($owner->id, (int) $snapshot['authorized_by']);
        $this->assertNotEmpty($snapshot['authorized_at']);

        // 10) el cobrador firma y entrega SIN segunda aprobación (9A.2 intacto).
        // 29) la entrega muestra "Cobrador" y jamás "Prestamista".
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.creditos.entrega', $credit->id))
            ->assertOk()->assertSee('Cobrador')->assertDontSee('Prestamista');
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])
            ->assertRedirect(route('cobros.collector.creditos.comprobante', $credit->id));

        // 20 + 21 + 22 + 30: una CxC, cuotas del contrato, assignment y firma.
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_credit_installments', 4);
        $this->assertDatabaseCount('cobros_credit_acceptances', 1);
        $this->assertTrue(
            CobrosAssignment::query()->forCompany($company->id)
                ->where('collector_user_id', $collector->id)->exists()
        );

        // 23 + 29 + 33: cartera, comprobante/PDF y datos históricos del dueño.
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.portfolio'))->assertOk()->assertSee('Maria Solis');
        $receipt = $this->get(route('cobros.collector.creditos.comprobante', $credit->id))->assertOk();
        $this->assertStringNotContainsString('Prestamista', $receipt->getContent());
        $this->get(route('cobros.collector.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]))->assertDownload();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.creditos.show', $credit->id))->assertOk();
        $this->get(route('cobros.owner.creditos.comprobante', $credit->id))->assertOk();
    }

    /** 11 + 12 + 13 + 14 + 28: collector_authorized — el cobrador decide y entrega; dueño no bloquea. */
    public function test_collector_authorized_flow_decides_and_delivers_without_owner(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->setPolicy((int) $company->id, (int) $collector->id, 'collector_authorized', (int) $owner->id);

        // 28) sin "pendiente de aprobación" falso para el dueño al enviar.
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $request = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))->assertOk()->assertDontSee('data-bell-badge', false);

        // 11) el cobrador revisa sus propios datos y define condiciones.
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.verify', $request->id), ['motivo' => 'Datos revisados con el cliente'])
            ->assertRedirect(route('cobros.collector.credit-requests.index'));
        $this->assertSame(CobrosCreditRequest::STATUS_VERIFIED, $request->fresh()->status);
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.prepare', $request->id))->assertOk();

        // 12) el cobrador autoriza (única autoridad económica).
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms())->assertRedirect();
        $credit = CobrosCredit::firstOrFail();
        $this->assertSame($collector->id, (int) $credit->approved_by);
        $this->assertSame('collector_authorized', $credit->terms_snapshot['authority_policy']);

        // 13) firma y entrega SIN dueño.
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])
            ->assertRedirect(route('cobros.collector.creditos.comprobante', $credit->id));
        $this->assertDatabaseCount('accounts_receivable', 1);

        // 14) el dueño NO bloquea la modalidad: puede consultar todo para supervisar.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.show', $request->id))->assertOk();
        $this->get(route('cobros.owner.creditos.show', $credit->id))->assertOk();

        // 16) cambio posterior de política NO altera el crédito anterior.
        $this->setPolicy((int) $company->id, (int) $collector->id, 'owner_required', (int) $owner->id);
        $credit->refresh();
        $this->assertSame('collector_authorized', $credit->terms_snapshot['authority_policy']);
        $this->assertSame('disbursed', $credit->status);
        $this->assertSame($collector->id, (int) $credit->approved_by);

        // Y una decisión futura ya exige al dueño.
        $request2 = $this->verifiedRequest($company, $owner, $route, $collector, $this->payload([
            'identification' => '776655443',
            'name' => 'Segundo Cliente',
        ]));
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.continuar', $request2->id), $this->terms())
            ->assertForbidden('16-decision-futura-owner-required');
        $this->assertSame(1, CobrosCredit::count(), '16-ningun-credito-sin-autorizacion');
    }

    /** 17 + 18 + 19: aislamiento — otro cobrador/ruta 403, otro tenant 404. */
    public function test_authority_is_blocked_for_other_collector_route_and_tenant(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->setPolicy((int) $company->id, (int) $collector->id, 'collector_authorized', (int) $owner->id);
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        // 17 + 18) otro cobrador (otra ruta) de la MISMA empresa: prohibido.
        $admin2 = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Sur', 'active' => true]);
        $collectorB = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $routeB->users()->attach($collectorB->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($routeB, $company, $admin2, now()->addMonth());
        $this->setPolicy((int) $company->id, (int) $collectorB->id, 'collector_authorized', (int) $owner->id);

        $this->asCollector($routeB, $collectorB);
        $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms())
            ->assertForbidden('17-otro-cobrador');
        $this->assertSame(0, CobrosCredit::count(), '17-ningun-credito-del-bloqueado');

        // El cobrador dueño de la solicitud SÍ puede decidir (no hubo interferencia).
        $this->asCollector($route, $collector);
        $this->post(route('cobros.collector.credit-requests.continuar', $request->id), $this->terms())
            ->assertRedirect();
        $this->assertSame(1, CobrosCredit::count());

        // 19) otro tenant: 404 (IDOR).
        session()->forget('active_company_id');
        [$companyX, $collectorX, $routeX] = $this->context();
        $this->asCollector($routeX, $collectorX);
        $this->get(route('cobros.collector.credit-requests.prepare', $request->id))->assertNotFound('19-otro-tenant');
    }

    /** 31 + 32: copy de consulta sin Buró/versión v1 y la consulta NO decide. */
    public function test_identity_consultation_copy_and_never_decides_the_credit(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        // 9C.3: la consulta exige policy collector_authorized (el bloqueo
        // owner_required se cubre en CobrosBureauAuthorityPolicyTest).
        $this->setPolicy((int) $company->id, (int) $collector->id, 'collector_authorized', (int) $owner->id);

        $this->asCollector($route, $collector);
        $html = $this->get(route('cobros.collector.credit-requests.create'))
            ->assertOk()
            ->assertSee('Autorización para consultar información')
            ->assertSee('El cliente autoriza la consulta de su información.')
            ->assertDontSee('Buró')
            ->assertDontSee('versión v1')
            ->getContent();

        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $request = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail();

        // 32) la consulta solo informa existencia; no aprueba ni rechaza.
        $this->asCollector($route, $collector);
        $response = $this->get(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'autorizacion' => 'acepto',
        ]));
        $response->assertOk()->assertJsonStructure(['found']);
        $this->assertSame(CobrosCreditRequest::STATUS_PENDING, $request->fresh()->status, 'la consulta no decide');
        $this->assertSame(0, CobrosCredit::count());

        // Sin autorización explícita no se consulta.
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => '102030405',
        ]))->assertStatus(302)->assertSessionHasErrors('autorizacion');
    }
}
