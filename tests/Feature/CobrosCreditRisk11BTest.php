<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Cobros\CreditRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 11B — semáforo visual reutilizable junto al nombre; reutiliza 11A sin escrituras. */
class CobrosCreditRisk11BTest extends TestCase
{
    use RefreshDatabase;

    public function test_componente_muestra_silueta_y_estado_accesible(): void
    {
        $company = Company::create(['trade_name' => 'Semaforo '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);

        $html = view('cobros.components.credit-risk', ['customer' => $customer, 'company' => $company])->render();
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('Estado de crédito: Al día', $html);
        $this->assertStringContainsString('stroke="#00B84A"', $html);
        $this->assertStringContainsString('stroke-width="2"', $html);
        $this->assertStringNotContainsString('rounded-full', $html);

        $html = view('cobros.components.credit-risk', ['level' => 'rojo'])->render();
        $this->assertStringContainsString('Estado de crédito: 2 o más vencidas', $html);
        $this->assertStringContainsString('stroke="#DC2626"', $html);
    }

    public function test_clasificar_por_lote_reutiliza_motor_sin_duplicar(): void
    {
        $company = Company::create(['trade_name' => 'Lote '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $a = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'A '.uniqid(), 'is_active' => true]);
        $b = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'B '.uniqid(), 'is_active' => true]);

        $levels = app(CreditRiskService::class)->classifyMany($company, [$a, $b]);
        $this->assertSame('verde', $levels[$a->id]['level']);
        $this->assertSame('verde', $levels[$b->id]['level']);
        $this->assertSame(0, $levels[$a->id]['overdue_count']);
        // Sin créditos ni registro de cartera inicial => cliente nuevo.
        $this->assertSame('nuevo', $levels[$a->id]['source']);
        $this->assertSame($levels[$a->id], app(CreditRiskService::class)->classify($company, $a));
    }
}
