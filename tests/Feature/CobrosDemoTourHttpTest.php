<?php

namespace Tests\Feature;

use App\Http\Controllers\Cobros\DemoVisitorController;
use App\Models\CobrosDemoSession;
use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BLOQUE 21D-4C.1 — recorrido guiado del DEMO individual: pasos, avances y
 * retrocesos, reingreso, aislamiento A/B, modalidad válida, token ajeno o
 * vencido denegado, reinicio solo propio. Sin pago ni activación reales.
 */
class CobrosDemoTourHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function service(): CobrosDemoSessionService
    {
        return app(CobrosDemoSessionService::class);
    }

    /** Inicia demo y elige «Recorrido guiado». */
    private function startGuided(): string
    {
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $this->post('/demo-cobros/modo', ['mode' => 'guiado'])->assertRedirect(route('cobros.demo.tour'));

        return session(DemoVisitorController::SESSION_TOKEN_KEY);
    }

    public function test_recorrido_completo_bienvenida_datos_modalidad(): void
    {
        $this->startGuided();

        $this->get('/demo-cobros/tour')->assertOk()->assertSee('Bienvenido a su demo guiado');

        $this->post('/demo-cobros/tour/bienvenida/guardar')->assertRedirect(route('cobros.demo.tour.step', ['step' => 'datos']));
        $this->get('/demo-cobros/tour/datos')->assertOk()->assertSee('Sus datos');

        $this->post('/demo-cobros/tour/datos/guardar', [
            'name' => 'María Demo', 'email' => 'maria.demo@demo-visitante.test', 'phone' => '+506 7000-0000',
        ])->assertRedirect(route('cobros.demo.tour.step', ['step' => 'modalidad']));

        $this->post('/demo-cobros/tour/modalidad/guardar', [
            'country_iso' => 'CR', 'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.demo.tour.step', ['step' => 'resumen']));

        $this->get('/demo-cobros/tour/resumen')->assertOk()->assertSee('María Demo')->assertSee('Prestamista');

        $session = CobrosDemoSession::query()
            ->where('token_hash', hash('sha256', session(DemoVisitorController::SESSION_TOKEN_KEY)))
            ->first();
        $this->assertSame('resumen', $session->tour_step);
        $this->assertSame('prestamista', $session->tour_answers['modality']);
        $this->assertSame('María Demo', $session->tour_answers['personal']['name']);
    }

    public function test_avances_y_retrocesos_funcionan(): void
    {
        $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'Ana Demo', 'email' => 'ana.demo@demo-visitante.test']);

        $this->get('/demo-cobros/tour/anterior?desde=modalidad')->assertRedirect(route('cobros.demo.tour.step', ['step' => 'datos']));
        $this->get('/demo-cobros/tour/datos')->assertOk()->assertSee('Ana Demo');

        $this->get('/demo-cobros/tour/anterior?desde=datos')->assertRedirect(route('cobros.demo.tour.step', ['step' => 'bienvenida']));
    }

    public function test_reingreso_continua_en_el_paso_guardado(): void
    {
        $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'Luis Demo', 'email' => 'luis.demo@demo-visitante.test']);
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CO', 'modality' => 'cobrador_independiente']);

        // Recarga / reingreso: GET sin parámetro => paso guardado (resumen).
        $this->get('/demo-cobros/tour')->assertOk()
            ->assertSee('Así quedaría su registro')
            ->assertSee('Colombia')
            ->assertSee('Cobrador independiente');
    }

    public function test_dos_visitantes_aislados_no_comparten_recorrido(): void
    {
        $tokenA = $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'Visitante A', 'email' => 'a.demo@demo-visitante.test']);
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => 'prestamista']);

        $sessionA = $this->service()->resolve($tokenA);
        $this->assertSame('resumen', $sessionA->fresh()->tour_step);

        $this->flushSession();
        $tokenB = $this->startGuided();
        $sessionB = $this->service()->resolve($tokenB);

        $this->assertNotSame($sessionA->id, $sessionB->id);
        $this->assertNull($sessionB->tour_step);
        $this->assertEmpty($sessionB->tour_answers ?? []);
        $this->assertSame('resumen', $sessionA->fresh()->tour_step);
    }

    public function test_modalidad_invalida_o_multiprestamista_es_rechazada(): void
    {
        $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');

        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => 'hack'])
            ->assertSessionHasErrors('modality');
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => 'cobrador_multiprestamista'])
            ->assertSessionHasErrors('modality');
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'MX', 'modality' => 'prestamista'])
            ->assertSessionHasErrors('country_iso');
    }

    public function test_token_ajeno_o_vencido_no_acede_al_recorrido(): void
    {
        $this->withSession([DemoVisitorController::SESSION_TOKEN_KEY => 'token-ajeno-'.str_repeat('q', 30)])
            ->get('/demo-cobros/tour')
            ->assertRedirect(route('cobros.demo.entry'));

        $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->travel($this->service()->ttlMinutes() + 1)->minutes();
        $this->get('/demo-cobros/tour')->assertRedirect(route('cobros.demo.entry'));
        $this->travelBack();
    }

    public function test_reiniciar_solo_afecta_el_recorrido_propio(): void
    {
        $tokenA = $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'A Demo', 'email' => 'a.demo@demo-visitante.test']);
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => 'prestamista']);

        $this->flushSession();
        $tokenB = $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'B Demo', 'email' => 'b.demo@demo-visitante.test']);
        $sessionB = $this->service()->resolve($tokenB);

        // B reinicia SOLO lo suyo.
        $this->post('/demo-cobros/tour/reiniciar')->assertRedirect(route('cobros.demo.tour'));
        $freshB = $sessionB->fresh();
        $this->assertSame('bienvenida', $freshB->tour_step);
        $this->assertEmpty($freshB->tour_answers ?? []);
        $this->assertSame($sessionB->company_id, $freshB->company_id); // empresa intacta

        // A intacto.
        $this->assertSame('resumen', CobrosDemoSession::query()
            ->where('token_hash', hash('sha256', $tokenA))->first()->tour_step);
    }

    public function test_sin_modo_guiado_no_entra_al_recorrido(): void
    {
        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
        $this->get('/demo-cobros/tour')->assertRedirect(route('cobros.demo.home'));
    }

    public function test_autocompletar_datos_ficticios(): void
    {
        $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/autofill')->assertRedirect(route('cobros.demo.tour.step', ['step' => 'datos']));
        $this->get('/demo-cobros/tour/datos')->assertOk()->assertSee('María Demo');
    }

    public function test_no_hay_pagos_activaciones_ni_llamadas_externas(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->startGuided();
        $this->post('/demo-cobros/tour/bienvenida/guardar');
        $this->post('/demo-cobros/tour/datos/guardar', ['name' => 'Prueba Demo', 'email' => 'p.demo@demo-visitante.test']);
        $this->post('/demo-cobros/tour/modalidad/guardar', ['country_iso' => 'CR', 'modality' => 'cobrador_vinculado']);

        $this->assertDatabaseCount('billing_payment_records', 0);
        $this->assertDatabaseCount('cobros_payment_confirmations', 0);
        $this->assertDatabaseCount('cobros_registration_drafts', 0);
        $this->assertDatabaseCount('users', 1); // solo el owner sintético de la empresa demo
        Http::assertNothingSent();
    }
}
