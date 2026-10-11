<?php

namespace Tests\Feature;

use App\Services\Cobros\CobrosDemoAccess;
use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INTEGRACIÓN-05 — habilitación explícita del DEMO para staging.
 *
 * Cubre: local habilitado, staging habilitado, producción bloqueada,
 * DEMO deshabilitado y acceso directo con URL prohibido. Todas las capas
 * (rutas, controladores y servicios) usan CobrosDemoAccess como única verdad.
 */
class CobrosDemoEnablementTest extends TestCase
{
    use RefreshDatabase;

    private function setEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
    }

    /**
     * POST con CSRF válido: al salir de 'testing' el framework deja de
     * omitir VerifyCsrfToken, así que se envía el token de sesión.
     */
    private function postCsrf(string $uri, array $data = [])
    {
        return $this->withSession(['_token' => 'token-prueba'])
            ->post($uri, $data + ['_token' => 'token-prueba']);
    }

    /** 1) Local + habilitado explícito => DEMO operativo. */
    public function test_local_habilitado_permite_el_demo(): void
    {
        $this->setEnvironment('local');
        config(['demo.enabled' => true]);

        $this->assertTrue(CobrosDemoAccess::enabled());

        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
    }

    /** 2) Staging + habilitado explícito => DEMO operativo. */
    public function test_staging_habilitado_permite_el_demo(): void
    {
        $this->setEnvironment('staging');
        config(['demo.enabled' => true]);

        $this->assertTrue(CobrosDemoAccess::enabled());

        $this->get('/demo-cobros')->assertRedirect(route('cobros.demo.home'));
    }

    /** 3) Producción => bloqueado aunque la configuración lo habilite. */
    public function test_produccion_bloquea_el_demo(): void
    {
        $this->setEnvironment('production');
        config(['demo.enabled' => true]);

        $this->assertFalse(CobrosDemoAccess::enabled());

        $this->get('/demo-cobros')->assertNotFound();
        $this->postCsrf('/demo-cobros/modo', ['mode' => 'libre'])->assertNotFound();
    }

    /** 4) Entorno permitido pero DEMO deshabilitado => bloqueado. */
    public function test_demo_deshabilitado_bloquea_rutas_y_servicios(): void
    {
        $this->setEnvironment('local');
        config(['demo.enabled' => false]);

        $this->assertFalse(CobrosDemoAccess::enabled());

        $this->get('/demo-cobros')->assertNotFound();
        $this->get('/demo-cobros/tour')->assertNotFound();

        $this->expectException(\RuntimeException::class);
        app(CobrosDemoSessionService::class)->start();
    }

    /** 5) Acceso directo con URL al ingreso operativo => 404 en servidor. */
    public function test_acceso_directo_al_ingreso_operativo_queda_prohibido(): void
    {
        config(['demo.enabled' => true]);

        $this->setEnvironment('production');
        $this->postCsrf('/demo-cobros/tour/entrar')->assertNotFound();
        $this->postCsrf('/demo-cobros/libre/entrar')->assertNotFound();

        $this->setEnvironment('staging');
        config(['demo.enabled' => false]);
        $this->postCsrf('/demo-cobros/tour/entrar')->assertNotFound();
        $this->postCsrf('/demo-cobros/libre/entrar')->assertNotFound();

        $this->assertFalse(CobrosDemoAccess::enabled());
    }
}
