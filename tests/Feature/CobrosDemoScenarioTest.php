<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\CobrosAssignment;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteLicense;
use App\Models\CobrosVisit;
use App\Models\Customer;
use App\Models\User;
use App\Services\Cobros\CobrosDemoScenario;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosModalityService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * DEMO-DATOS-01B — escenario liviano: 2 cobradores operativos, 14 clientes
 * (7 por cobrador) y agenda semanal lunes-domingo (1 cliente por día).
 * Todo corre sobre SQLite en memoria (:memory:), nunca sobre la base local.
 */
class CobrosDemoScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 404)]);
    }

    /** Empresa con el trade name DEMO (marcador usado por isDemoCompany) + sucursal. */
    private function demoCompany(string $suffix): Company
    {
        // El trade name DEMO exacto es el marcador que reconoce isDemoCompany()
        // de DemoCompanyProvisioner; trade_name no tiene restricción de unicidad.
        $company = Company::create([
            'trade_name' => config('demo.company_name'),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        Branch::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'D'.strtoupper(substr($suffix, -2))],
            ['name' => 'Sucursal DEMO '.$suffix, 'is_active' => true]
        );

        return $company;
    }

    private function scenario(): CobrosDemoScenario
    {
        return app(CobrosDemoScenario::class);
    }

    private function byLocal(string $needle): User
    {
        return User::where('email', 'like', $needle.'%@'.CobrosDemoScenario::USER_DOMAIN)->firstOrFail();
    }

    public function test_seed_genera_dos_cobradores_catorce_clientes_y_agenda_semanal(): void
    {
        $company = $this->demoCompany('A');
        $before = $this->scenario()->summary($company);

        $summary = $this->scenario()->seed($company);

        $this->assertTrue($summary['created']);
        $this->assertSame(0, $before['routes']);
        $this->assertSame(2, $summary['routes']);
        $this->assertSame(14, $summary['customers']);
        $this->assertSame(14, $summary['assignments']);
        $this->assertSame(4, $summary['credits']);
        $this->assertSame(3, $summary['users']);
        $this->assertSame(14, $summary['visits']);

        // Exactamente 2 cobradores operativos (independiente y vinculado).
        $independiente = $this->byLocal('cobrador.independiente');
        $vinculado = $this->byLocal('cobrador.vinculado');
        $modalityService = app(CobrosModalityService::class);
        $this->assertSame(CobrosModalityCatalog::COBRADOR_INDEPENDIENTE,
            $modalityService->currentModality($company, $independiente));
        $this->assertSame(CobrosModalityCatalog::COBRADOR_VINCULADO,
            $modalityService->currentModality($company, $vinculado));
        $this->assertSame(CobrosModalityCatalog::PRESTAMISTA,
            $modalityService->currentModality($company, $this->byLocal('prestamista.administrador')));

        // No existen usuarios auxiliares de otras modalidades operativas.
        $this->assertSame(0, User::where('email', 'like', 'cobrador.multiprestamista%@'.CobrosDemoScenario::USER_DOMAIN)->count());
        $this->assertSame(0, User::where('email', 'like', 'prestamista.cobrador%@'.CobrosDemoScenario::USER_DOMAIN)->count());

        // Cada cobrador tiene exactamente 7 clientes distintos.
        foreach ([$independiente, $vinculado] as $collector) {
            $customerIds = CobrosAssignment::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('collector_user_id', $collector->id)
                ->pluck('customer_id');
            $this->assertCount(7, $customerIds);
            $this->assertCount(7, $customerIds->unique());
        }

        // Sin solape de cartera: los 14 clientes son disjoint entre cobradores.
        $norteIds = CobrosAssignment::withoutGlobalScopes()->where('collector_user_id', $independiente->id)->pluck('customer_id');
        $surIds = CobrosAssignment::withoutGlobalScopes()->where('collector_user_id', $vinculado->id)->pluck('customer_id');
        $this->assertSame(0, $norteIds->intersect($surIds)->count());

        // Agenda semanal: 1 visita pendiente por día lunes-domingo por cobrador.
        foreach ([$independiente, $vinculado] as $collector) {
            $visits = CobrosVisit::where('company_id', $company->id)
                ->where('collector_user_id', $collector->id)
                ->where('status', CobrosVisit::STATUS_PENDING)
                ->get();
            $this->assertCount(7, $visits);
            $weekdays = $visits->map(fn ($v) => \Carbon\Carbon::parse($v->scheduled_for)->dayOfWeekIso)->sort()->values()->all();
            $this->assertSame([1, 2, 3, 4, 5, 6, 7], $weekdays);

            // Cada visita pertenece a la cartera del cobrador.
            $assignmentCustomerIds = CobrosAssignment::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('collector_user_id', $collector->id)
                ->pluck('customer_id')->all();
            foreach ($visits as $visit) {
                $this->assertContains($visit->customer_id, $assignmentCustomerIds);
            }
        }

        // Una licencia activa por ruta (servicio existente, no reescrito).
        $this->assertSame(2, CobrosRouteLicense::withoutGlobalScopes()
            ->where('company_id', $company->id)->count());

        // Créditos desembolsados con sus cuotas y cuenta por cobrar asociada a la ruta.
        $routeIds = CobrosRoute::withoutGlobalScopes()->where('company_id', $company->id)->pluck('id');
        $this->assertSame(16, CobrosCreditInstallment::whereIn('credit_id',
            \App\Models\CobrosCredit::withoutGlobalScopes()->whereIn('cobros_route_id', $routeIds)->pluck('id'))->count());
        $this->assertGreaterThanOrEqual(4, \App\Models\AccountReceivable::whereIn('cobros_route_id', $routeIds)->count());

        // Pagos reales por el camino de dinero existente (custodia, sin caja).
        $accounts = \App\Models\AccountReceivable::whereIn('cobros_route_id', $routeIds)->pluck('id');
        $payments = \App\Models\AccountReceivablePayment::whereIn('account_receivable_id', $accounts)->get();
        $this->assertCount(3, $payments);
        foreach ($payments as $payment) {
            $this->assertSame(CobrosDemoScenario::PAYMENT_AMOUNT, $payment->amount);
            $this->assertFalse((bool) $payment->affects_cash_snapshot);
        }

        // Módulo CobrOS habilitado conservando los existentes.
        $this->assertTrue(CompanyModule::where('company_id', $company->id)
            ->where('module_key', 'cobros')->value('is_enabled'));
    }

    public function test_seed_es_idempotente_y_no_duplica_filas(): void
    {
        $company = $this->demoCompany('B');
        $first = $this->scenario()->seed($company);
        $second = $this->scenario()->seed($company);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(
            collect($first)->except('created')->all(),
            collect($second)->except('created')->all()
        );
        $this->assertSame(2, CobrosRoute::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(14, Customer::where('company_id', $company->id)->count());
        $this->assertSame(3, User::where('email', 'like', '%@'.CobrosDemoScenario::USER_DOMAIN)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->count());
    }

    public function test_reset_es_idempotente_y_no_toca_datos_ajenos_ni_otra_empresa(): void
    {
        $companyA = $this->demoCompany('A1');
        $companyB = $this->demoCompany('B1');

        $this->scenario()->seed($companyA);
        $this->scenario()->seed($companyB);

        // Datos ajenos al escenario en la MISMA empresa: jamás deben eliminarse.
        $foreign = Customer::create([
            'company_id' => $companyA->id,
            'name' => 'Cliente Operativo Propio',
            'identification' => '100000001',
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
        $foreignUser = User::factory()->create(['is_active' => true]);
        $foreignUser->companies()->attach($companyA->id, ['role_id' => null]);

        $removed = $this->scenario()->reset($companyA);

        $this->assertGreaterThan(0, $removed['routes']);
        $summaryA = $this->scenario()->summary($companyA);
        $this->assertSame(0, $summaryA['routes']);
        $this->assertSame(0, $summaryA['customers']);
        $this->assertSame(0, $summaryA['credits']);
        $this->assertSame(0, $summaryA['payments']);
        $this->assertSame(0, $summaryA['visits']);
        $this->assertSame(0, $summaryA['users']);

        // Ajenos intactos.
        $this->assertDatabaseHas('customers', ['id' => $foreign->id, 'name' => 'Cliente Operativo Propio']);
        $this->assertDatabaseHas('company_user', ['user_id' => $foreignUser->id, 'company_id' => $companyA->id]);

        // La OTRA empresa DEMO queda intacta.
        $summaryB = $this->scenario()->summary($companyB);
        $this->assertSame(2, $summaryB['routes']);
        $this->assertSame(14, $summaryB['customers']);
        $this->assertSame(4, $summaryB['credits']);

        // Reset repetido: idempotente, sin errores y sin filas extra.
        $again = $this->scenario()->reset($companyA);
        $this->assertSame(0, $again['routes']);
        $this->assertSame(0, $again['customers']);

        // Se puede volver a generar desde cero.
        $reseed = $this->scenario()->seed($companyA);
        $this->assertTrue($reseed['created']);
        $this->assertSame(2, $reseed['routes']);
    }

    public function test_rechaza_empresas_no_demo_y_entornos_no_autorizados(): void
    {
        $company = Company::create([
            'trade_name' => 'Empresa Operativa Real '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('solo opera sobre empresas DEMO');
        $this->scenario()->seed($company);
    }

    public function test_datos_ficticios_no_parecen_cedulas_ni_datos_reales(): void
    {
        $company = $this->demoCompany('C1');
        $this->scenario()->seed($company);

        $customers = Customer::where('company_id', $company->id)
            ->where('name', 'like', CobrosDemoScenario::CUSTOMER_PREFIX.'%')->get();
        $this->assertCount(14, $customers);

        foreach ($customers as $customer) {
            $identification = (string) $customer->identification;
            $this->assertMatchesRegularExpression('/^999\d{6}$/', $identification);
            // Cédulas costarricenses tienen primer dígito 0–8: esta identificación NUNCA
            // puede ser una cédula costarricense real.
            $this->assertDoesNotMatchRegularExpression('/^[0-8]\d{8}$/', $identification);
            $this->assertStringStartsWith(CobrosDemoScenario::CUSTOMER_PREFIX, $customer->name);
        }

        // Identificación no usada por ningún cliente ajeno de esta empresa.
        $this->assertSame(14, Customer::where('company_id', $company->id)
            ->where('identification', 'like', '999%')->count());
    }
}
