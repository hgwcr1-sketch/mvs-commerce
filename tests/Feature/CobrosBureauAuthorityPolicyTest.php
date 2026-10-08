<?php

namespace Tests\Feature;

use App\Models\CobrosBureauEvent;
use App\Models\CobrosCollectorAuthorityPolicy;
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
 * 9C.3 — La MISMA política de autoridad que decide quién aprueba el préstamo
 * decide quién CONSULTA/VE la información de evaluación del solicitante.
 * Ejecutar POR SEPARADO.
 */
class CobrosBureauAuthorityPolicyTest extends TestCase
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
        $company = Company::create(['trade_name' => 'BureauAuth '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
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

    private function send(Company $company, CobrosRoute $route, User $collector, array $payload = []): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), ($payload ?: $this->payload()) + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), ($payload ?: $this->payload()) + ['review_token' => $m[1]])->assertRedirect();
    }

    private function asCollector(CobrosRoute $route, User $collector): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);
    }

    private function setPolicy(int $companyId, int $collectorId, string $policy): void
    {
        CobrosCollectorAuthorityPolicy::set($companyId, $collectorId, $policy);
    }

    private function queryParams(string $identification = '102030405'): array
    {
        return [
            'identification_type' => 'cedula',
            'identification' => $identification,
            'autorizacion' => 'acepto',
        ];
    }

    /** 1 + 3 + 2: owner_required — bloquea al cobrador (sin resultado) y permite a la oficina. */
    public function test_owner_required_blocks_collector_and_allows_office(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->send($company, $route, $collector); // default owner_required

        // 1 + 3) el cobrador NO ejecuta ni recibe resultado sensible.
        $this->asCollector($route, $collector);
        $blocked = $this->getJson(route('cobros.collector.credit-requests.bureau-check', $this->queryParams()))
            ->assertForbidden();
        $this->assertStringNotContainsString('"found"', $blocked->getContent(), '3-sin-resultado-sensible');

        // 2) la oficina legítima (dentro del expediente) sí consulta.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->getJson(route('cobros.owner.solicitudes.bureau-check', $this->queryParams()))
            ->assertOk()
            ->assertJson(['found' => true]);

        // 2b) la oficina NO puede buscar cédulas arbitrarias sin expediente.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->getJson(route('cobros.owner.solicitudes.bureau-check', $this->queryParams('999999999')))
            ->assertForbidden();
    }

    /** 4 + 5: collector_authorized — dueño de la solicitud sí; otro cobrador, no. */
    public function test_collector_authorized_allows_owner_collector_and_blocks_others(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->setPolicy((int) $company->id, (int) $collector->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);
        $this->send($company, $route, $collector);

        // 4) el cobrador dueño de la solicitud sí consulta.
        $this->asCollector($route, $collector);
        $this->getJson(route('cobros.collector.credit-requests.bureau-check', $this->queryParams()))
            ->assertOk()
            ->assertJson(['found' => true]);

        // 5) otro cobrador (misma empresa, también collector_authorized): 403.
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Sur', 'active' => true]);
        $collectorB = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $routeB->users()->attach($collectorB->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($routeB, $company, $admin, now()->addMonth());
        $this->setPolicy((int) $company->id, (int) $collectorB->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);

        $this->asCollector($routeB, $collectorB);
        $this->getJson(route('cobros.collector.credit-requests.bureau-check', $this->queryParams()))
            ->assertForbidden();
    }

    /** 6 + 7: otra ruta → 403; otro tenant (oficina ajena sin expediente) → 403. */
    public function test_route_and_tenant_isolation(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->setPolicy((int) $company->id, (int) $collector->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);
        $this->send($company, $route, $collector);

        // 6) sesión activa en OTRA ruta del mismo cobrador: 403.
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $routeB = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Sur', 'active' => true]);
        $routeB->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($routeB, $company, $admin, now()->addMonth());
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $routeB->id]);
        $this->getJson(route('cobros.collector.credit-requests.bureau-check', $this->queryParams()))
            ->assertForbidden();

        // 7) otro tenant: la oficina ajena no tiene expediente → 403 (sin datos).
        session()->forget('active_company_id');
        [$companyX, $collectorX, $routeX, $ownerX] = $this->context();
        $this->send($companyX, $routeX, $collectorX, $this->payload(['identification' => '554433221', 'name' => 'Otro Cliente']));
        $this->actingAs($ownerX)->withSession(['active_company_id' => $companyX->id]);
        $this->getJson(route('cobros.owner.solicitudes.bureau-check', $this->queryParams()))
            ->assertForbidden();
    }

    /** 8: sin autorización del cliente NO se consulta (validación antes de la policy). */
    public function test_consultation_requires_client_authorization(): void
    {
        [$company, $collector, $route] = $this->context();
        $this->setPolicy((int) $company->id, (int) $collector->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);

        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => '102030405',
        ]))->assertStatus(302)->assertSessionHasErrors('autorizacion');
        $this->assertSame(0, CobrosBureauEvent::count(), 'sin autorización no hay consulta ni auditoría');
    }

    /** 9: auditoría registra solicitud/identity, actor, fecha, purpose, versión y contexto company. */
    public function test_audit_records_actor_purpose_version_and_context(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->send($company, $route, $collector); // owner_required → consulta la oficina

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->getJson(route('cobros.owner.solicitudes.bureau-check', $this->queryParams()))
            ->assertOk()->assertJson(['found' => true]);

        $event = CobrosBureauEvent::query()
            ->where('event_type', CobrosBureauEvent::TYPE_IDENTITY_ACCESSED)
            ->where('actor_user_id', $owner->id)
            ->latest('id')->firstOrFail();
        $this->assertNotNull($event->bureau_identity_id, 'identity');
        $this->assertSame($owner->id, (int) $event->actor_user_id, 'actor');
        $this->assertSame('evaluacion_solicitud_credito::autorizacion_v1', $event->purpose, 'purpose+versión');
        $this->assertSame((int) $company->id, (int) $event->source_company_id, 'contexto company');
        $this->assertNotNull($event->created_at, 'fecha/hora');
        // Consentimiento existente intacto (misma purpose de siempre).
        $this->assertSame(
            'evaluacion_solicitud_credito::autorizacion_v1',
            $event->purpose
        );
    }

    /** 10: UI sin "Buró"/"versión v1" y sin endpoint de consulta bajo owner_required. */
    public function test_ui_keeps_copy_and_hides_consultation_under_owner_required(): void
    {
        [$company, $collector, $route] = $this->context();

        // owner_required: la página de captura NO ofrece la consulta.
        $this->asCollector($route, $collector);
        $html = $this->get(route('cobros.collector.credit-requests.create'))
            ->assertOk()
            ->assertDontSee('bureau-check')
            ->assertDontSee('Buró')
            ->assertDontSee('versión v1')
            ->assertSee('Autorización para consultar información')
            ->getContent();

        // collector_authorized: sí se ofrece (y la policy server-side lo permite).
        $this->setPolicy((int) $company->id, (int) $collector->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);
        $this->asCollector($route, $collector);
        $this->get(route('cobros.collector.credit-requests.create'))
            ->assertOk()
            ->assertSee('bureau-check')
            ->assertDontSee('Buró')
            ->assertDontSee('versión v1');
    }
}
