<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * BLOQUE 16B-2B f1 — carga PRIVADA de cartera durante el onboarding.
 * Archivos de clientes/créditos guardados en almacenamiento privado y
 * vinculados al borrador + UUID de ruta, sin ninguna escritura operativa
 * (clientes, créditos, CxC, rutas, cuentas, licencias): solo borradores
 * «Pendiente de activación» con reemplazo/eliminación de archivos propios.
 */
class CobrosRegistrationUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Aislamiento: cada prueba trabaja sobre un disco local privado efímero,
        // sin archivos residuales de otras pruebas ni de ejecuciones previas.
        \Illuminate\Support\Facades\Storage::fake('local');
    }

    private const CUSTOMERS_HEADERS = ['tipo_identificacion', 'identificacion', 'nombre', 'telefono', 'direccion', 'ruta', 'latitud', 'longitud'];

    private const CREDITS_HEADERS = ['identificacion_tipo', 'identificacion', 'nombre', 'telefono', 'monto_original', 'saldo_pendiente', 'cuota', 'frecuencia', 'fecha_primer_vencimiento', 'referencia_externa', 'comportamiento', 'notas', 'ruta'];

    private function reachRoutesStep(string $email = 'uploads.owner@test.com'): string
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Draft Owner',
            'email' => $email,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), ['name' => 'Ruta Cargas'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        return session('cobros.registration')['routes'][0]['id'];
    }

    private function xlsx(array $headers, array $rows = []): \Illuminate\Http\UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([$headers], null, 'A1');
        if ($rows !== []) {
            $sheet->getActiveSheet()->fromArray($rows, null, 'A2');
        }
        $path = tempnam(sys_get_temp_dir(), 'cobros-16b2b-upload-').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        return \Illuminate\Http\UploadedFile::fake()->createWithContent('cartera.xlsx', (string) file_get_contents($path));
    }

    public function test_upload_is_bound_to_draft_and_route_with_pending_status(): void
    {
        $routeId = $this->reachRoutesStep();

        $file = $this->xlsx(self::CUSTOMERS_HEADERS, [
            ['01', '201234567', 'Cliente Carga', '88880000', 'San José', '', '', ''],
        ]);

        $this->post(route('cobros.register.uploads.customers'), [
            'route_id' => $routeId,
            'upload_file' => $file,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]))
            ->assertSessionHas('upload_status', fn (string $status) => str_contains($status, 'Pendiente de activación'));

        $uploads = session('cobros.registration')['uploads'];
        $this->assertCount(1, $uploads);
        $this->assertSame('customers', $uploads[0]['kind']);
        $this->assertSame($routeId, $uploads[0]['route_id']);
        $this->assertSame(1, $uploads[0]['rows']);
        $this->assertSame('Pendiente de activación', $uploads[0]['status']);
        $this->assertSame('cartera.xlsx', $uploads[0]['original_name']);
        $this->assertStringStartsWith('registration-uploads/', $uploads[0]['path']);

        $draft = \App\Models\CobrosRegistrationDraft::where('email', 'uploads.owner@test.com')->firstOrFail();
        $this->assertCount(1, $draft->payload['uploads'], 'Los metadatos persisten en el borrador.');

        // Nada operativo se creó: sin rutas, clientes ni carteras reales.
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertGuest();
    }

    public function test_replace_and_delete_only_own_file(): void
    {
        $routeId = $this->reachRoutesStep();

        $this->post(route('cobros.register.uploads.credits'), [
            'route_id' => $routeId,
            'upload_file' => $this->xlsx(self::CREDITS_HEADERS, [
                ['cedula', '201234569', 'Cliente Creditos', '88880001', '50000', '35000', '3500', 'quincenal', '2026-11-05', 'EXT-1', 'bueno', '', ''],
            ]),
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $first = session('cobros.registration')['uploads'][0]['path'];
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($first);

        // Reemplazo: mismo kind+ruta, el archivo anterior debe desaparecer.
        $this->post(route('cobros.register.uploads.replace', ['kind' => 'credits']), [
            'route_id' => $routeId,
            'upload_file' => $this->xlsx(self::CREDITS_HEADERS, [
                ['cedula', '201234570', 'Cliente Creditos 2', '88880002', '60000', '40000', '4000', 'mensual', '2026-12-01', 'EXT-2', 'regular', '', ''],
            ]),
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $uploads = session('cobros.registration')['uploads'];
        $this->assertCount(1, $uploads, 'El reemplazo no duplica entradas.');
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($first);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($uploads[0]['path']);

        // Eliminación propia.
        $this->post(route('cobros.register.uploads.delete', ['kind' => 'credits']), ['route_id' => $routeId])
            ->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->assertSame([], session('cobros.registration')['uploads']);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($uploads[0]['path']);

        // Los datos del archivo no generan registros de cartera.
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('cobros_initial_portfolio_records', 0);
    }

    public function test_upload_validates_row_limit_mime_and_route_ownership(): void
    {
        $routeId = $this->reachRoutesStep();

        // Ruta ajena al borrador: rechazada; del borrador no puede cargarla otra sesión.
        $this->post(route('cobros.register.uploads.customers'), [
            'route_id' => 'ruta-que-no-existe',
            'upload_file' => $this->xlsx(self::CUSTOMERS_HEADERS),
        ])->assertSessionHasErrors('route_id');

        // Archivo ilegible / mime no permitido.
        $this->post(route('cobros.register.uploads.customers'), [
            'route_id' => $routeId,
            'upload_file' => \Illuminate\Http\UploadedFile::fake()->create('cartera.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('upload_file');

        // Ashora: límite de filas (501 filas de datos) reutilizado del motor.
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['01', '2'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'Cliente ' . $i, '8888000' . $i % 10, '', '', '', ''];
        }
        $this->post(route('cobros.register.uploads.customers'), [
            'route_id' => $routeId,
            'upload_file' => $this->xlsx(self::CUSTOMERS_HEADERS, $rows),
        ])->assertSessionHasErrors('upload_file');

        // Nada se guardó.
        $this->assertSame([], session('cobros.registration')['uploads'] ?? []);
        $directory = 'registration-uploads';
        $this->assertTrue(
            \Illuminate\Support\Facades\Storage::disk('local')->allFiles($directory) === [],
            'Solo se almacenan cargas válidas.'
        );
    }

    public function test_uploads_survive_session_loss_via_recovery_token(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $routeId = $this->reachRoutesStep();

        $this->post(route('cobros.register.uploads.customers'), [
            'route_id' => $routeId,
            'upload_file' => $this->xlsx(self::CUSTOMERS_HEADERS, [
                ['01', '201234571', 'Cliente Persistente', '88880003', '', '', '', ''],
            ]),
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->flushSession();
        $this->assertNull(session('cobros.registration'));

        $this->post(route('cobros.register.recover.store'), ['email' => 'uploads.owner@test.com'])
            ->assertRedirect(route('cobros.register.recover'));

        $token = null;
        \Illuminate\Support\Facades\Notification::assertSentOnDemand(\App\Notifications\RegistrationDraftRecovery::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->get(route('cobros.register.recover.token', ['token' => $token]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $step3 = $this->get(route('cobros.register.step', ['paso' => 3]));
        $step3->assertOk()
            ->assertSee('Ruta Cargas')
            ->assertSee('cartera.xlsx')
            ->assertSee('Pendiente de activación');

        $restored = \App\Models\CobrosRegistrationDraft::where('email', 'uploads.owner@test.com')->firstOrFail()->payload['uploads'];
        $this->assertCount(1, $restored, 'La recuperación restaura las cargas del borrador.');
        $this->assertGuest();
    }
}
