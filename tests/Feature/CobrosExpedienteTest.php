<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosAssignment;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditExpediente;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosExpedienteDocument;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 10A-Corrección — expediente PERMANENTE del cliente (company + customer),
 * accesible desde la ficha "VER CLIENTE", con o sin solicitud. Más
 * navegación del cobrador (barra inferior y menú "Más").
 */
class CobrosExpedienteTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers (mismo flujo real 9A/9C para solicitudes).
    // ------------------------------------------------------------------

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: User} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Expediente '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company, $collector, $route, $owner];
    }

    private function newCustomer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente Expediente '.uniqid(),
            'identification' => '9009999'.random_int(100, 999),
            'address' => 'San Ramon, calle 1',
            'is_active' => true,
        ]);
    }

    private function assign(Company $company, CobrosRoute $route, User $collector, Customer $customer, User $by): CobrosAssignment
    {
        return CobrosAssignment::create([
            'company_id' => $company->id,
            'cobros_route_id' => $route->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $collector->id,
            'assigned_by' => $by->id,
            'assigned_at' => now(),
        ]);
    }

    /** Cliente asignado al cobrador + dueño, listo para expediente. */
    private function scenario(): array
    {
        [$company, $collector, $route, $owner] = $this->context();
        $customer = $this->newCustomer($company);
        $this->assign($company, $route, $collector, $customer, $owner);

        return [$company, $collector, $route, $owner, $customer];
    }

    private function actAsCollector(User $collector, Company $company, CobrosRoute $route): void
    {
        $this->actingAs($collector)->withSession([
            'active_company_id' => $company->id,
            'active_cobros_route_id' => $route->id,
        ]);
    }

    private function actAsOwner(User $owner, Company $company): void
    {
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'occupation' => 'Carpintero',
            'workplace' => 'Ferreteria La Union',
            'income_amount' => '150000.00',
            'income_frequency' => 'monthly',
            'activity_time' => '3 años',
            'address_reference' => 'Frente a la iglesia, casa azul',
        ], $overrides);
    }

    private function expedienteOf(Customer $customer): CobrosCreditExpediente
    {
        return CobrosCreditExpediente::query()->withoutGlobalScopes()
            ->where('customer_id', $customer->id)->firstOrFail();
    }

    /** Solicitud real del flujo 9C vinculada a un cliente existente. */
    private function solicitudFor(Customer $customer, CobrosRoute $route, User $collector): CobrosCreditRequest
    {
        $payload = [
            'applicant_type' => 'nuevo',
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'name' => 'Maria Solis',
            'phone' => '88888888',
            'address' => 'San Ramon, calle 1',
            'amount_requested' => '1500.50',
            'notes' => 'Solicitud expediente',
        ];
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $payload + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->assertNotEmpty($m);
        $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $m[1]])->assertRedirect();

        $request = CobrosCreditRequest::forCompany($route->company_id)->orderByDesc('id')->firstOrFail();
        CobrosCreditRequest::withoutGlobalScopes()->where('id', $request->id)->update(['customer_id' => $customer->id]);

        return $request->refresh();
    }

    // ------------------------------------------------------------------
    // 1) Cliente SIN solicitud puede completir el expediente.
    // ------------------------------------------------------------------
    public function test_cliente_sin_solicitud_completa_expediente(): void
    {
        [$company, $collector, $route, , $customer] = $this->scenario();

        $this->actAsCollector($collector, $company, $route);
        $this->get(route('cobros.collector.customers.expediente', $customer->id))->assertOk();

        $this->actAsCollector($collector, $company, $route);
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id), $this->form())
            ->assertSessionHas('expediente_saved');

        $expediente = $this->expedienteOf($customer);
        $this->assertSame((int) $company->id, (int) $expediente->company_id);
        $this->assertSame('Carpintero', $expediente->occupation);
        $this->assertNull($expediente->credit_request_id);
        $this->assertSame(0, CobrosCreditRequest::query()->withoutGlobalScopes()->count());

        $this->actAsCollector($collector, $company, $route);
        $this->get(route('cobros.collector.customers.expediente', $customer->id))
            ->assertOk()->assertSee('Expediente: Incompleto')->assertSee('Carpintero');
    }

    // ------------------------------------------------------------------
    // 2) Varias solicitudes conservan UN expediente único.
    // ------------------------------------------------------------------
    public function test_varias_solicitudes_conservan_expediente_unico(): void
    {
        [$company, $collector, $route, , $customer] = $this->scenario();
        $first = $this->solicitudFor($customer, $route, $collector);
        $second = $this->solicitudFor($customer, $route, $collector);

        $this->actAsCollector($collector, $company, $route);
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id).'?solicitud='.$first->id,
            $this->form(['occupation' => 'Electricista']))->assertSessionHas('expediente_saved');

        $this->actAsCollector($collector, $company, $route);
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id).'?solicitud='.$second->id,
            $this->form(['occupation' => 'Plomero']))->assertSessionHas('expediente_saved');

        $this->assertSame(1, CobrosCreditExpediente::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->count());
        $expediente = $this->expedienteOf($customer);
        $this->assertSame('Plomero', $expediente->occupation);
        $this->assertSame((int) $first->id, (int) $expediente->credit_request_id);
    }

    // ------------------------------------------------------------------
    // 3) Rechazar una solicitud NO elimina ni modifica el expediente.
    // ------------------------------------------------------------------
    public function test_rechazar_solicitud_no_elimina_expediente(): void
    {
        [$company, $collector, $route, $owner, $customer] = $this->scenario();
        $request = $this->solicitudFor($customer, $route, $collector);

        $this->actAsCollector($collector, $company, $route);
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id).'?solicitud='.$request->id,
            $this->form())->assertSessionHas('expediente_saved');
        $before = $this->expedienteOf($customer)->getAttributes();

        $this->actAsOwner($owner, $company);
        $this->post(route('cobros.owner.solicitudes.reject', $request->id), ['motivo' => 'No cumple política interna'])
            ->assertRedirect();
        $this->assertSame(CobrosCreditRequest::STATUS_REJECTED, $request->refresh()->status);

        $after = $this->expedienteOf($customer);
        $this->assertEquals($before, $after->getAttributes());
        $this->assertSame(1, CobrosCreditExpediente::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->count());
    }

    // ------------------------------------------------------------------
    // 4) Edición y guardado parcial.
    // ------------------------------------------------------------------
    public function test_edicion_y_guardado_parcial(): void
    {
        [$company, $collector, $route, , $customer] = $this->scenario();
        $url = route('cobros.collector.customers.expediente.save', $customer->id);

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form([
            'references' => [['name' => 'Juan Perez', 'phone' => '77777777', 'relationship' => 'Hermano']],
        ]))->assertSessionHas('expediente_saved');

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, ['income_amount' => '175000'])->assertSessionHas('expediente_saved');

        $expediente = $this->expedienteOf($customer);
        $this->assertSame('175000.00', (string) $expediente->income_amount);
        $this->assertSame('Carpintero', $expediente->occupation);
        $this->assertSame('Frente a la iglesia, casa azul', $expediente->address_reference);
        $this->assertCount(1, $expediente->references);
    }

    // ------------------------------------------------------------------
    // 5) Fiador opcional + limpieza de datos.
    // ------------------------------------------------------------------
    public function test_fiador_opcional_y_limpieza(): void
    {
        [$company, $collector, $route, , $customer] = $this->scenario();
        $url = route('cobros.collector.customers.expediente.save', $customer->id);

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form([
            'has_guarantor' => '1',
            'guarantor_name' => 'Carlos Fiador',
            'guarantor_identification' => '202020202',
            'guarantor_phone' => '55555555',
        ]))->assertSessionHas('expediente_saved');
        $this->assertTrue((bool) $this->expedienteOf($customer)->has_guarantor);

        // Fiador NO: se limpia la PII.
        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form(['has_guarantor' => '0', 'guarantor_name' => 'Carlos Fiador']))
            ->assertSessionHas('expediente_saved');
        $expediente = $this->expedienteOf($customer);
        $this->assertFalse((bool) $expediente->has_guarantor);
        $this->assertNull($expediente->guarantor_name);
        $this->assertNull($expediente->guarantor_identification);
        $this->assertNull($expediente->guarantor_phone);
    }

    // ------------------------------------------------------------------
    // 6) Documentos privados + acceso autorizado (dueño y asignado).
    // ------------------------------------------------------------------
    public function test_documentos_privados_y_acceso_autorizado(): void
    {
        [$company, $collector, $route, $owner, $customer] = $this->scenario();
        $pdf = UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 expediente-10a');

        $this->actAsCollector($collector, $company, $route);
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id),
            $this->form(['doc_income' => $pdf]))->assertSessionHas('expediente_saved');

        $document = CobrosExpedienteDocument::query()->withoutGlobalScopes()
            ->where('expediente_id', $this->expedienteOf($customer)->id)->where('doc_type', 'income')->firstOrFail();
        $this->assertStringStartsWith('cobros/credit_expedientes/', $document->path);
        $this->assertTrue(Storage::disk('local')->exists($document->path));
        $this->assertFalse(Storage::disk('public')->exists($document->path));

        $this->actAsCollector($collector, $company, $route);
        $response = $this->get(route('cobros.collector.customers.expediente.document', [$customer->id, 'income']))
            ->assertOk();
        $this->assertSame(
            realpath(Storage::disk('local')->path($document->path)),
            realpath($response->baseResponse->getFile()->getPathname())
        );

        // El dueño también puede verlo.
        $this->actAsOwner($owner, $company);
        $this->get(route('cobros.owner.customers.expediente.document', [$customer->id, 'income']))->assertOk();
    }

    // ------------------------------------------------------------------
    // 7) Aislamiento entre empresas (404).
    // ------------------------------------------------------------------
    public function test_aislamiento_entre_empresas(): void
    {
        [, , , , $customer] = $this->scenario();

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        $other = Company::create(['trade_name' => 'Otra '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($other, $admin, ['cobros', 'notifications']);
        $intruder = $this->member($other, ['cobros.acceder', 'cobros.cobrar']);
        $otherRoute = CobrosRoute::create(['company_id' => $other->id, 'name' => 'Ruta Ajena', 'active' => true]);
        $otherRoute->users()->attach($intruder->id, ['active' => true]);

        $this->actAsCollector($intruder, $other, $otherRoute);
        $this->get(route('cobros.collector.customers.expediente', $customer->id))->assertNotFound();
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id), $this->form())
            ->assertNotFound();
        $this->assertSame(0, CobrosCreditExpediente::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------
    // 8) Cobrador SIN asignación recibe 403.
    // ------------------------------------------------------------------
    public function test_collector_sin_asignacion_recibe_403(): void
    {
        [$company, , $route, , $customer] = $this->scenario();
        $stranger = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $strangerRoute = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta 2', 'active' => true]);
        $strangerRoute->users()->attach($stranger->id, ['active' => true]);

        $this->actAsCollector($stranger, $company, $strangerRoute);
        $this->get(route('cobros.collector.customers.expediente', $customer->id))->assertForbidden();
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id), $this->form())
            ->assertForbidden();
        $this->get(route('cobros.collector.customers.expediente.document', [$customer->id, 'income']))
            ->assertForbidden();
        $this->assertSame(0, CobrosCreditExpediente::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------
    // 9) Menú "Más" completo y SIN duplicados para el cobrador.
    // ------------------------------------------------------------------
    public function test_menu_mas_completo_sin_duplicados(): void
    {
        [$company, $collector, $route] = $this->context();

        $this->actAsCollector($collector, $company, $route);
        $html = $this->get(route('cobros.collector.my-customers'))->assertOk()->getContent();

        foreach (['Agenda hoy', 'Mi cartera', 'Mis clientes', 'Cierre del d', 'Mis recibos', 'Mis solicitudes', 'Mis préstamos'] as $label) {
            // title= solo existe en los ítems del nav: exactamente 1 = sin duplicados.
            $this->assertSame(1, substr_count($html, 'title="'.$label), "Etiqueta '{$label}' duplicada o ausente.");
        }
        $this->assertSame(1, substr_count($html, 'id="cobros-more"'), 'El botón Más debe existir una sola vez.');
        // Nada de módulos ajenos (Commerce / Fidelización).
        $this->assertStringNotContainsString('Fidelizaci', $html);
        $this->assertStringNotContainsString('Punto de venta', $html);
    }

    // ------------------------------------------------------------------
    // 10) Navegación de dueño/supervisor intacta.
    // ------------------------------------------------------------------
    public function test_navegacion_dueno_intacta(): void
    {
        [$company, , , $owner] = $this->context();

        $this->actAsOwner($owner, $company);
        $html = $this->get(route('cobros.owner.dashboard'))->assertOk()->getContent();

        foreach (['Inicio / Cartera', 'Cobradores', 'Solicitudes'] as $label) {
            $this->assertSame(1, substr_count($html, 'title="'.$label), "Etiqueta '{$label}' duplicada o ausente.");
        }
        $this->assertStringNotContainsString('Mis recibos', $html);
        $this->assertStringNotContainsString('Mis clientes', $html);
    }

    // ------------------------------------------------------------------
    // 11) Créditos, cuotas, pagos y recibos SIN cambios por el expediente.
    // ------------------------------------------------------------------
    public function test_creditos_cuotas_pagos_recibos_sin_cambios(): void
    {
        [$company, $collector, $route, , $customer] = $this->scenario();

        $this->actAsCollector($collector, $company, $route);
        $this->post(route('cobros.collector.customers.expediente.save', $customer->id), $this->form([
            'has_guarantor' => '1',
            'guarantor_name' => 'Carlos Fiador',
            'guarantor_identification' => '202020202',
            'guarantor_phone' => '55555555',
            'references' => [['name' => 'Juan Perez', 'phone' => '77777777', 'relationship' => 'Hermano']],
            'doc_income' => UploadedFile::fake()->createWithContent('ingreso.pdf', '%PDF-1.4 ingreso'),
        ]))->assertSessionHas('expediente_saved');

        $this->assertSame(0, CobrosCredit::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, CobrosCreditInstallment::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AccountReceivable::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AccountReceivablePayment::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, CobrosReceipt::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------
    // 12) Validaciones server-side + XSS escapado.
    // ------------------------------------------------------------------
    public function test_validaciones_server_side_y_xss_escapado(): void
    {
        [$company, $collector, $route, , $customer] = $this->scenario();
        $url = route('cobros.collector.customers.expediente.save', $customer->id);

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form(['income_frequency' => 'yearly']))->assertSessionHasErrors('income_frequency');

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form(['income_amount' => 'mil colones']))->assertSessionHasErrors('income_amount');

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form(['doc_income' => UploadedFile::fake()->create('notas.txt', 5, 'text/plain')]))
            ->assertSessionHasErrors('doc_income');

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form(['references' => [
            ['name' => 'A', 'phone' => '1', 'relationship' => 'Amigo'],
            ['name' => 'B', 'phone' => '2', 'relationship' => 'Amigo'],
            ['name' => 'C', 'phone' => '3', 'relationship' => 'Amigo'],
        ]]))->assertSessionHasErrors('references');

        $this->actAsCollector($collector, $company, $route);
        $this->post($url, $this->form(['occupation' => '<script>alert(1)</script>']))->assertSessionHas('expediente_saved');

        $this->actAsCollector($collector, $company, $route);
        $this->get(route('cobros.collector.customers.expediente', $customer->id))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
