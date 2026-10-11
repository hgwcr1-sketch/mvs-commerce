<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosDemoSession;
use App\Models\CompanyLicense;
use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * BLOQUE 21D-4D-1 — exploración libre del DEMO individual: ingreso directo al
 * entorno operativo ficticio SIN recorrido guiado ni pago simulado. Cubre las
 * 3 modalidades públicas, denegación en modo guiado/inválido, aislamiento A/B,
 * expiración y cero pagos/licencias/contratos/HTTP externos adicionales.
 */
class CobrosDemoFreeEntryHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rutas operativas DEMO solo registradas en local; el test las replica
        // con middleware web para sesión (mismo patrón que CobrosDemoEntryHttpTest).
        Route::post('/demo-cobros/tour/entrar', [\App\Http\Controllers\Cobros\DemoTourController::class, 'enter'])
            ->middleware('web')
            ->name('cobros.demo.tour.enter');
        Route::post('/demo-cobros/libre/entrar', [\App\Http\Controllers\Cobros\DemoTourController::class, 'enterFree'])
            ->middleware('web')
            ->name('cobros.demo.free.enter');

        \Illuminate\Support\Facades\Cache::flush();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function service(): CobrosDemoSessionService
    {
        return app(CobrosDemoSessionService::class);
    }

    private function sessionRow(string $token): CobrosDemoSession
    {
        return CobrosDemoSession::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /** Visitante en modo libre (sin pago simulado). */
    private function freeVisitor(): string
    {
        $this->get('/demo-cobros');
        $this->post('/demo-cobros/modo', ['mode' => 'libre']);

        return session('cobros.demo_token');
    }

    public function test_las_tres_modalidades_ingresan_a_su_pantalla_operativa(): void
    {
        foreach ([
            'prestamista' => 'cobros.owner.dashboard',
            'cobrador_independiente' => 'cobros.collector.index',
            'cobrador_vinculado' => 'cobros.collector.index',
        ] as $modality => $landing) {
            $this->flushSession();
            $this->app['auth']->logout();

            $token = $this->freeVisitor();
            $this->post('/demo-cobros/libre/entrar', ['modality' => $modality])
                ->assertRedirect(route($landing));

            $this->assertAuthenticated();
            $companyId = session('active_company_id');
            $this->assertTrue(auth()->user()->companies()->where('companies.id', $companyId)->exists());
            $this->assertSame($modality, $this->sessionRow($token)->tour_answers['modality']);
        }
    }

    public function test_exploracion_libre_no_requiere_pago_simulado(): void
    {
        $token = $this->freeVisitor();
        $this->assertNull($this->sessionRow($token)->demo_payment);

        $this->post('/demo-cobros/libre/entrar', ['modality' => 'prestamista'])
            ->assertRedirect(route('cobros.owner.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_deniega_exploracion_libre_en_modo_guiado(): void
    {
        $this->get('/demo-cobros');
        $this->post('/demo-cobros/modo', ['mode' => 'guiado']);

        $this->post('/demo-cobros/libre/entrar', ['modality' => 'prestamista'])
            ->assertRedirect(route('cobros.demo.home'));
        $this->assertGuest();
    }

    public function test_modalidad_invalida_es_rechazada_en_servidor(): void
    {
        $this->freeVisitor();

        $this->from(route('cobros.demo.home'))
            ->post('/demo-cobros/libre/entrar', ['modality' => 'hacker'])
            ->assertRedirect(route('cobros.demo.home'))
            ->assertSessionHasErrors('modality');
        $this->assertGuest();
    }

    public function test_visitantes_a_y_b_aislados_no_comparten_entorno(): void
    {
        $tokenA = $this->freeVisitor();
        $companyA = $this->sessionRow($tokenA)->company_id;

        $this->post('/demo-cobros/libre/entrar', ['modality' => 'cobrador_independiente'])
            ->assertRedirect(route('cobros.collector.index'));
        $this->assertSame($companyA, session('active_company_id'));

        $this->app['auth']->logout();
        $this->flushSession();
        $tokenB = $this->freeVisitor();
        $companyB = $this->sessionRow($tokenB)->company_id;

        $this->assertNotSame($companyA, $companyB);
        $this->post('/demo-cobros/libre/entrar', ['modality' => 'prestamista'])
            ->assertRedirect(route('cobros.owner.dashboard'));
        $this->assertSame($companyB, session('active_company_id'));
        $this->assertSame($companyA, $this->sessionRow($tokenA)->company_id);
    }

    public function test_deniega_exploracion_libre_con_sesion_vencida(): void
    {
        $this->freeVisitor();
        $this->travel($this->service()->ttlMinutes() + 1)->minutes();

        $this->post('/demo-cobros/libre/entrar', ['modality' => 'prestamista'])
            ->assertRedirect(route('cobros.demo.entry'));
        $this->assertGuest();

        $this->travelBack();
    }

    public function test_cero_pagos_licencias_contratos_ni_http_externo(): void
    {
        $this->freeVisitor();

        $contractsBefore = CobrosBillingContract::query()->count();
        $licensesBefore = CompanyLicense::query()->count();
        $paymentsBefore = \App\Models\BillingPaymentRecord::query()->count();

        $this->post('/demo-cobros/libre/entrar', ['modality' => 'prestamista'])
            ->assertRedirect(route('cobros.owner.dashboard'));

        $this->assertSame($paymentsBefore, \App\Models\BillingPaymentRecord::query()->count());
        $this->assertDatabaseCount('cobros_payment_confirmations', 0);
        $this->assertSame($contractsBefore, CobrosBillingContract::query()->count());
        $this->assertSame($licensesBefore, CompanyLicense::query()->count());
        Http::assertNothingSent();
    }
}
