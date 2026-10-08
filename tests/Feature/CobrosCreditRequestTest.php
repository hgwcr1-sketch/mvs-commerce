<?php

namespace Tests\Feature;

use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** BLOQUE 7A — nueva solicitud de crédito del cobrador (con evidencias). */
class CobrosCreditRequestTest extends TestCase
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
        $company = Company::create(['trade_name' => 'Creditos '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

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
        $this->assertSame(2, substr_count($review->getContent(), 'Adjunta ✓'), 'la revisión debe indicar ambas fotos');

        if (! preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $matches)) {
            $this->fail('La revisión no expuso el token de revisión.');
        }

        return $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $matches[1]]);
    }

    public function test_happy_path_sends_request_with_both_photos_and_log_without_financial_side_effects(): void
    {
        [$company, $collector, $route] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        $this->get(route('cobros.collector.credit-requests.create'))->assertOk()->assertSee('CLIENTE NUEVO')->assertSee('CLIENTE EXISTENTE');

        $this->sendFlow($this->payload())
            ->assertRedirect(route('cobros.collector.credit-requests.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('cobros_credit_requests', 1);
        $this->assertDatabaseCount('cobros_credit_request_logs', 1);
        $row = CobrosCreditRequest::forCompany($company->id)->first();
        $this->assertSame(CobrosCreditRequest::STATUS_PENDING, $row->status);
        $this->assertSame('nuevo', $row->applicant_type);
        $this->assertNull($row->customer_id);
        $this->assertNotNull($row->id_front_path);
        $this->assertNotNull($row->id_back_path);
        $this->assertNotSame($row->id_front_path, $row->id_back_path);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($row->id_front_path));
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($row->id_back_path));
        $this->assertSame('1500.5000', $row->amount_requested);

        // G: nada financiero ni de personas.
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('cobros_assignments', 0);

        $this->get(route('cobros.collector.credit-requests.index'))->assertOk()
            ->assertSee('Maria Solis')->assertSee('Pendiente de revisión');
    }

    public function test_new_applicant_name_lookup_endpoint_supports_autocomplete_flow(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        \Illuminate\Support\Facades\Http::fake(['api.hacienda.go.cr/*' => \Illuminate\Support\Facades\Http::response([], 404)]);

        // Lookup no encontrado / sin proveedor: nunca bloquea, el nombre es manual
        // y la solicitud igual se envía (flujo manual cubierto por happy path).
        $this->getJson(route('cobros.customers.identification-name', [
            'identification_type' => 'cedula',
            'identification' => '102030405',
        ]))->assertOk()->assertJsonStructure(['supported', 'found', 'name']);

        // Lookup "encontrado": el formulario rellena nombre y envía normal.
        $this->sendFlow($this->payload(['name' => 'Nombre Autocompletado']))
            ->assertRedirect();
        $this->assertSame('Nombre Autocompletado', CobrosCreditRequest::forCompany($company->id)->first()->name);
    }

    public function test_existing_customer_links_customer_id_without_duplicating(): void
    {
        [$company, $collector] = $this->context();
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente Existente',
            'customer_type' => 'individual',
            'identification' => '102030405',
            'phone' => '88888888',
            'is_active' => true,
        ]);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        $this->get(route('cobros.collector.credit-requests.customers').'?q=Existente')
            ->assertOk()->assertJsonFragment(['name' => 'Cliente Existente']);

        $this->sendFlow($this->payload([
            'applicant_type' => 'existente',
            'customer_id' => $customer->id,
        ]))->assertRedirect();

        $this->assertDatabaseCount('customers', 1);
        $row = CobrosCreditRequest::forCompany($company->id)->first();
        $this->assertSame($customer->id, $row->customer_id);
        $this->assertSame('existente', $row->applicant_type);
        $this->assertSame('Cliente Existente', $row->name);
    }

    public function test_existing_customer_from_other_tenant_is_rejected(): void
    {
        [$company, $collector] = $this->context();
        session()->forget('active_company_id');
        [$foreignCompany] = $this->context();
        $foreignCustomer = Customer::create([
            'company_id' => $foreignCompany->id,
            'name' => 'Ajeno',
            'customer_type' => 'individual',
            'identification' => '555666',
            'is_active' => true,
        ]);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        $this->post(route('cobros.collector.credit-requests.review'), $this->payload([
            'applicant_type' => 'existente',
            'customer_id' => $foreignCustomer->id,
        ]) + $this->photos())->assertSessionHasErrors('customer_id');

        // El typeahead jamás devuelve clientes de otro tenant.
        $this->get(route('cobros.collector.credit-requests.customers').'?q=Ajeno')
            ->assertOk()->assertJsonMissing(['id' => $foreignCustomer->id]);

        $this->assertDatabaseCount('cobros_credit_requests', 0);
    }

    public function test_front_and_back_photos_are_required_and_invalid_files_rejected(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        // Reverso faltante.
        $this->post(route('cobros.collector.credit-requests.review'),
            $this->payload() + ['id_front' => UploadedFile::fake()->image('f.jpg')])
            ->assertSessionHasErrors('id_back');

        // Frente faltante.
        $this->post(route('cobros.collector.credit-requests.review'),
            $this->payload() + ['id_back' => UploadedFile::fake()->image('b.jpg')])
            ->assertSessionHasErrors('id_front');

        // Archivo inválido (no imagen).
        $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => UploadedFile::fake()->create('archivo.pdf', 100, 'application/pdf'),
            'id_back' => UploadedFile::fake()->image('b.jpg'),
        ])->assertSessionHasErrors('id_front');

        // Sin revisión no hay envío: se exige el token con sus fotos.
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload())
            ->assertSessionHasErrors('review_token');

        $this->assertDatabaseCount('cobros_credit_requests', 0);
    }

    public function test_validation_rejects_zero_and_invalid_amount_and_requires_fields(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        $this->from(route('cobros.collector.credit-requests.create'))
            ->post(route('cobros.collector.credit-requests.review'), $this->payload(['amount_requested' => '0']) + $this->photos())
            ->assertSessionHasErrors('amount_requested');

        $this->from(route('cobros.collector.credit-requests.create'))
            ->post(route('cobros.collector.credit-requests.review'), $this->payload(['amount_requested' => '10.12345']) + $this->photos())
            ->assertSessionHasErrors('amount_requested');

        $this->from(route('cobros.collector.credit-requests.create'))
            ->post(route('cobros.collector.credit-requests.review'), ['identification' => '123'])
            ->assertSessionHasErrors(['name', 'amount_requested', 'id_front', 'id_back']);

        $this->assertDatabaseCount('cobros_credit_requests', 0);
    }

    public function test_open_duplicate_same_company_identification_is_blocked_but_closed_history_allows_new(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        $this->sendFlow($this->payload())->assertRedirect();
        $this->assertDatabaseCount('cobros_credit_requests', 1);

        $this->sendFlow($this->payload(['identification' => '102030405', 'name' => 'Otra Persona']))
            ->assertSessionHasErrors('identification');
        $this->assertDatabaseCount('cobros_credit_requests', 1);

        CobrosCreditRequest::query()->first()->update(['status' => CobrosCreditRequest::STATUS_REJECTED]);
        $this->sendFlow($this->payload(['name' => 'Nueva Despues']))->assertRedirect();
        $this->assertDatabaseCount('cobros_credit_requests', 2);
    }

    public function test_gps_optional_and_no_financial_records_created(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        $this->sendFlow($this->payload())->assertRedirect();
        $row = CobrosCreditRequest::forCompany($company->id)->first();
        $this->assertNull($row->latitude);
        $this->assertNull($row->longitude);

        $this->sendFlow($this->payload([
            'identification' => '99887766',
            'latitude' => '9.90000000',
            'longitude' => '-84.10000000',
        ]))->assertRedirect();
        $with = CobrosCreditRequest::forCompany($company->id)->where('identification', '99887766')->first();
        $this->assertSame('9.90000000', (string) $with->latitude);
        $this->assertSame('-84.10000000', (string) $with->longitude);

        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_idor_other_collector_and_other_tenant_never_see_or_create_in_foreign_context(): void
    {
        [$company, $collector, $route] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->sendFlow($this->payload(['name' => 'Solicitud Propia']))->assertRedirect();

        $other = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $otherRoute = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Sur', 'active' => true]);
        $otherRoute->users()->attach($other->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($otherRoute, $company,
            User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), now()->addMonth());

        $this->actingAs($other)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.collector.credit-requests.index'))->assertOk()
            ->assertDontSee('Solicitud Propia')->assertDontSee('102030405');

        session()->forget('active_company_id');
        [$foreignCompany, $foreignCollector] = $this->context();
        $this->actingAs($foreignCollector)->withSession(['active_company_id' => $foreignCompany->id]);
        $this->get(route('cobros.collector.credit-requests.index'))->assertOk()->assertDontSee('Solicitud Propia');
        $this->sendFlow($this->payload())->assertRedirect();

        session()->forget('active_company_id');
        $this->assertSame(1, CobrosCreditRequest::forCompany($company->id)->count());
        $this->assertSame(1, CobrosCreditRequest::forCompany($foreignCompany->id)->count());
    }

    public function test_sent_request_has_no_edit_endpoint(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->sendFlow($this->payload())->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->first()->id;

        $this->patch('/cobros/collector/credit-requests/'.$id)->assertStatus(404);
        $this->put('/cobros/collector/credit-requests/'.$id)->assertStatus(404);

        foreach (Route::getRoutes() as $routeItem) {
            if (! str_contains($routeItem->uri(), 'cobros/collector/credit-requests')) {
                continue;
            }
            $this->assertNotContains('PATCH', $routeItem->methods());
            $this->assertNotContains('PUT', $routeItem->methods());
        }
    }

    public function test_without_active_licensed_route_no_request_is_created(): void
    {
        [$company] = $this->context();
        $bare = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($bare)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload() + ['review_token' => 'abc123'])
            ->assertSessionHasErrors('cobros_route_id');

        $this->assertDatabaseCount('cobros_credit_requests', 0);
    }

    /** Imagen REAL (GD) en el formato dado: jpg, png o webp. */
    private function realImage(string $ext): UploadedFile
    {
        $img = imagecreatetruecolor(60, 40);
        $path = tempnam(sys_get_temp_dir(), 'mvsimg').'.'.$ext;
        if ($ext === 'jpg') {
            imagejpeg($img, $path);
        } elseif ($ext === 'png') {
            imagepng($img, $path);
        } else {
            imagewebp($img, $path);
        }
        imagedestroy($img);

        return new UploadedFile($path, 'foto.'.$ext, $ext === 'jpg' ? 'image/jpeg' : 'image/'.$ext, null, true);
    }

    public function test_create_form_declares_multipart_so_photos_reach_php_as_files(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        // Sin enctype="multipart/form-data" el navegador envía id_front/id_back
        // como STRINGS: required pasa, image/mimes fallan ("debe ser una imagen")
        // y las fotos reales válidas quedan rechazadas en nuevo y existente.
        $this->get(route('cobros.collector.credit-requests.create'))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_review_accepts_real_jpeg_png_webp_from_camera_or_gallery(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        // JPG real + PNG real (galería/cámara/WhatsApp) => aceptados.
        $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => $this->realImage('jpg'),
            'id_back' => $this->realImage('png'),
        ])->assertOk();

        // WEBP real => aceptado.
        $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => $this->realImage('webp'),
            'id_back' => $this->realImage('jpg'),
        ])->assertOk();

        // Extensión .jpg con contenido que NO es imagen => sigue rechazado.
        $fake = UploadedFile::fake()->create('falso.jpg', 20, 'text/plain');
        $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => $fake,
            'id_back' => $this->realImage('jpg'),
        ])->assertSessionHasErrors('id_front');
    }

    public function test_upload_error_beyond_php_limit_reports_clear_server_limit_message(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);

        // Una foto real recortada por upload_max_filesize de PHP llega con
        // UPLOAD_ERR_INI_SIZE: debe decir el límite del servidor, no "no es imagen".
        $tmp = tempnam(sys_get_temp_dir(), 'mvsbig');
        file_put_contents($tmp, str_repeat('x', 4096));
        $big = new UploadedFile($tmp, 'grande.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->post(route('cobros.collector.credit-requests.review'), $this->payload() + [
            'id_front' => $big,
            'id_back' => $this->realImage('jpg'),
        ])->assertSessionHasErrors('id_front');

        $errors = session('errors')->get('id_front');
        $this->assertStringContainsString('límite de subida del servidor', (string) $errors[0]);
    }

    public function test_correction_prefills_all_fields_shows_motive_and_keeps_both_photos_when_none_reuploaded(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->sendFlow($this->payload())->assertRedirect();

        $original = CobrosCreditRequest::forCompany($company->id)->firstOrFail();
        $id = $original->id;
        $front = $original->id_front_path;
        $back = $original->id_back_path;

        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);
        app(\App\Services\Cobros\CobrosCreditRequestService::class)->requestCorrection(
            $company->fresh(),
            CobrosCreditRequest::forCompany($company->id)->findOrFail($id),
            $owner,
            'Confirmar dirección exacta'
        );

        // Formulario de corrección: banner con motivo + TODOS los campos
        // precargados y editables + fotos existentes marcadas como conservables.
        $form = $this->get(route('cobros.collector.credit-requests.create', ['resume' => $id]));
        $form->assertOk()
            ->assertSee('Corrección solicitada: Confirmar dirección exacta', false)
            ->assertSee('Foto actual ✓', false)
            ->assertSee('Reemplazar foto (opcional)', false)
            ->assertSee('value="102030405"', false)
            ->assertSee('value="Maria Solis"', false)
            ->assertSee('value="88888888"', false)
            ->assertSee('value="San Ramon, calle 1"', false)
            ->assertSee('value="1500.5000"', false)
            ->assertSee('Primera solicitud', false);

        // Reenvío SIN seleccionar ninguna foto → se conservan exactas.
        $review = $this->post(route('cobros.collector.credit-requests.review'), $this->payload(['resume_id' => $id]));
        $review->assertOk();
        $this->assertStringNotContainsString('Adjunta ✓', $review->getContent());
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload(['resume_id' => $id]) + [
            'review_token' => $m[1],
        ])->assertRedirect()->assertSessionHas('success');

        $row = CobrosCreditRequest::find($id);
        $this->assertSame(1, CobrosCreditRequest::forCompany($company->id)->count(), 'misma fila, nunca solicitud nueva');
        $this->assertSame(CobrosCreditRequest::STATUS_PENDING, $row->status);
        $this->assertSame($front, $row->id_front_path, 'frente conservado exactamente');
        $this->assertSame($back, $row->id_back_path, 'reverso conservado exactamente');
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($front));
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($back));
        $this->assertSame('1500.5000', $row->amount_requested);
        $this->assertSame(3, $row->logs()->count(), 'creación + corrección + reenvío (append-only)');
        $this->assertTrue($row->logs()->where('from_status', 'requiere_correccion')->where('to_status', 'pendiente')->exists());
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_correction_replacing_only_front_replaces_front_and_keeps_back_exactly(): void
    {
        [$company, $collector] = $this->context();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id]);
        $this->sendFlow($this->payload())->assertRedirect();

        $original = CobrosCreditRequest::forCompany($company->id)->firstOrFail();
        $id = $original->id;
        $front = $original->id_front_path;
        $back = $original->id_back_path;

        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);
        app(\App\Services\Cobros\CobrosCreditRequestService::class)->requestCorrection(
            $company->fresh(),
            CobrosCreditRequest::forCompany($company->id)->findOrFail($id),
            $owner,
            'Foto ilegible'
        );

        // Solo reemplaza el FRENTE; el reverso no se sube.
        $review = $this->post(route('cobros.collector.credit-requests.review'), $this->payload([
            'resume_id' => $id,
            'name' => 'Maria Solis Editada',
        ]) + ['id_front' => UploadedFile::fake()->image('frente_nuevo.jpg', 100, 60)]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), $this->payload([
            'resume_id' => $id,
            'name' => 'Maria Solis Editada',
        ]) + ['review_token' => $m[1]])->assertRedirect();

        $row = CobrosCreditRequest::find($id);
        $this->assertNotSame($front, $row->id_front_path, 'frente reemplazado por el nuevo');
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($row->id_front_path));
        $this->assertSame($back, $row->id_back_path, 'reverso intacto');
        $this->assertSame('Maria Solis Editada', $row->name, 'solo cambia lo que el cobrador edita');
        // La evidencia física anterior del frente NO se destruye.
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($front));
        $this->assertSame(CobrosCreditRequest::STATUS_PENDING, $row->status);
        $this->assertSame(3, $row->logs()->count());
    }
}
