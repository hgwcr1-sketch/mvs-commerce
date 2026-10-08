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
use Tests\TestCase;

/**
 * BLOQUE 11A — semáforo: 0=verde, 1=amarillo, 2+=rojo; nuevos verdes;
 * migrados por declared_behavior; abono parcial no cambia el color solo.
 */
class CobrosCreditRisk11ATest extends TestCase
{
    use RefreshDatabase;

    public function test_nuevo_sin_historial_es_verde(): void
    {
        [$company, $customer] = $this->context();

        $result = app(CreditRiskService::class)->classify($company, $customer);

        $this->assertSame('verde', $result['level']);
        $this->assertSame(0, $result['overdue_count']);
    }

    public function test_cero_una_y_dos_cuotas_vencidas(): void
    {
        [$company, $customer] = $this->context();
        $service = app(CreditRiskService::class);
        $credit = $this->credit($company, $customer);

        $this->assertSame('verde', $service->classify($company, $customer)['level']);

        $this->installment($credit, 1, today()->subDays(5));
        $this->assertSame('amarillo', $service->classify($company, $customer)['level']);

        $this->installment($credit, 2, today()->subDays(3));
        $result = $service->classify($company, $customer);
        $this->assertSame('rojo', $result['level']);
        $this->assertSame(2, $result['overdue_count']);
    }

    public function test_abono_parcial_no_cambia_color_y_cuota_pagada_no_cuenta(): void
    {
        [$company, $customer] = $this->context();
        $service = app(CreditRiskService::class);
        $credit = $this->credit($company, $customer);

        $this->installment($credit, 1, today()->subDays(5), '900.0000', '100.0000');
        $this->assertSame('amarillo', $service->classify($company, $customer)['level']);

        $this->installment($credit, 2, today()->subDays(3), '500.0000', '500.0000', CobrosCreditInstallment::STATUS_PAID);
        $result = $service->classify($company, $customer);
        $this->assertSame('amarillo', $result['level']);
        $this->assertSame(1, $result['overdue_count']);
    }

    public function test_migrados_respetan_historico_y_deuda_grave_es_roja(): void
    {
        $service = app(CreditRiskService::class);

        [$c1, $cu1] = $this->context();
        $this->migrated($c1, $cu1, 'malo');
        $this->assertSame('rojo', $service->classify($c1, $cu1)['level']);

        [$c2, $cu2] = $this->context();
        $this->migrated($c2, $cu2, 'regular');
        $this->assertSame('amarillo', $service->classify($c2, $cu2)['level']);

        [$c3, $cu3] = $this->context();
        $this->migrated($c3, $cu3, 'bueno');
        $this->assertSame('verde', $service->classify($c3, $cu3)['level']);

        [$c4, $cu4] = $this->context();
        $this->migrated($c4, $cu4, 'bueno', 'Deuda antigua grave en cobro judicial');
        $this->assertSame('rojo', $service->classify($c4, $cu4)['level']);
    }

    /** @return array{0: Company, 1: Customer} */
    private function context(): array
    {
        $company = Company::create([
            'trade_name' => 'Riesgo '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
        ]);
        $customer = Customer::create([
            'company_id' => $company->id, 'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(), 'is_active' => true,
        ]);

        return [$company->fresh(), $customer];
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

    private function installment(
        CobrosCredit $credit,
        int $number,
        $dueDate,
        string $contractual = '500.0000',
        string $applied = '0.0000',
        string $status = CobrosCreditInstallment::STATUS_PENDING
    ): void {
        CobrosCreditInstallment::create([
            'credit_id' => $credit->id, 'number' => $number,
            'due_date' => CarbonImmutable::parse($dueDate)->toDateString(),
            'contractual_amount' => $contractual, 'applied_amount' => $applied,
            'status' => $status,
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
