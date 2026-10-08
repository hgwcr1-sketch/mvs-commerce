<?php

namespace Tests\Feature;

use App\Models\CobrosCollectorAuthorityPolicy;
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
use Tests\TestCase;

/**
 * 9C.4 — UX owner_required en el detalle del dueño: consulta con la política
 * existente (9C.3), copy humano sin Buró/IDs, flujo
 * SOLICITUD → CONSULTAR → REVISAR → PREPARAR PRÉSTAMO o NO AUTORIZAR.
 * Ejecutar POR SEPARADO.
 */
class CobrosOwnerConsultUITest extends TestCase
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
        $company = Company::create(['trade_name' => 'OwnerUI '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'San Ramon', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);

        return [$company, $collector, $route, $owner, $admin];
    }

    private function payload(): array
    {
        return [
            'applicant_type' => 'nuevo',
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'name' => 'Ligia Jacqueline Leiton Carranza',
            'phone' => '88888888',
            'address' => 'San Ramon, calle 1',
            'amount_requested' => '1500.50',
            'notes' => 'Solicitud',
        ];
    }

    private function submitted(Company $company, CobrosRoute $route, User $collector): CobrosCreditRequest
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload() + ['review_token' => $m[1]])->assertRedirect();

        return CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail();
    }

    private function asOwner(User $owner, Company $company): void
    {
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
    }

    private function asCollector(CobrosRoute $route, User $collector): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);
    }

    private function terms(): array
    {
        return [
            'principal' => '1000.00',
            'interest_type' => 'percent',
            'interest_value' => '12.5',
            'installment_count' => 4,
            'frequency' => 'monthly',
            'first_due_date' => now()->addWeek()->toDateString(),
            'late_fee_type' => 'none',
            'late_fee_value' => '0',
            'late_fee_grace_days' => 0,
        ];
    }

    /** 1 + 2 + 3 + 4 + 5 + 8 + 9: sección de consulta con endpoint existente, consentimiento y copy limpio. */
    public function test_owner_required_shows_consult_section_with_existing_endpoint_and_clean_copy(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->submitted($company, $route, $collector); // default owner_required

        $this->asOwner($owner, $company);
        $html = $this->get(route('cobros.owner.solicitudes.show', $request->id))
            ->assertOk()
            // 1) sección humana de consulta.
            ->assertSee('Información para evaluar la solicitud')
            ->assertSee('Esta información sirve como apoyo y no aprueba ni rechaza')
            ->assertSee('CONSULTAR INFORMACIÓN')
            ->assertSee('Solicitud → Consultar información → Revisar → Preparar préstamo o No autorizar')
            // 2) usa EXCLUSIVAMENTE el endpoint existente 9C.3.
            ->assertSee(str_replace('/', '\/', route('cobros.owner.solicitudes.bureau-check')), false)
            // 3) consentimiento obligatorio antes de consultar.
            ->assertSee('El cliente autoriza la consulta de su información.')
            ->assertSee('office-consent')
            ->assertSee('disabled', false)
            // 4 + 5) copy humano: sin Buró, sin IDs internos de identity.
            ->assertDontSee('Buró')
            ->assertDontSee('versión v1')
            ->assertDontSee('(#')
            ->assertSee('Información de identidad')
            ->assertSee('Registrada')
            // 8 + 9) corrección y no autorizar siguen disponibles.
            ->assertSee('SOLICITAR CORRECCIÓN')
            ->assertSee('NO AUTORIZAR')
            ->getContent();

        $this->assertStringNotContainsString('Identidad en Buró', $html);
        $this->assertStringNotContainsString('Buro', $html);

        // 3) el endpoint exige el consentimiento ANTES de consultar.
        $this->asOwner($owner, $company);
        $this->get(route('cobros.owner.solicitudes.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => $request->identification,
        ]))->assertStatus(302)->assertSessionHasErrors('autorizacion');

        // 2b) la consulta real desde esta pantalla responde con el resultado permitido.
        $this->asOwner($owner, $company);
        $this->getJson(route('cobros.owner.solicitudes.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => $request->identification,
            'autorizacion' => 'acepto',
        ]))->assertOk()->assertJson(['found' => true]);
    }

    /** 6 + 7: revisión → PREPARAR PRÉSTAMO (form 9A) → autorización única, sin doble aprobación. */
    public function test_review_flows_to_prepare_loan_with_single_approval(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->submitted($company, $route, $collector);

        // Estado previo: botón de revisión presente.
        $this->asOwner($owner, $company);
        $this->get(route('cobros.owner.solicitudes.show', $request->id))
            ->assertOk()
            ->assertSee('REVISAR Y CONFIRMAR DATOS')
            ->assertDontSee('PREPARAR PRÉSTAMO');

        // Revisar y confirmar (transición existente, NO aprobación).
        $this->asOwner($owner, $company);
        $this->post(route('cobros.owner.solicitudes.verify', $request->id), ['motivo' => 'Datos revisados'])
            ->assertRedirect();
        $this->assertSame(CobrosCreditRequest::STATUS_VERIFIED, $request->fresh()->status);

        // 6) ahora existe la acción humana PREPARAR PRÉSTAMO.
        $this->asOwner($owner, $company);
        $this->get(route('cobros.owner.solicitudes.show', $request->id))
            ->assertOk()
            ->assertSee('PREPARAR PRÉSTAMO')
            ->assertSee('CONSULTAR INFORMACIÓN'); // sigue disponible en verificada

        // 7) sin segunda confirmación de revisión (no doble aprobación en esta pantalla).
        $this->asOwner($owner, $company);
        $this->get(route('cobros.owner.solicitudes.show', $request->id))
            ->assertDontSee('REVISAR Y CONFIRMAR DATOS');

        // Formulario financiero 9A existente + autorización UNA sola vez.
        $this->asOwner($owner, $company);
        $this->get(route('cobros.owner.creditos.define', $request->id))->assertOk();
        $this->asOwner($owner, $company);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())
            ->assertRedirect(route('cobros.owner.creditos.show', CobrosCredit::firstOrFail()->id));
        $this->assertSame(1, CobrosCredit::count(), '7-unica-autorizacion');
    }

    /** 10 + 11 + 12: seguridad 9C.3 intacta; collector_authorized sin botón de consulta del dueño. */
    public function test_security_rules_remain_intact_across_policies(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->submitted($company, $route, $collector);

        // 10) owner_required: el cobrador sigue sin consultar.
        $this->asCollector($route, $collector);
        $this->getJson(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => $request->identification,
            'autorizacion' => 'acepto',
        ]))->assertForbidden();

        // 11) collector_authorized: el cobrador legítimo sí consulta; el dueño
        // supervisa SIN botón bloqueante de consulta.
        CobrosCollectorAuthorityPolicy::set((int) $company->id, (int) $collector->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);
        $this->asCollector($route, $collector);
        $this->getJson(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => $request->identification,
            'autorizacion' => 'acepto',
        ]))->assertOk()->assertJson(['found' => true]);

        $this->asOwner($owner, $company);
        $this->get(route('cobros.owner.solicitudes.show', $request->id))
            ->assertOk()
            ->assertDontSee('CONSULTAR INFORMACIÓN');

        // 12) otro tenant: 404 sobre el expediente ajeno.
        session()->forget('active_company_id');
        [$companyX, $collectorX, $routeX, $ownerX] = $this->context();
        $this->asOwner($ownerX, $companyX);
        $this->get(route('cobros.owner.solicitudes.show', $request->id))->assertNotFound();
    }
}
