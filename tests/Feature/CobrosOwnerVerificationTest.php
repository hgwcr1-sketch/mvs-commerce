<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** BLOQUE 8 — verificación de solicitudes (dueño) + corrección del cobrador. */
class CobrosOwnerVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fuente fiscal/lookup: sin llamadas reales de red en pruebas.
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

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: User} dueño en índice 3 */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Verif '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

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

    /** Envía la solicitud completa como el cobrador (multipart review + store). */
    private function sendAsCollector(array $payload, CobrosRoute $route, User $collector, array $extra = []): TestResponse
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $payload + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ] + $extra);
        $review->assertOk();

        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->assertNotEmpty($m, 'token de revisión ausente');

        return $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $m[1]] + $extra);
    }

    public function test_owner_bandeja_groups_by_route_hides_other_tenant_and_shows_nav(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();

        // Nueva empresa en sesión limpia (evita el guard de tenant al crear rutas).
        session()->forget('active_company_id');
        [$otherCompany, $otherCollector, $otherRoute] = $this->context();
        $this->sendAsCollector($this->payload(['identification' => '998877665', 'name' => 'Cliente Ajeno']), $otherRoute, $otherCollector)->assertRedirect();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $page = $this->get(route('cobros.owner.solicitudes.index'));
        $page->assertOk()->assertSee('Maria Solis')->assertSee('Ruta Norte')->assertSee('Solicitudes');
        $page->assertDontSee('Cliente Ajeno')->assertDontSee('998877665');

        // Filtro por ruta.
        $this->get(route('cobros.owner.solicitudes.index', ['route_id' => $route->id]))->assertOk()->assertSee('Maria Solis');
    }

    public function test_owner_detail_idor_and_private_photos_are_404_for_foreign_tenant(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->firstOrFail()->id;

        session()->forget('active_company_id');
        [$otherCompany, $otherCollector, $otherRoute, $otherOwner] = $this->context();
        $this->sendAsCollector($this->payload(['identification' => '554433221']), $otherRoute, $otherCollector)->assertRedirect();

        // Dueño ajeno: 404 en detalle y foto (IDOR).
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $otherCompany->id]);
        $this->get(route('cobros.owner.solicitudes.show', $id))->assertNotFound();
        $this->get(route('cobros.owner.solicitudes.photo', ['id' => $id, 'lado' => 'front']))->assertNotFound();

        // Dueño propio: 200 con datos, foto binaria privada y sin URL pública.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $detail = $this->get(route('cobros.owner.solicitudes.show', $id));
        $detail->assertOk()->assertSee('Maria Solis')->assertSee('Sin resultado');
        $detail->assertDontSee('storage/cobros')->assertDontSee('Storage::url');

        $photo = $this->get(route('cobros.owner.solicitudes.photo', ['id' => $id, 'lado' => 'front']));
        $photo->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->assertStringStartsWith('cobros/credit_requests/', CobrosCreditRequest::find($id)->id_front_path);

        // El cobrador (sin portal_dueno) no accede a la bandeja del dueño.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))->assertForbidden();
    }

    public function test_verify_correct_reject_transitions_with_motivo_and_append_only_logs(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->firstOrFail()->id;
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);

        // Corrección sin motivo → validación.
        $this->post(route('cobros.owner.solicitudes.correct', $id))->assertSessionHasErrors('motivo');
        // Rechazo sin motivo → validación.
        $this->post(route('cobros.owner.solicitudes.reject', $id))->assertSessionHasErrors('motivo');
        $this->assertSame('pendiente', CobrosCreditRequest::find($id)->status);

        // Corrección con motivo → requiere_correccion + log append-only.
        $this->post(route('cobros.owner.solicitudes.correct', $id), ['motivo' => 'Falta foto del reverso nítida'])
            ->assertRedirect(route('cobros.owner.solicitudes.show', $id));
        $row = CobrosCreditRequest::find($id);
        $this->assertSame('requiere_correccion', $row->status);
        $log = $row->latestLog();
        $this->assertSame('pendiente', $log->from_status);
        $this->assertSame('requiere_correccion', $log->to_status);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame('Falta foto del reverso nítida', $log->notes);
        $this->assertNotNull($log->created_at);

        // Desde requiere_correccion: verificar no está permitido (viene por pendiente).
        $this->post(route('cobros.owner.solicitudes.verify', $id))->assertSessionHasErrors('transition');
        $this->assertSame('requiere_correccion', CobrosCreditRequest::find($id)->status);

        // El cobrador ve estado + motivo.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'cobros_route_id' => $route->id]);
        $mine = $this->get(route('cobros.collector.credit-requests.index'));
        $mine->assertOk()->assertSee('Requiere corrección')->assertSee('Falta foto del reverso nítida')
            ->assertSee('Corregir y reenviar')->assertSee('Mis solicitudes')->assertDontSee('Nueva solicitud');

        // Rechazo desde corrección → terminal con motivo en log.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.reject', $id), ['motivo' => 'Datos no coinciden con la identificación'])
            ->assertRedirect(route('cobros.owner.solicitudes.show', $id));
        $row = CobrosCreditRequest::find($id);
        $this->assertSame('rechazada', $row->status);
        $this->assertSame('rechazada', $row->latestLog()->to_status);
        $this->assertSame('Datos no coinciden con la identificación', $row->latestLog()->notes);

        // Terminal: ninguna transición posterior cambia el estado.
        $this->post(route('cobros.owner.solicitudes.verify', $id))->assertSessionHasErrors('transition');
        $this->post(route('cobros.owner.solicitudes.reject', $id), ['motivo' => 'Intento repetido de rechazo'])->assertSessionHasErrors('transition');
        $this->assertSame('rechazada', CobrosCreditRequest::find($id)->status);

        // BLOQUE 8: sin crédito, CxC, pagos ni clientes nuevos.
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_verified_transition_ready_for_block9_and_bureau_events_without_blacklisting_identity(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $request = CobrosCreditRequest::forCompany($company->id)->firstOrFail();
        $identityId = $request->bureau_identity_id;
        $this->assertNotNull($identityId);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.verify', $request->id), ['motivo' => 'Identidad y fotos consistentes'])
            ->assertRedirect(route('cobros.owner.solicitudes.show', $request->id));
        $this->assertSame('verificada', $request->fresh()->status);

        // Evento Buró append-only del rechazo/verificación; identidad intacta.
        $this->assertDatabaseHas('cobros_bureau_events', ['event_type' => 'request_verified', 'bureau_identity_id' => $identityId]);
        $identity = \App\Models\CobrosBureauIdentity::find($identityId);
        $this->assertNotNull($identity);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    public function test_correction_resubmit_reuses_row_keeps_history_and_old_evidence(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $request = CobrosCreditRequest::forCompany($company->id)->firstOrFail();
        $oldFront = $request->id_front_path;
        $oldBack = $request->id_back_path;

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.correct', $request->id), ['motivo' => 'Nombre mal escrito en la solicitud'])->assertRedirect();

        // Mientras esté en corrección, un envío NUEVO del mismo identificador queda bloqueado.
        $this->sendAsCollector($this->payload(), $route, $collector)->assertSessionHasErrors('identification');

        // Reenvío: formulario prellenado con la misma solicitud.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'cobros_route_id' => $route->id]);
        $form = $this->get(route('cobros.collector.credit-requests.create', ['resume' => $request->id]));
        $form->assertOk()->assertSee('Maria Solis')->assertSee('Solicitud de crédito');

        $newPayload = $this->payload(['name' => 'Maria Solis Corregida']);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $newPayload + [
            'resume_id' => $request->id,
            'id_front' => UploadedFile::fake()->image('frente2.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso2.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), $newPayload + [
            'review_token' => $m[1],
            'resume_id' => $request->id,
        ])->assertRedirect(route('cobros.collector.credit-requests.index'));

        // MISMA fila: vuelve a pendiente, historial crece y evidencias previas conservadas.
        $this->assertSame(1, CobrosCreditRequest::forCompany($company->id)->count());
        $request->refresh();
        $this->assertSame('pendiente', $request->status);
        $this->assertSame('Maria Solis Corregida', $request->name);
        $this->assertNotSame($oldFront, $request->id_front_path);
        $this->assertTrue(Storage::disk('local')->exists($oldFront), 'evidencia previa del frente debe conservarse');
        $this->assertTrue(Storage::disk('local')->exists($oldBack), 'evidencia previa del reverso debe conservarse');
        $this->assertTrue(Storage::disk('local')->exists($request->id_front_path));
        $logs = $request->logs()->orderBy('id')->get();
        $this->assertSame(['pendiente', 'requiere_correccion', 'pendiente'], $logs->pluck('to_status')->all());
        $this->assertSame($collector->id, $logs->last()->actor_user_id);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    public function test_rejection_bureaus_event_and_never_creates_financial_records(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $request = CobrosCreditRequest::forCompany($company->id)->firstOrFail();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.reject', $request->id), ['motivo' => 'Historial negativo no verificable'])->assertRedirect();

        $this->assertDatabaseHas('cobros_bureau_events', ['event_type' => 'request_rejected', 'bureau_identity_id' => $request->bureau_identity_id]);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('customers', 0);
        // La identidad Buró no se marcó como "mal cliente": solo hubo eventos.
        $this->assertNotNull(\App\Models\CobrosBureauIdentity::find($request->bureau_identity_id));
    }

    public function test_form_texts_use_solicitud_de_credito_and_keep_mis_solicitudes(): void
    {
        [$company, $collector, $route] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'cobros_route_id' => $route->id]);

        $this->get(route('cobros.collector.credit-requests.create'))
            ->assertOk()->assertSee('Solicitud de crédito')->assertDontSee('Nueva solicitud');

        $this->get(route('cobros.collector.credit-requests.index'))
            ->assertOk()->assertSee('Mis solicitudes')->assertSee('Solicitud de crédito');

        // 9B: la navegación de cartera usa los labels operativos NUEVA SOLICITUD
        // / MIS SOLICITUDES / MIS CLIENTES y ya no incluye "Nuevo cliente".
        $this->get(route('cobros.collector.portfolio'))
            ->assertOk()->assertSee('NUEVA SOLICITUD')->assertSee('MIS SOLICITUDES')->assertSee('MIS CLIENTES')
            ->assertDontSee('Nuevo cliente')->assertDontSee('Nueva solicitud');
    }

    public function test_bell_shows_new_and_resubmitted_requests_with_tenant_isolation_and_marks_read_on_open(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->firstOrFail()->id;

        // Otra empresa: alerta propia que NO debe aparecer en la campana del dueño A.
        session()->forget('active_company_id');
        [$otherCompany, $otherCollector, $otherRoute] = $this->context();
        $this->sendAsCollector($this->payload(['identification' => '776655443', 'name' => 'Ajeno NoVer']), $otherRoute, $otherCollector)->assertRedirect();

        // Badge = 1, texto de nueva solicitud con nombre y localidad, link al detalle.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $openRow = \App\Models\AlertRecipient::query()
            ->where('user_id', $owner->id)
            ->whereHas('alert', fn ($q) => $q->where('entity_type', 'cobros_credit_request')->where('entity_id', $id))
            ->value('id');
        $index = $this->get(route('cobros.owner.solicitudes.index'));
        $index->assertOk()
            ->assertSee('data-bell-badge', false)
            ->assertSee('Nueva solicitud de crédito · Maria Solis · Ruta Norte', false)
            ->assertSee(route('cobros.notifications.open', $openRow), false)
            ->assertDontSee('Ajeno NoVer')->assertDontSee('776655443')
            ->assertSee('Ver solicitudes');

        // Click en la campana (endpoint open) marca SOLO ese recipient como leído
        // (read_at Core, sin borrar historial) y el badge desaparece con 0 pendientes.
        $this->get(route('cobros.notifications.open', $openRow))->assertRedirect(
            url('/cobros/owner/solicitudes/'.$id)
        );
        $this->assertNotNull(
            \App\Models\AlertRecipient::query()
                ->where('user_id', $owner->id)
                ->whereHas('alert', fn ($q) => $q->where('entity_type', 'cobros_credit_request')->where('entity_id', $id))
                ->first()->read_at
        );
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()->assertDontSee('data-bell-badge', false)->assertSee('Sin novedades pendientes');

        // Corrección + reenvío → campana vuelve a notificar con el texto de reenvío.
        $this->post(route('cobros.owner.solicitudes.correct', $id), ['motivo' => 'Revisar datos del solicitante'])->assertRedirect();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertSessionHasErrors('identification');

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $this->payload([
            'name' => 'Maria Solis Corregida',
            'resume_id' => $id,
        ]) + [
            'id_front' => UploadedFile::fake()->image('f2.jpg', 90, 70),
            'id_back' => UploadedFile::fake()->image('b2.jpg', 90, 70),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload([
            'name' => 'Maria Solis Corregida',
            'resume_id' => $id,
        ]) + ['review_token' => $m[1]])->assertRedirect();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()
            ->assertSee('data-bell-badge', false)
            ->assertSee('Solicitud corregida · Maria Solis Corregida · Ruta Norte', false);
    }

    public function test_photos_ver_and_download_are_private_with_safe_names_and_403_for_collector(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->firstOrFail()->id;

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $detail = $this->get(route('cobros.owner.solicitudes.show', $id));
        $detail->assertOk()
            ->assertSee('Ver foto')->assertSee('Descargar')
            ->assertSee('solicitudes/'.$id.'/foto/front?descargar=1', false)
            ->assertSee('solicitudes/'.$id.'/foto/back?descargar=1', false);

        // Ver foto: imagen completa (inline) frente y reverso.
        foreach (['front' => 'frente', 'back' => 'reverso'] as $lado => $nombre) {
            $inline = $this->get(route('cobros.owner.solicitudes.photo', ['id' => $id, 'lado' => $lado]));
            $inline->assertOk()->assertHeader('content-type', 'image/jpeg');
            $this->assertStringNotContainsString('attachment', (string) $inline->headers->get('content-disposition'));

            // Descargar: forzado con nombre seguro/descriptivo.
            $download = $this->get(route('cobros.owner.solicitudes.photo', ['id' => $id, 'lado' => $lado, 'descargar' => 1]));
            $download->assertOk();
            $disposition = (string) $download->headers->get('content-disposition');
            $this->assertStringContainsString('attachment', $disposition);
            $this->assertStringContainsString('solicitud-'.$id.'-'.$nombre.'.jpg', $disposition);
        }

        // Dueño ajeno: 404. Cobrador sin portal_dueno: 403. Sin sesión: redirect login.
        session()->forget('active_company_id');
        [$otherCompany, $otherCollector, $otherRoute, $otherOwner] = $this->context();
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $otherCompany->id]);
        $this->get(route('cobros.owner.solicitudes.photo', ['id' => $id, 'lado' => 'front', 'descargar' => 1]))->assertNotFound();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.owner.solicitudes.photo', ['id' => $id, 'lado' => 'front']))->assertForbidden();
    }

    public function test_collector_bell_receives_verified_correction_and_unauthorized_texts_for_owner_only(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $other = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $route->users()->attach($other->id, ['active' => true]);
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $idVerified = CobrosCreditRequest::forCompany($company->id)->firstOrFail()->id;

        // Solicitud #2 → corrección y después no autorizada (reject acepta corrección).
        $this->sendAsCollector($this->payload([
            'identification' => '203040506',
            'name' => 'Pedro Quesada',
            'address' => 'San Ramon, calle 2',
        ]), $route, $collector)->assertRedirect()->assertSessionHas('success');
        $idCorrection = CobrosCreditRequest::forCompany($company->id)->where('id', '!=', $idVerified)->firstOrFail()->id;

        // Verificada → texto visible para el cobrador DUEÑO de la solicitud.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.verify', $idVerified), ['motivo' => 'Datos confirmados'])->assertRedirect();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.index'))
            ->assertOk()
            ->assertSee('data-bell-badge', false)
            ->assertSee('Solicitud verificada · Maria Solis', false)
            ->assertSee(route('cobros.collector.credit-requests.index'), false);

        // Aislamiento de destinatario: otro cobrador de la misma empresa no lo ve.
        $this->actingAs($other)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.index'))
            ->assertOk()->assertDontSee('data-bell-badge', false)
            ->assertDontSee('Solicitud verificada · Maria Solis', false);

        // Requiere corrección → click va DIRECTO al formulario de corrección.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.correct', $idCorrection), ['motivo' => 'Falta dato del cliente'])->assertRedirect();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.index'))
            ->assertOk()
            ->assertSee('Solicitud requiere corrección · Pedro Quesada', false);
        // Click → endpoint open redirige al formulario de corrección y marca SOLO ese recipient.
        $corrRow = \App\Models\AlertRecipient::query()
            ->where('user_id', $collector->id)
            ->whereHas('alert', fn ($q) => $q->where('entity_type', 'cobros_credit_request')->where('entity_id', $idCorrection))
            ->value('id');
        $this->get(route('cobros.notifications.open', $corrRow))
            ->assertRedirect(url('/cobros/collector/credit-requests/create?resume='.$idCorrection));

        // No autorizada: texto de usuario; el status interno sigue 'rechazada'.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.reject', $idCorrection), ['motivo' => 'Datos inconsistentes'])->assertRedirect();
        $this->assertSame('rechazada', CobrosCreditRequest::find($idCorrection)->status);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.index'))
            ->assertOk()->assertSee('Solicitud no autorizada · Pedro Quesada', false);
    }

    public function test_collector_notification_links_resolve_without_owner_routes_and_badge_is_red(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $other = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $route->users()->attach($other->id, ['active' => true]);

        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->firstOrFail()->id;

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.correct', $id), ['motivo' => 'Falta dato'])->assertRedirect();

        // Link de corrección responde 200 para SU solicitud; link de verificada/no autorizada (index) también.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.create', ['resume' => $id]))->assertOk();
        $this->get(route('cobros.collector.credit-requests.index'))->assertOk();

        // Otro cobrador NO accede a la corrección ajena.
        $this->actingAs($other)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.credit-requests.create', ['resume' => $id]))->assertNotFound();

        // La campana del cobrador jamás navega a rutas owner/ (fuente del 403): pie → Mis solicitudes.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $bell = $this->get(route('cobros.collector.credit-requests.index'));
        $bell->assertOk()
            ->assertDontSee('/cobros/owner/', false)
            ->assertSee(route('cobros.collector.credit-requests.index'), false)
            ->assertSee('Mis solicitudes', false)
            ->assertSee('bg-red-600', false);

        // El dueño conserva su home y sus links.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()
            ->assertSee(route('cobros.owner.solicitudes.index'), false)
            ->assertSee('Ver solicitudes', false);
    }

    public function test_opening_one_notification_marks_only_it_read_shrinks_badge_and_isolates_recipients(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $otherOwner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);

        // Dos solicitudes → dos alertas no leídas para ambos dueños de la empresa.
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $this->sendAsCollector($this->payload(['identification' => '112233445', 'name' => 'Segundo Cliente']), $route, $collector)->assertRedirect();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()->assertSee('data-bell-badge>2<', false);
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()->assertSee('data-bell-badge>2<', false);

        $ids = \App\Models\AlertRecipient::query()->where('user_id', $owner->id)->orderBy('id')->pluck('id');
        $this->assertSame(2, $ids->count());

        // Otro usuario NO puede abrir (ni marcar) la notificación ajena → 404, badge intacto.
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.notifications.open', $ids[0]))->assertNotFound();
        $this->get(route('cobros.owner.solicitudes.index'))->assertSee('data-bell-badge>2<', false);

        // Abrir UNA → solo esa queda leída: badge 2→1.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.notifications.open', $ids[0]))->assertRedirect();
        $this->assertNotNull(\App\Models\AlertRecipient::find($ids[0])->read_at);
        $this->assertNull(\App\Models\AlertRecipient::find($ids[1])->read_at, 'abrir una no debe marcar las demás');
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()->assertSee('data-bell-badge>1<', false);

        // Abrir la segunda → badge llega a 0 y desaparece.
        $this->get(route('cobros.notifications.open', $ids[1]))->assertRedirect();
        $this->get(route('cobros.owner.solicitudes.index'))
            ->assertOk()->assertDontSee('data-bell-badge', false)->assertSee('Sin novedades pendientes');

        // El otro dueño no fue afectado: sigue con sus 2 pendientes.
        $this->actingAs($otherOwner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.solicitudes.index'))->assertSee('data-bell-badge>2<', false);
    }
}
