<?php

namespace Tests\Feature;

use App\Http\Controllers\Cobros\DemoVisitorController;
use App\Models\CobrosDemoSession;
use App\Models\CobrosPricePlan;
use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BLOQUE 21D-4C.2A — pago y activación SIMULADOS en el recorrido guiado:
 * cotización con tarifas vigentes, confirmación idempotente solo en el estado
 * de la sesión, aislamiento A/B, vencimiento, salto de pasos denegado y cero
 * servicios externos (Stripe, SINPE, webhooks, contratos, licencias).
 */
class CobrosDemoPaymentTourHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response([], 404)]);
        $this->seedPricePlans();
    }

    /** Tarifas estándar CR (misma estructura que el Panel Maestro). */
    private function seedPricePlans(): void
    {
        foreach ([
            ['CR', 'administration', '10000.0000'],
            ['CR', 'route', '10000.0000'],
            ['CR', 'collector', '10000.0000'],
        ] as [$iso, $concept, $amount]) {
            CobrosPricePlan::create([
                'country_iso' => $iso,
                'concept' => $concept,
                'currency' => 'CRC',
                'amount' => $amount,
                'is_active' => true,
            ]);
        }
    }

    private function service(): CobrosDemoSessionService
    {
        return app(CobrosDemoSessionService::class);
    }

    /** Visitante guiado con recorrido completo hasta modalidad (prestamista CR). */
    private function guidedToSummary(): string
    {
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $this->post('/demo-cobros/modo', ['mode' => 'guiado']);
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'María Demo', 'email' => 'maria.demo@demo-visitante.test']);
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => 'prestamista']);

        return session(DemoVisitorController::SESSION_TOKEN_KEY);
    }

    private function sessionRow(string $token): CobrosDemoSession
    {
        return CobrosDemoSession::query()->where('token_hash', hash('sha256', $token))->first();
    }

    public function test_cotizacion_usa_tarifas_vigentes_y_advierte_sin_cobro(): void
    {
        $this->guidedToSummary();

        $response = $this->get('/demo-cobros/tour/cotizacion');
        $response->assertOk();
        $response->assertSee('Cotización DEMO');
        $response->assertSee('10.000,00'); // tarifa administration CR real (10000)
        $response->assertSee('Pago de demostración — no se cobrará dinero');
    }

    public function test_confirmar_pago_simulado_registra_solo_en_la_sesion(): void
    {
        $token = $this->guidedToSummary();

        // La licencia/contrato del espacio DEMO aislado ya existe por el
        // provisionado (infraestructura del visitante); el pago simulado NO
        // debe crear ni modificar ninguno.
        $licensesBefore = \App\Models\CompanyLicense::query()->count();
        $contractsBefore = \App\Models\CobrosBillingContract::query()->count();

        $this->post('/demo-cobros/tour/pago')->assertRedirect(route('cobros.demo.tour.step', ['step' => 'activacion']));

        $row = $this->sessionRow($token);
        $this->assertSame('activacion', $row->tour_step);
        $this->assertTrue($row->demo_payment['simulated']);
        $this->assertSame('prestamista', $row->demo_payment['modality']);
        $this->assertNotEmpty($row->demo_payment['operation_id']);

        // Cero efectos reales: nada nuevo en tablas productivas ni licencias/contratos.
        $this->assertDatabaseCount('billing_payment_records', 0);
        $this->assertDatabaseCount('cobros_payment_confirmations', 0);
        $this->assertSame($contractsBefore, \App\Models\CobrosBillingContract::query()->count());
        $this->assertSame($licensesBefore, \App\Models\CompanyLicense::query()->count());
        Http::assertNothingSent();
    }

    public function test_confirmacion_repetida_es_idempotente(): void
    {
        $token = $this->guidedToSummary();
        $this->post('/demo-cobros/tour/pago');
        $first = $this->sessionRow($token)->demo_payment;

        // Repetir el confirmar: no duplica ni cambia la operación ficticia.
        $this->post('/demo-cobros/tour/pago')->assertRedirect(route('cobros.demo.tour.step', ['step' => 'activacion']));
        $second = $this->sessionRow($token)->demo_payment;

        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertSame($first['confirmed_at'], $second['confirmed_at']);
        $this->assertSame(1, CobrosDemoSession::query()->count()); // una sola sesión, sin duplicados
    }

    public function test_pago_de_un_visitante_no_toca_al_otro(): void
    {
        $tokenA = $this->guidedToSummary();
        $this->post('/demo-cobros/tour/pago');
        $payA = $this->sessionRow($tokenA)->demo_payment;

        $this->flushSession();
        $tokenB = $this->guidedToSummary();
        $rowB = $this->sessionRow($tokenB);

        $this->assertNull($rowB->demo_payment);
        $this->assertNotSame($this->sessionRow($tokenA)->id, $rowB->id);
        // A intacto.
        $this->assertSame($payA['operation_id'], $this->sessionRow($tokenA)->demo_payment['operation_id']);
    }

    public function test_no_se_puede_pagar_sin_haber_elegido_modalidad(): void
    {
        // Visitante nuevo en modo guiado SIN respuestas de registro.
        $this->get('/demo-cobros');
        $this->post('/demo-cobros/modo', ['mode' => 'guiado']);

        // Saltar directo al pago: denegado (sin country/modality).
        $this->post('/demo-cobros/tour/pago')->assertRedirect(route('cobros.demo.tour'));
        // Saltar a cotización: denegado.
        $this->get('/demo-cobros/tour/cotizacion')->assertRedirect(route('cobros.demo.tour'));

        $this->assertNull($this->sessionRow(session(DemoVisitorController::SESSION_TOKEN_KEY))->demo_payment);
    }

    public function test_sesion_vencida_no_puede_pagar(): void
    {
        $this->guidedToSummary();
        $this->travel($this->service()->ttlMinutes() + 1)->minutes();

        $this->post('/demo-cobros/tour/pago')->assertRedirect(route('cobros.demo.entry'));
        $this->get('/demo-cobros/tour/cotizacion')->assertRedirect(route('cobros.demo.entry'));

        $this->travelBack();
    }

    public function test_activacion_es_simulada_y_muestra_siguiente_paso(): void
    {
        $this->guidedToSummary();
        $contractsBefore = \App\Models\CobrosBillingContract::query()->count();
        $this->post('/demo-cobros/tour/pago');

        $response = $this->get('/demo-cobros/tour/activacion');
        $response->assertOk();
        $response->assertSee('Activación simulada');
        $response->assertSee('Simulado — sin cobro real');
        // INTEGRACIÓN-05: el DEMO YA permite acceso operativo simulado
        // (habilitación explícita), por lo que la pantalla de activación
        // muestra el botón «Entrar al sistema DEMO», no el aviso de fase
        // posterior. Sin pagos reales ni contratos nuevos.
        $response->assertSee('Entrar al sistema DEMO');

        // Ningún servicio real invocado; ningún contrato nuevo.
        Http::assertNothingSent();
        $this->assertSame($contractsBefore, \App\Models\CobrosBillingContract::query()->count());
    }

    public function test_reiniciar_limpia_tambien_el_pago_ficticio(): void
    {
        $token = $this->guidedToSummary();
        $this->post('/demo-cobros/tour/pago');
        $this->assertNotNull($this->sessionRow($token)->demo_payment);

        $this->post('/demo-cobros/tour/reiniciar')->assertRedirect(route('cobros.demo.tour'));
        $row = $this->sessionRow($token);
        $this->assertSame('bienvenida', $row->tour_step);
        $this->assertNull($row->demo_payment);
        // La empresa del visitante sigue intacta.
        $this->assertNotNull($this->service()->resolve($token)->company);
    }
}
