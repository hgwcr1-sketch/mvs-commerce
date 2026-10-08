<?php

namespace Tests\Feature;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosInitialPortfolioMigration;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Cobros\CreditRiskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BLOQUE 12A fase 2 — semáforo por lote SIN N+1:
 * equivalencia exacta con classify() por cliente y número de consultas
 * constante (no crece con la cantidad de clientes del listado).
 */
class CobrosCreditRisk12BTest extends TestCase
{
    use RefreshDatabase;

    /** classifyMany() debe dar EXACTAMENTE los mismos resultados que classify() por cliente. */
    public function test_classify_many_es_equivalente_a_classify_por_cliente(): void
    {
        $service = app(CreditRiskService::class);
        [$company, $customers] = $this->scenario();

        $many = $service->classifyMany($company, $customers);

        $this->assertSame(array_column($customers, 'id'), array_keys($many));

        foreach ($customers as $customer) {
            $single = $service->classify($company, $customer);
            $this->assertSame($single, $many[(int) $customer->id], "Diferencia para cliente {$customer->id}");
        }

        // Sanity: los escenarios cubren los tres colores.
        $levels = array_column($many, 'level');
        $this->assertContains('verde', $levels);
        $this->assertContains('amarillo', $levels);
        $this->assertContains('rojo', $levels);
    }

    /** Las consultas del lote deben ser constantes: mismas con 4 que con 8 clientes. */
    public function test_classify_many_no_escala_consultas_con_el_numero_de_clientes(): void
    {
        $service = app(CreditRiskService::class);
        [$company, $customers] = $this->scenario();

        $half = array_slice($customers, 0, 4);
        $all = array_slice($customers, 0, 8);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->classifyMany($company, $half);
        $queriesHalf = count(DB::getQueryLog());

        DB::flushQueryLog();
        $service->classifyMany($company, $all);
        $queriesAll = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesHalf, $queriesAll, 'El número de consultas creció con más clientes (N+1).');
        // Q1 créditos + Q2 cuotas + Q3 histórico (+1 de arranque) = tope 4.
        $this->assertLessThanOrEqual(4, $queriesAll);

        // Y debe ser claramente menor que el enfoque N+1 (3-4 consultas POR cliente).
        $this->assertLessThan(count($all) * 2, $queriesAll);
    }

    /**
     * Escenarios en UNA empresa (8 clientes): nuevo, 1 vencida, 2 vencidas,
     * crédito al día, migrado malo/regular/bueno y deuda grave histórica.
     *
     * @return array{0: Company, 1: array<int, Customer>}
     */
    private function scenario(): array
    {
        $company = Company::create([
            'trade_name' => 'Lote '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);

        $new = $this->customer($company);                       // verde nuevo
        $c2 = $this->customer($company);                        // amarillo (1 vencida)
        $this->installment($this->credit($company, $c2), 1, today()->subDays(3));
        $c3 = $this->customer($company);                        // rojo (2 vencidas)
        $credit3 = $this->credit($company, $c3);
        $this->installment($credit3, 1, today()->subDays(9));
        $this->installment($credit3, 2, today()->subDays(3));
        $c5 = $this->customer($company);                        // verde (crédito sin vencidas)
        $this->credit($company, $c5);
        $c6 = $this->customer($company);                        // rojo histórico (malo)
        $this->migrated($company, $c6, 'malo');
        $c7 = $this->customer($company);                        // amarillo histórico (regular)
        $this->migrated($company, $c7, 'regular');
        $c8 = $this->customer($company);                        // rojo por nota grave histórica
        $this->migrated($company, $c8, 'bueno', 'Deuda antigua grave en cobro judicial');

        return [$company, [$new, $c2, $c3, $this->customer($company), $c5, $c6, $c7, $c8]];
    }

    private function customer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(), 'is_active' => true,
        ]);
    }

    private function credit(Company $company, Customer $customer): CobrosCredit
    {
        $collector = User::factory()->create();
        $route = CobrosRoute::create([
            'company_id' => $company->id, 'name' => 'Ruta '.uniqid(), 'active' => true,
        ]);
        $request = CobrosCreditRequest::create([
            'company_id' => $company->id, 'cobros_route_id' => $route->id,
            'collector_user_id' => $collector->id, 'customer_id' => $customer->id,
            'applicant_type' => 'existente', 'status' => CobrosCreditRequest::STATUS_PENDING,
            'identification_type' => 'cedula', 'identification' => '1'.random_int(100000000, 999999999),
            'name' => $customer->name, 'amount_requested' => '1000.0000', 'submitted_at' => now(),
        ]);

        return CobrosCredit::create([
            'company_id' => $company->id, 'credit_request_id' => $request->id,
            'customer_id' => $customer->id, 'collector_user_id' => $collector->id,
            'cobros_route_id' => $route->id,
            'principal' => '1000.0000', 'interest_type' => CobrosCredit::INTEREST_NONE,
            'interest_value' => '0.0000', 'total_due' => '1000.0000',
            'installment_count' => 4, 'frequency' => CobrosCredit::FREQUENCY_WEEKLY,
            'disbursement_date' => today()->subMonth(), 'first_due_date' => today()->subDays(10),
            'status' => CobrosCredit::STATUS_DISBURSED,
            'terms_snapshot' => ['amount_requested' => '1000.0000'],
        ]);
    }

    private function installment(CobrosCredit $credit, int $number, $dueDate): void
    {
        CobrosCreditInstallment::create([
            'credit_id' => $credit->id, 'number' => $number,
            'due_date' => CarbonImmutable::parse($dueDate)->toDateString(),
            'contractual_amount' => '500.0000', 'applied_amount' => '0.0000',
            'status' => CobrosCreditInstallment::STATUS_PENDING,
        ]);
    }

    private function migrated(Company $company, Customer $customer, string $behavior, ?string $note = null): void
    {
        $migration = CobrosInitialPortfolioMigration::create([
            'company_id' => $company->id,
            'collector_user_id' => User::factory()->create()->id,
            'status' => CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS,
        ]);
        CobrosInitialPortfolioRecord::create([
            'migration_id' => $migration->id, 'company_id' => $company->id,
            'customer_id' => $customer->id, 'identification_type' => 'cedula',
            'source' => CobrosInitialPortfolioRecord::SOURCE_INITIAL,
            'verification' => CobrosInitialPortfolioRecord::VERIFICATION_DECLARED,
            'original_amount' => '75000.0000', 'balance' => '90000.0000',
            'installment' => '5000.0000', 'frequency' => 'quincenal',
            'next_charge_date' => today()->addDays(7)->toDateString(),
            'declared_behavior' => $behavior, 'historical_note' => $note,
            'migrated_by_user_id' => User::factory()->create()->id, 'migrated_at' => now(),
        ]);
    }
}
