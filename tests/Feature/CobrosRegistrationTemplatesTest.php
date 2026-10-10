<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Imports\CustomerImportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * BLOQUE 16B-2A — machotes VACÍOS de clientes y créditos descargables desde el
 * onboarding (paso Rutas) sin alta, licencia ni sesión de cobrador. Las
 * descargas son anónimas, no exponen datos de ninguna empresa y no escriben
 * registros operativos (clientes, créditos, CxC, licencias o pagos).
 */
class CobrosRegistrationTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_customer_and_credit_templates_are_publicly_downloadable(): void
    {
        foreach ([
            'cobros.register.templates.customers' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'cobros.register.templates.credits' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ] as $routeName => $mime) {
            $response = $this->get(route($routeName))
                ->assertOk()
                ->assertHeader('content-type', $mime);

            $this->assertStringContainsString(
                'attachment; filename=',
                (string) $response->headers->get('content-disposition'),
            );
        }

        $this->assertGuest();
    }

    public function test_customer_template_keeps_cobros_columns_without_commerce_fields(): void
    {
        $content = $this->get(route('cobros.register.templates.customers'))->streamedContent();
        $path = $this->tempSpreadsheet($content);

        try {
            $sheet = IOFactory::load($path);
            $headers = $sheet->getSheet(0)->rangeToArray('A1:H1', null, false, false)[0];
            $sheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            CustomerImportService::COBROS_HEADERS,
            $headers,
            'El machote debe conservar las columnas CobrOS (el asterisco marca obligatorio).'
        );

        $this->assertNotContains('tipo_cliente', $headers, 'El machote CobrOS no debe exponer campos comerciales de Commerce.');
        $this->assertNotContains('puntos_iniciales', $headers, 'El machote CobrOS no debe exponer campos comerciales de Commerce.');
        $this->assertNotContains('nivel_precio', $headers, 'El machote CobrOS no debe exponer campos comerciales de Commerce.');
    }

    public function test_credit_template_keeps_engine_columns_and_contains_no_customer_data(): void
    {
        $content = $this->get(route('cobros.register.templates.credits'))->streamedContent();
        $path = $this->tempSpreadsheet($content);

        try {
            $sheet = IOFactory::load($path);
            $headers = $sheet->getSheet(0)->rangeToArray('A1:M1', null, false, false)[0];
            $sample = (string) $sheet->getSheet(0)->getCell('C2')->getValue();
            $sheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        $this->assertSame('identificacion_tipo', strtolower((string) $headers[0]));
        $this->assertSame('ruta', strtolower((string) $headers[12]));
        $this->assertSame('Cliente Ejemplo', $sample, 'Solo la fila de ejemplo de la plantilla.');
    }

    public function test_templates_create_no_operative_records_or_licenses(): void
    {
        $this->get(route('cobros.register.templates.customers'))->assertOk();
        $this->get(route('cobros.register.templates.credits'))->assertOk();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('cobros_route_licenses', 0);
        $this->assertGuest();
    }

    public function test_template_links_are_shown_on_routes_step_after_removal_of_prior_note(): void
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Draft Owner',
            'email' => 'templates.owner@test.com',
            'phone' => '8888-0000',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $step3 = $this->get(route('cobros.register.step', ['paso' => 3]));
        $step3->assertOk()
            ->assertSee('Descargar machote de clientes')
            ->assertSee('Descargar machote de créditos')
            ->assertSee(route('cobros.register.templates.customers'), false)
            ->assertSee(route('cobros.register.templates.credits'), false)
            ->assertDontSee('no se habilitan descargas', false);

        $this->assertGuest();
    }

    /** Guarda el contenido de la descarga en un temporal propio con extensión xlsx. */
    private function tempSpreadsheet(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cobros-16b2a-').'.xlsx';
        file_put_contents($path, $content);

        return $path;
    }
}
