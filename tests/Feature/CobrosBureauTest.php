<?php

namespace Tests\Feature;

use App\Models\CobrosBureauEvent;
use App\Models\CobrosBureauIdentity;
use App\Models\CobrosBureauIdentityDocument;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CobrosBureauService;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** BLOQUE 7B — Fundación Buró: identidad global, evidencias y eventos. SIN scoring. */
class CobrosBureauTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array{0: Company, 1: User, 2: CobrosRoute} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Buró '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta '.uniqid(), 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

        // 9C.3: la consulta exige policy collector_authorized (default owner_required).
        \App\Models\CobrosCollectorAuthorityPolicy::set(
            (int) $company->id,
            (int) $collector->id,
            \App\Models\CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED
        );

        return [$company, $collector, $route];
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

    private function photos(): array
    {
        return [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 120, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 120, 80),
        ];
    }

    /** Revisión (con fotos) + envío con el token devuelto. */
    private function sendFlow(array $payload): TestResponse
    {
        $review = $this->post(route('cobros.collector.credit-requests.review'), $payload + $this->photos());
        $review->assertOk();

        if (! preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $matches)) {
            $this->fail('La revisión no expuso el token de revisión.');
        }

        return $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $matches[1]]);
    }

    private function lastRequestRow(): object
    {
        return DB::table('cobros_credit_requests')->orderByDesc('id')->first();
    }

    public function test_same_country_type_and_number_yield_one_global_identity(): void
    {
        [$companyA, $collectorA] = $this->context();
        [$companyB, $collectorB] = $this->context();

        $this->actingAs($collectorA)->withSession(['active_company_id' => $companyA->id]);
        $this->sendFlow($this->payload(['name' => 'Maria Solis']))->assertRedirect();

        $this->actingAs($collectorB)->withSession(['active_company_id' => $companyB->id]);
        $this->sendFlow($this->payload(['name' => 'Otro Nombre']))->assertRedirect();

        $this->assertDatabaseCount('cobros_bureau_identities', 1);
        $ids = DB::table('cobros_credit_requests')->orderBy('id')->pluck('bureau_identity_id');
        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertNotNull($ids[0]);
    }

    public function test_different_names_do_not_create_different_identities(): void
    {
        [$companyA, $collectorA] = $this->context();
        [$companyB, $collectorB] = $this->context();

        $this->actingAs($collectorA)->withSession(['active_company_id' => $companyA->id]);
        $this->sendFlow($this->payload(['name' => 'Carlos Ruiz']))->assertRedirect();

        $this->actingAs($collectorB)->withSession(['active_company_id' => $companyB->id]);
        $this->sendFlow($this->payload(['name' => 'Ana Lopez']))->assertRedirect();

        $this->assertSame(1, CobrosBureauIdentity::count());
        $identity = CobrosBureauIdentity::first();
        $this->assertSame('cedula', $identity->identification_type);
        $this->assertSame('102030405', $identity->normalized_identification);
    }

    public function test_new_and_existing_applicants_link_same_identity_without_new_customer(): void
    {
        [$companyA, $collectorA] = $this->context();
        [$companyB, $collectorB] = $this->context();
        $customer = Customer::create([
            'company_id' => $companyB->id,
            'name' => 'Cliente Existente',
            'customer_type' => 'individual',
            'identification' => '102030405',
            'phone' => '88888888',
            'is_active' => true,
        ]);

        $this->actingAs($collectorA)->withSession(['active_company_id' => $companyA->id]);
        $this->sendFlow($this->payload(['name' => 'Nuevo']))->assertRedirect();

        $this->actingAs($collectorB)->withSession(['active_company_id' => $companyB->id]);
        $this->sendFlow($this->payload(['applicant_type' => 'existente', 'customer_id' => $customer->id]))->assertRedirect();

        $this->assertSame(1, CobrosBureauIdentity::count());
        $ids = DB::table('cobros_credit_requests')->orderBy('id')->pluck('bureau_identity_id');
        $this->assertSame($ids[0], $ids[1]);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame((int) $customer->id, (int) DB::table('cobros_credit_requests')->orderByDesc('id')->first()->customer_id);
    }

    public function test_submit_registers_front_back_evidences_with_origin_and_events(): void
    {
        [$company, $collector] = $this->context();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->sendFlow($this->payload())->assertRedirect();

        $request = $this->lastRequestRow();
        $this->assertNotNull($request->bureau_identity_id);

        $documents = CobrosBureauIdentityDocument::orderBy('document_type')->get();
        $this->assertCount(2, $documents);
        $byType = $documents->keyBy('document_type');
        $this->assertSame('front', $byType['front']->document_type);
        $this->assertSame('back', $byType['back']->document_type);
        foreach ($documents as $document) {
            $this->assertSame((int) $request->bureau_identity_id, (int) $document->bureau_identity_id);
            $this->assertSame((int) $request->id, (int) $document->source_credit_request_id);
            $this->assertSame((int) $company->id, (int) $document->source_company_id);
            $this->assertSame($collector->id, $document->source_collector_user_id);
            $this->assertNotNull($document->uploaded_at);
            // Misma ruta privada de 7A: sin copia física del archivo.
            $this->assertContains($document->private_path, [$request->id_front_path, $request->id_back_path]);
        }

        $events = CobrosBureauEvent::pluck('event_type');
        $this->assertSame(1, $events->filter(fn ($e) => $e === CobrosBureauEvent::TYPE_IDENTITY_CREATED)->count());
        $this->assertSame(1, $events->filter(fn ($e) => $e === CobrosBureauEvent::TYPE_IDENTITY_LINKED)->count());
        $this->assertSame(1, $events->filter(fn ($e) => $e === CobrosBureauEvent::TYPE_REQUEST_SUBMITTED)->count());
        $this->assertSame(2, $events->filter(fn ($e) => $e === CobrosBureauEvent::TYPE_DOCUMENT_ATTACHED)->count());

        $created = CobrosBureauEvent::where('event_type', CobrosBureauEvent::TYPE_IDENTITY_CREATED)->first();
        $this->assertSame((int) $company->id, (int) $created->source_company_id);
        $this->assertSame($collector->id, $created->actor_user_id);
        $this->assertNotNull($created->occurred_at);
    }

    public function test_resolution_is_idempotent_and_survives_concurrent_insert_race(): void
    {
        [$company, $collector] = $this->context();
        $service = app(CobrosBureauService::class);

        $first = $service->resolveIdentity($company, 'cedula', '1.234.567-8', $collector, null, 't');
        $second = $service->resolveIdentity($company, 'cedula', '12345678', $collector, null, 't');

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['identity']->id, $second['identity']->id);
        $this->assertDatabaseCount('cobros_bureau_identities', 1);

        // Carrera: otra transacción inserta la misma clave -> el índice único
        // la rechaza y el flujo reutiliza la identidad existente (ya cubierto arriba).
        try {
            DB::table('cobros_bureau_identities')->insert([
                'country_code' => $first['identity']->country_code,
                'identification_type' => $first['identity']->identification_type,
                'normalized_identification' => $first['identity']->normalized_identification,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('El índice único de identidad no protege contra duplicados.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('cobros_bureau_identities', 1);
    }

    public function test_bureau_check_is_audited_and_exposes_only_existence(): void
    {
        [$companyA, $collectorA] = $this->context();
        [$companyB, $collectorB] = $this->context();

        $this->actingAs($collectorA)->withSession(['active_company_id' => $companyA->id]);
        $this->sendFlow($this->payload())->assertRedirect();

        $this->actingAs($collectorB)->withSession(['active_company_id' => $companyB->id]);
        $response = $this->get(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'autorizacion' => 'acepto',
        ]));
        $response->assertOk()->assertExactJson(['found' => true]);

        $access = CobrosBureauEvent::where('event_type', CobrosBureauEvent::TYPE_IDENTITY_ACCESSED)->get();
        $this->assertCount(1, $access);
        $this->assertSame((int) $companyB->id, (int) $access->first()->source_company_id);
        $this->assertSame($collectorB->id, $access->first()->actor_user_id);
        $this->assertSame('evaluacion_solicitud_credito::autorizacion_v1', $access->first()->purpose);

        // 9C: sin autorización explícita NO se consulta Buró.
        $this->get(route('cobros.collector.credit-requests.bureau-check', [
            'identification_type' => 'cedula',
            'identification' => '102030405',
        ]))->assertStatus(302)->assertSessionHasErrors('autorizacion');
        $this->assertCount(1, CobrosBureauEvent::where('event_type', CobrosBureauEvent::TYPE_IDENTITY_ACCESSED)->get());

        // Sin parámetros: validación normal de Laravel (redirect con errores),
        // igual que el resto de rutas web del proyecto.
        $this->getJson(route('cobros.collector.credit-requests.bureau-check'))
            ->assertStatus(302)
            ->assertSessionHasErrors('identification');
    }

    public function test_document_access_is_blocked_for_other_company(): void
    {
        [$companyA, $collectorA] = $this->context();
        [$companyB] = $this->context();

        $this->actingAs($collectorA)->withSession(['active_company_id' => $companyA->id]);
        $this->sendFlow($this->payload())->assertRedirect();

        $document = CobrosBureauIdentityDocument::where('document_type', 'front')->firstOrFail();
        $service = app(CobrosBureauService::class);

        $this->assertNull($service->accessDocumentPath($document->id, $companyB));
        $this->assertSame($document->private_path, $service->accessDocumentPath($document->id, $companyA));
    }

    public function test_no_arbitrary_bureau_search_endpoint_exists(): void
    {
        $bureauRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getName(), 'bureau'));

        // 9C.3: solo los DOS endpoints policy-gated (cobrador + oficina);
        // ninguno de búsqueda arbitraria.
        $this->assertCount(2, $bureauRoutes);
        $names = $bureauRoutes->map(fn ($route) => $route->getName())->all();
        sort($names);
        $this->assertSame(
            ['cobros.collector.credit-requests.bureau-check', 'cobros.owner.solicitudes.bureau-check'],
            $names
        );
        $bureauRoutes->each(fn ($route) => $this->assertMatchesRegularExpression('{/(bureau-check|consulta)$}', $route->uri()));
    }

    public function test_submit_creates_no_customer_and_no_financial_records(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        $this->sendFlow($this->payload())->assertRedirect();

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('cobros_bureau_identities', 1);
        $this->assertSame(1, CobrosCreditRequest::count());
    }
}
