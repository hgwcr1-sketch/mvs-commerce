<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 21D-FIX-3B — identidad DEMO por modalidad: los cobradores (independiente y
 * vinculado) autentican un usuario sintético SIN permisos administrativos y
 * reciben 403 al intentar acceder por URL al portal del dueño.
 */
class CobrosDemoModalityIdentityHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/demo-cobros/libre/entrar', [\App\Http\Controllers\Cobros\DemoTourController::class, 'enterFree'])
            ->middleware('web')
            ->name('cobros.demo.free.enter');

        // Permisos CobrOS mínimos ANTES de provisionar la empresa del
        // visitante: CompanyProvisioner sincroniza los activos al rol
        // Administrador, por lo que el owner DEMO los hereda.
        foreach ([
            'cobros.portal_dueno' => 'Portal Dueño',
            'cobros.acceder' => 'Acceder',
            'cobros.cobrar' => 'Cobrar',
            'cobros.asignar' => 'Asignar',
            'cobros.anular' => 'Anular',
            'notificaciones.cobros' => 'Notificaciones Cobros',
        ] as $name => $label) {
            \App\Models\Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $label, 'module' => 'CobrOS', 'is_active' => true]
            );
        }

        \Illuminate\Support\Facades\Cache::flush();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32))]);
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function freeEnter(string $modality): void
    {
        $this->get('/demo-cobros');
        $this->post('/demo-cobros/modo', ['mode' => 'libre']);
        $this->post('/demo-cobros/libre/entrar', ['modality' => $modality])
            ->assertRedirect(route('cobros.collector.index'));
    }

    public function test_cobrador_vinculado_sin_permisos_administrativos(): void
    {
        $this->freeEnter('cobrador_vinculado');

        $user = auth()->user();
        $company = Company::find(session('active_company_id'));

        $this->assertNotSame('demo@mvscommerce.com', $user->email);
        $this->assertFalse($user->hasPermission('cobros.portal_dueno', $company));
        $this->assertFalse($user->hasPermission('cobros.asignar', $company));
        $this->assertTrue($user->hasPermission('cobros.cobrar', $company));

        // Acceso directo por URL a funciones administrativas => 403 (servidor).
        $this->get(route('cobros.owner.collectors'))->assertForbidden();
        $this->get(route('cobros.owner.dashboard'))->assertForbidden();
    }

    public function test_cobrador_independiente_sin_permisos_administrativos(): void
    {
        $this->freeEnter('cobrador_independiente');

        $user = auth()->user();
        $company = Company::find(session('active_company_id'));

        $this->assertNotSame('demo@mvscommerce.com', $user->email);
        $this->assertFalse($user->hasPermission('cobros.portal_dueno', $company));
        $this->assertTrue($user->hasPermission('cobros.cobrar', $company));

        $this->get(route('cobros.owner.collectors'))->assertForbidden();
    }

    public function test_cobradores_de_visitantes_distintos_no_comparten_identidad(): void
    {
        $this->freeEnter('cobrador_vinculado');
        $userA = auth()->user()->id;
        $companyA = session('active_company_id');

        $this->app['auth']->logout();
        $this->flushSession();

        $this->freeEnter('cobrador_vinculado');
        $userB = auth()->user()->id;
        $companyB = session('active_company_id');

        $this->assertNotSame($companyA, $companyB);
        $this->assertNotSame($userA, $userB);
    }

    public function test_prestamista_conserva_owner_administrativo(): void
    {
        $this->get('/demo-cobros');
        $this->post('/demo-cobros/modo', ['mode' => 'libre']);
        $this->post('/demo-cobros/libre/entrar', ['modality' => 'prestamista'])
            ->assertRedirect(route('cobros.owner.dashboard'));

        $user = auth()->user();
        $company = Company::find(session('active_company_id'));

        // El owner sintético conserva su identidad administrativa (rol
        // provisionado por CompanyProvisioner con todos los permisos activos).
        $this->assertSame($company->owner_user_id, $user->id);

        // 21D-FIX-3I: la membresía queda marcada como prestamista y el
        // middleware de modalidad deniega el cobro operativo en servidor.
        $this->assertSame('prestamista', app(\App\Services\Cobros\CobrosModalityService::class)->currentModality($company, $user));
        $this->get(route('cobros.owner.dashboard'))->assertOk();
        $this->get(route('cobros.collector.index'))->assertForbidden();
    }
}
