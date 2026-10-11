<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosDemoSession;
use App\Models\CobrosPricePlan;
use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * BLOQUE 21D-4C.2B-1 — ingreso al entorno DEMO operativo tras la activación
 * simulada. Reutiliza el Core de autenticación multi-tenant existente. Cubre:
 * acceso tras activación, denegación antes de completar, aislamiento A/B,
 * modalidad correcta, vencimiento y cero pagos/HTTP externos.
 */
class CobrosDemoEntryHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registrar la ruta SOLO para este test (ingreso operativo DEMO) con middleware web para sesión
        Route::post('/demo-cobros/tour/entrar', [\App\Http\Controllers\Cobros\DemoTourController::class, 'enter'])
            ->middleware('web')
            ->name('cobros.demo.tour.enter');

        \Illuminate\Support\Facades\Cache::flush();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response([], 404)]);
        $this->seedPricePlans();
    }

    private function seedPricePlans(): void
    {
        foreach ([
            ['CR', 'administration', '10000.0000'],
            ['CR', 'route', '10000.0000'],
            ['CR', 'collector', '10000.0000'],
        ] as [$iso, $concept, $amount]) {
            CobrosPricePlan::create([
                'country_iso' => $iso, 'concept' => $concept, 'currency' => 'CRC',
                'amount' => $amount, 'is_active' => true,
            ]);
        }
    }

    private function service(): CobrosDemoSessionService
    {
        return app(CobrosDemoSessionService::class);
    }

    private function sessionRow(string $token): CobrosDemoSession
    {
        return CobrosDemoSession::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /** Visitante guiado llevado hasta la activación simulada (pago ficticio). */
    private function guidedActivated(string $modality = 'prestamista'): string
    {
        $this->get('/demo-cobros');
        $this->post('/demo-cobros/modo', ['mode' => 'guiado']);
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'María Demo', 'email' => 'maria.demo@demo-visitante.test']);
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => $modality]);
        $this->post('/demo-cobros/tour/pago');

        return session('cobros.demo_token');
    }

    public function test_ingresa_al_entorno_demo_tras_la_activacion_simulada(): void
    {
        $this->guidedActivated('prestamista');

        $response = $this->post('/demo-cobros/tour/entrar');
        $response->assertRedirect(route('cobros.owner.dashboard'));

        // Sesión operativa autenticada y limitada a la empresa del visitante.
        $this->assertAuthenticated();
        $companyId = session('active_company_id');
        $this->assertNotNull($companyId);
        $this->assertTrue(auth()->user()->companies()->where('companies.id', $companyId)->exists());
    }

    public function test_deniega_entrar_sin_completar_la_activacion(): void
    {
        // Visitante en modo guiado SIN pago (sin activación simulada).
        $this->get('/demo-cobros');
        $this->post('/demo-cobros/modo', ['mode' => 'guiado']);
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'Ana Demo', 'email' => 'ana.demo@demo-visitante.test']);
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => 'prestamista']);

        // No se puede entrar sin pago simulado: se redirige a la cotización.
        $this->post('/demo-cobros/tour/entrar')->assertRedirect(route('cobros.demo.tour.step', ['step' => 'cotizacion']));
        $this->assertGuest();
    }

    public function test_deniega_entrar_con_sesion_vencida(): void
    {
        $this->guidedActivated('prestamista');
        $this->travel($this->service()->ttlMinutes() + 1)->minutes();

        $this->post('/demo-cobros/tour/entrar')->assertRedirect(route('cobros.demo.entry'));
        $this->assertGuest();

        $this->travelBack();
    }

    public function test_visitantes_a_y_b_aislados_no_comparten_entorno(): void
    {
        $tokenA = $this->guidedActivated('cobrador_independiente');
        $companyA = $this->sessionRow($tokenA)->company_id;

        // A entra y queda autenticado en SU empresa.
        $this->post('/demo-cobros/tour/entrar')->assertRedirect(route('cobros.collector.index'));
        $this->assertSame($companyA, session('active_company_id'));

        // B:另一 sesión,另一 empresa. Simula otro visitante (nueva sesión HTTP).
        $this->app['auth']->logout();
        $this->flushSession();
        $tokenB = $this->guidedActivated('prestamista');
        $companyB = $this->sessionRow($tokenB)->company_id;

        $this->assertNotSame($companyA, $companyB);
        $this->post('/demo-cobros/tour/entrar')->assertRedirect(route('cobros.owner.dashboard'));
        $this->assertSame($companyB, session('active_company_id'));
        // A no fue tocada: su empresa sigue asociada a su propia sesión.
        $this->assertSame($companyA, $this->sessionRow($tokenA)->company_id);
    }

    public function test_dirige_a_la_pantalla_segun_la_modalidad(): void
    {
        // Cobrador => pantalla de cobro.
        $this->guidedActivated('cobrador_independiente');
        $this->post('/demo-cobros/tour/entrar')->assertRedirect(route('cobros.collector.index'));
        $this->app['auth']->logout();
        $this->flushSession();

        // Prestamista => portal del dueño.
        $this->guidedActivated('prestamista');
        $this->post('/demo-cobros/tour/entrar')->assertRedirect(route('cobros.owner.dashboard'));
    }

    public function test_cero_pagos_licencias_nuevas_ni_http_externo(): void
    {
        $this->guidedActivated('prestamista');

        // 21D-4C.2B-1K: baseline DESPUÉS del provisionamiento DEMO (que crea la
        // licencia trial de la empresa-demo) y ANTES de enter. La garantía es
        // que el INGRESO operativo no crea pagos, licencias ni contratos nuevos.
        $contractsBefore = CobrosBillingContract::query()->count();
        $licensesBefore = \App\Models\CompanyLicense::query()->count();
        $paymentsBefore = \App\Models\BillingPaymentRecord::query()->count();

        $this->post('/demo-cobros/tour/entrar')->assertRedirect(route('cobros.owner.dashboard'));

        $this->assertSame($paymentsBefore, \App\Models\BillingPaymentRecord::query()->count());
        $this->assertDatabaseCount('cobros_payment_confirmations', 0);
        $this->assertSame($contractsBefore, CobrosBillingContract::query()->count());
        $this->assertSame($licensesBefore, \App\Models\CompanyLicense::query()->count());
        Http::assertNothingSent();
    }
}
