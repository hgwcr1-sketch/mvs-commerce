<?php

namespace Tests\Feature;

use App\Http\Controllers\Cobros\DemoVisitorController;
use App\Models\CobrosDemoSession;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BLOQUE 21D-4B — entrada pública del DEMO individual: iniciar, retomar,
 * dos visitantes independientes, reingreso, autorización SOLO en servidor,
 * vencimiento y cero pagos/activaciones externos.
 */
class CobrosDemoVisitorHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response([], 404)]);
    }

    public function test_visitante_nuevo_inicia_demo_y_ve_las_dos_opciones(): void
    {
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));

        $token = session(DemoVisitorController::SESSION_TOKEN_KEY);
        $this->assertNotEmpty($token);

        $response = $this->get('/demo-cobros/inicio');
        $response->assertOk();
        $response->assertSee('Recorrido guiado');
        $response->assertSee('Exploración libre');
    }

    public function test_recargar_conserva_la_sesion_del_mismo_visitante(): void
    {
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $first = session(DemoVisitorController::SESSION_TOKEN_KEY);
        $firstSession = app(\App\Services\Cobros\CobrosDemoSessionService::class)->resolve($first);

        // Recarga: misma sesión, misma empresa, sin crear espacio nuevo.
        $this->get('/demo-cobros/inicio')->assertOk();
        $this->assertSame($first, session(DemoVisitorController::SESSION_TOKEN_KEY));
        $this->assertSame($firstSession->id, app(\App\Services\Cobros\CobrosDemoSessionService::class)->resolve($first)->id);
        $this->assertSame(1, CobrosDemoSession::query()->count());
    }

    public function test_dos_visitantes_independientes_no_comparten_espacio(): void
    {
        // Visitante A.
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $tokenA = session(DemoVisitorController::SESSION_TOKEN_KEY);
        $service = app(\App\Services\Cobros\CobrosDemoSessionService::class);
        $companyA = $service->resolve($tokenA)->company_id;

        // Visitante B: sesión completamente nueva (simula otro navegador).
        $this->flushSession();
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $tokenB = session(DemoVisitorController::SESSION_TOKEN_KEY);
        $companyB = $service->resolve($tokenB)->company_id;

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertNotSame($companyA, $companyB);
        $this->assertSame(2, CobrosDemoSession::query()->count());
    }

    public function test_autorizacion_solo_en_servidor_token_ajeno_o_inventado_no_abre(): void
    {
        // Token inventado en la sesión del visitante => nunca resuelve.
        $this->withSession([DemoVisitorController::SESSION_TOKEN_KEY => 'token-ajeno-'.str_repeat('z', 30)])
            ->get('/demo-cobros/inicio')
            ->assertRedirect(route('cobros.demo.entry'));

        // POST de modo sin sesión válida => redirige, no cambia nada.
        $this->withSession([DemoVisitorController::SESSION_TOKEN_KEY => ''])
            ->post('/demo-cobros/modo', ['mode' => 'guiado'])
            ->assertRedirect(route('cobros.demo.entry'));
        $this->assertNull(session(DemoVisitorController::SESSION_MODE_KEY));
    }

    public function test_elegir_modo_requiere_sesion_valida_y_lo_guarda(): void
    {
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));

        $this->post('/demo-cobros/modo', ['mode' => 'libre'])
            ->assertRedirect(route('cobros.demo.home'));
        $this->assertSame('libre', session(DemoVisitorController::SESSION_MODE_KEY));

        // Modo inválido => rechazado.
        $this->post('/demo-cobros/modo', ['mode' => 'hack'])
            ->assertSessionHasErrors('mode');
    }

    public function test_sesion_vencida_no_abre_y_genera_un_espacio_nuevo(): void
    {
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $oldToken = session(DemoVisitorController::SESSION_TOKEN_KEY);
        $service = app(\App\Services\Cobros\CobrosDemoSessionService::class);

        $this->travel($service->ttlMinutes() + 1)->minutes();

        // La home con token vencido redirige a la entrada (autorización servidor).
        $this->get('/demo-cobros/inicio')->assertRedirect(route('cobros.demo.entry'));

        // Reintentar la entrada crea un espacio nuevo (token distinto).
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $newToken = session(DemoVisitorController::SESSION_TOKEN_KEY);
        $this->assertNotSame($oldToken, $newToken);

        $this->travelBack();
    }

    public function test_no_hay_pagos_activaciones_ni_llamadas_externas(): void
    {
        Http::fake(['*' => Http::response([], 404)]);

        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $this->get('/demo-cobros/inicio')->assertOk();
        // 21D-FIX-1: el modo guiado avanza al primer paso del tour.
        $this->post('/demo-cobros/modo', ['mode' => 'guiado'])->assertRedirect(route('cobros.demo.tour'));

        $this->assertDatabaseCount('billing_payment_records', 0);
        $this->assertDatabaseCount('cobros_payment_confirmations', 0);
        $this->assertDatabaseCount('cobros_registration_drafts', 0);
        Http::assertNothingSent();
    }

    public function test_landing_enlaza_a_la_entrada_demo(): void
    {
        $this->get('/bienvenida-cobros')
            ->assertOk()
            ->assertSee(route('cobros.demo.entry'), false);
    }
}
