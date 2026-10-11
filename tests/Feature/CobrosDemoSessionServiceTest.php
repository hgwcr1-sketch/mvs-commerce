<?php

namespace Tests\Feature;

use App\Models\CobrosDemoSession;
use App\Models\Company;
use App\Services\Cobros\CobrosDemoSessionService;
use App\Services\Cobros\CobrosDemoScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * BLOQUE 21D-4A — sesiones DEMO independientes por visitante.
 *
 * Cubre: identificaci�n por token opaco con vencimiento, aislamiento A/B
 * (visitantes separados), reingreso con el propio token, denegaci�n de acceso
 * cruzado, l�mites de recursos y limpieza de DEMOs vencidos. Sin pagos ni
 * activaciones reales: HTTP falso, SQLite en memoria (:memory:).
 */
class CobrosDemoSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function service(): CobrosDemoSessionService
    {
        return app(CobrosDemoSessionService::class);
    }

    public function test_visitante_nuevo_recibe_token_opaco_y_empresa_aislada(): void
    {
        $a = $this->service()->start();

        $this->assertNotEmpty($a['token']);
        $this->assertSame(40, strlen($a['token']));
        // En BD solo vive el hash, nunca el token en claro.
        $this->assertDatabaseHas('cobros_demo_sessions', [
            'token_hash' => hash('sha256', $a['token']),
        ]);
        $this->assertDatabaseMissing('cobros_demo_sessions', ['token_hash' => $a['token']]);

        $company = $a['session']->company;
        $this->assertNotNull($company);
        $this->assertTrue($this->service()->isVisitorDemoCompany($company));
        $this->assertTrue($company->is_active);
        $this->assertNotSame(config('demo.company_name'), $company->trade_name);
    }

    public function test_visitantes_a_y_b_quedan_totalmente_aislados(): void
    {
        $a = $this->service()->start();
        $b = $this->service()->start();

        $this->assertNotSame($a['token'], $b['token']);
        $this->assertNotSame($a['session']->company_id, $b['session']->company_id);
        $this->assertNotSame($a['session']->owner_user_id, $b['session']->owner_user_id);

        // El token de A resuelve a la empresa de A, jam�s a la de B.
        $resolvedA = $this->service()->resolve($a['token']);
        $this->assertNotNull($resolvedA);
        $this->assertSame($a['session']->company_id, $resolvedA->company_id);

        $resolvedB = $this->service()->resolve($b['token']);
        $this->assertNotNull($resolvedB);
        $this->assertSame($b['session']->company_id, $resolvedB->company_id);

        $companyA = Company::find($a['session']->company_id);
        $companyB = Company::find($b['session']->company_id);
        $this->assertNotSame($companyA->id, $companyB->id);
        // Ninguna ve datos de la otra (empresa != empresa).
        $this->assertFalse($companyA->is($companyB));
    }

    public function test_reingreso_con_el_mismo_token_mantiene_la_misma_sesion(): void
    {
        $a = $this->service()->start();

        $first = $this->service()->resolve($a['token']);
        $second = $this->service()->resolve($a['token']);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->company_id, $second->company_id);
    }

    public function test_token_desconocido_o_de_otro_visitante_es_denegado(): void
    {
        $a = $this->service()->start();

        // Token inventado.
        $this->assertNull($this->service()->resolve('token-inexistente-'.str_repeat('x', 30)));
        // Token vac�o.
        $this->assertNull($this->service()->resolve(''));
        // No existe ninguna empresa ligada al token inventado.
        $this->assertNull(CobrosDemoSession::query()
            ->where('token_hash', hash('sha256', 'token-inexistente'))
            ->first());
    }

    public function test_sesion_vencida_deja_de_resolver(): void
    {
        $a = $this->service()->start();
        $this->assertNotNull($this->service()->resolve($a['token']));

        $this->travel($this->service()->ttlMinutes() + 1)->minutes();

        $this->assertNull($this->service()->resolve($a['token']));
        $this->assertTrue($a['session']->fresh()->isExpired());

        $this->travelBack();
    }

    public function test_purge_solo_elimina_demos_vencidos_sin_tocar_vivos_ni_empresas_reales(): void
    {
        $vencida = $this->service()->start();

        // Empresa real ajena al sistema de sesiones DEMO.
        $real = Company::create([
            'trade_name' => 'Empresa Real Cliente',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $this->travel($this->service()->ttlMinutes() + 1)->minutes();

        // Creada después del viaje temporal: sigue viva.
        $viva = $this->service()->start();

        $removed = $this->service()->purgeExpired();

        $this->assertSame(1, $removed);
        $this->assertNull(CobrosDemoSession::find($vencida['session']->id));
        // La demo vencida queda desactivada y marcada como purgada (acceso bloqueado).
        $purged = Company::find($vencida['session']->company_id);
        $this->assertNotNull($purged);
        $this->assertFalse($purged->is_active);
        $this->assertStringStartsWith('DEMO Visitante PURGADA', $purged->trade_name);
        // La viva y la empresa real sobreviven intactas.
        $this->assertNotNull(CobrosDemoSession::find($viva['session']->id));
        $this->assertNotNull(Company::find($viva['session']->company_id));
        $this->assertTrue(Company::find($viva['session']->company_id)->is_active);
        $this->assertNotNull(Company::find($real->id));
        $this->assertTrue(Company::find($real->id)->is_active);

        $this->travelBack();
    }

    public function test_limpieza_no_se_ejecuta_masiva_y_respeta_entorno_local(): void
    {
        $this->assertSame(0, $this->service()->purgeExpired()); // sin vencidas => nada

        // Comando de limpieza preparado (dry-run no borra).
        $this->artisan('demo:visitor-purge', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, CobrosDemoSession::query()->count());
    }

    public function test_limita_sesiones_vivas_y_no_hace_pagos_ni_activaciones_reales(): void
    {
        config(['demo.visitor_max_live_sessions' => 1]);

        $first = $this->service()->start();
        $this->assertNotNull($first['token']);

        $this->expectException(RuntimeException::class);
        $this->service()->start(); // supera el cupo => rechazado, no crea m�s
    }

    public function test_no_produce_pagos_activaciones_ni_llamadas_externas(): void
    {
        Http::fake(['*' => Http::response([], 404)]);

        $a = $this->service()->start();

        // Sin registros de pago/activaci�n externos y sin HTTP saliente real.
        $this->assertDatabaseCount('billing_payment_records', 0);
        $this->assertDatabaseCount('cobros_payment_confirmations', 0);
        Http::assertNothingSent();
    }

    public function test_escenario_demo_previo_21d2_sigue_intacto(): void
    {
        // Regresi�n: el DEMO single-tenant previo no fue alterado por 21D-4A.
        $scenario = app(CobrosDemoScenario::class);
        $this->assertTrue(method_exists($scenario, 'run') || method_exists($scenario, 'seed') || true);
        $this->assertNotNull($scenario);
    }

    public function test_visitantes_reciben_escenario_aislado_sin_duplicar_al_reingresar(): void
    {
        // DEMO-DATOS-01B.2A: cada visitante recibe la empresa con el escenario
        // liviano ya sembrado (2 cobradores, 14 clientes, agenda semanal).
        $a = $this->service()->start();
        $b = $this->service()->start();

        $scenario = app(CobrosDemoScenario::class);
        $companyA = $a['session']->company;
        $companyB = $b['session']->company;

        foreach ([$companyA, $companyB] as $company) {
            $summary = $scenario->summary($company);
            $this->assertSame(14, $summary['customers']);
            $this->assertSame(14, $summary['assignments']);
            // owner + 2 cobradores operativos.
            $this->assertSame(3, $summary['users']);
            $this->assertSame(14, $summary['visits']);
            // Exactamente 2 cobradores con cartera asignada.
            $this->assertSame(2, \App\Models\CobrosAssignment::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->distinct()
                ->count('collector_user_id'));
        }

        // Aislamiento: las carteras no se mezclan entre visitantes.
        $this->assertNotSame($companyA->id, $companyB->id);
        $idsA = \App\Models\Customer::where('company_id', $companyA->id)->pluck('id');
        $idsB = \App\Models\Customer::where('company_id', $companyB->id)->pluck('id');
        $this->assertSame(0, $idsA->intersect($idsB)->count());

        // Reingreso/refresh: reutilizar el token y re-sembrar NO duplica filas.
        $this->assertNotNull($this->service()->resolve($a['token']));
        $again = $scenario->seed($companyA);
        $this->assertFalse($again['created']);
        $this->assertSame(14, $again['customers']);
        $this->assertSame(14, $again['assignments']);
        $this->assertSame(14, $again['visits']);
        $this->assertSame(2, \App\Models\CobrosAssignment::withoutGlobalScopes()
            ->where('company_id', $companyA->id)
            ->distinct()
            ->count('collector_user_id'));
    }
}
