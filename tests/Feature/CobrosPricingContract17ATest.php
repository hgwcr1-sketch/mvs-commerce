<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosContractSeat;
use App\Models\CobrosPriceOverride;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * BLOQUE 17A-1 — motor de licencias, tarifas y precios negociados.
 *
 * Cubre: tarifas estándar CR/CO, prestamista-cobrador sin doble cargo,
 * contratos individuales y empresariales con UN responsable de pago,
 * cupos, mixta bloqueada por defecto, precios personalizados auditados y
 * aislados por contrato, permisos de Panel Maestro y cero activación.
 */
class CobrosPricingContract17ATest extends TestCase
{
    use RefreshDatabase;

    private CobrosPricingService $pricing;

    private CobrosBillingContractService $contracts;

    /** Tablas que SOLO la activación (17A-2) podrá escribir al facturar. */
    private const ACTIVATION_TABLES = [
        'company_licenses', 'company_modules', 'cobros_collector_licenses',
        'cobros_route_licenses', 'cobros_routes', 'cobros_assignments',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = app(CobrosPricingService::class);
        $this->contracts = app(CobrosBillingContractService::class);
    }

    private function company(string $suffix = 'a'): array
    {
        $owner = User::factory()->create(['is_active' => true, 'email' => "presta.{$suffix}@tarifas.test"]);
        $company = Company::create([
            'trade_name' => 'Prestamista '.ucfirst($suffix),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $owner->id,
        ]);

        return [$company, $owner];
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
    }

    private function activationSnapshot(): array
    {
        return collect(self::ACTIVATION_TABLES)
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }

    private function assertNoActivation(array $snapshot): void
    {
        $this->assertSame($snapshot, $this->activationSnapshot());
    }

    public function test_standard_prices_costa_rica_and_colombia(): void
    {
        // Costa Rica: ₡10.000 por concepto base.
        $this->assertSame('10000.0000', $this->pricing->standardAmount('CR', 'administration'));
        $this->assertSame('10000.0000', $this->pricing->standardAmount('CR', 'route'));
        $this->assertSame('10000.0000', $this->pricing->standardAmount('CR', 'collector'));

        $independent = $this->pricing->quote('CR', 'cobrador_independiente');
        $this->assertSame('10000.0000', $independent->monthlyTotal);
        $this->assertSame('CRC', $independent->currency);

        $linked = $this->pricing->quote('CR', 'cobrador_vinculado');
        $this->assertSame('10000.0000', $linked->monthlyTotal);

        $lender = $this->pricing->quote('CR', 'prestamista');
        $this->assertSame('10000.0000', $lender->monthlyTotal);

        // Colombia: $60.000 unidad base; prestamista-cobrador total $60.000.
        $this->assertSame('60000.0000', $this->pricing->standardAmount('CO', 'administration'));
        $this->assertSame('60000.0000', $this->pricing->standardAmount('CO', 'route'));

        $coAdmin = $this->pricing->quote('CO', 'prestamista');
        $this->assertSame('60000.0000', $coAdmin->monthlyTotal);
        $this->assertSame('COP', $coAdmin->currency);

        $coCollector = $this->pricing->quote('CO', 'prestamista_cobrador');
        $this->assertSame('60000.0000', $coCollector->monthlyTotal);
    }

    public function test_prestamista_cobrador_charges_once_and_never_duplicates(): void
    {
        $quote = $this->pricing->quote('CR', 'prestamista_cobrador');

        // ₡10.000 TOTAL: incluye administración y su única ruta (16R-1).
        $this->assertSame('10000.0000', $quote->monthlyTotal);
        $this->assertNotSame('20000.0000', $quote->monthlyTotal);

        $concepts = array_column($quote->lines, 'concept');
        $this->assertContains('administration', $concepts);
        $this->assertContains('route', $concepts);

        $routeLine = collect($quote->lines)->firstWhere('concept', 'route');
        $this->assertSame('0.0000', $routeLine->amount);
        $this->assertSame('included', $routeLine->source);

        $this->assertSame('₡10.000,00', $quote->formattedTotal());
    }

    public function test_individual_contract_single_payer_without_activation(): void
    {
        $snapshot = $this->activationSnapshot();
        [$company, $payer] = $this->company('ind');
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createIndividual(
            $company,
            'cobrador_independiente',
            'CR',
            $payer,
            now()->startOfMonth(),
            now()->addMonth()->startOfMonth(),
            $admin,
        );

        $this->assertSame(CobrosBillingContract::MODE_INDIVIDUAL, $contract->billing_mode);
        $this->assertSame(CobrosBillingContract::STATUSES[0], $contract->status, 'El contrato nace en draft: sin activar ni facturar.');
        $this->assertSame($payer->id, $contract->payer_user_id, 'Un único responsable de pago.');
        $this->assertSame(1, $contract->seats_total);
        $this->assertSame('10000.0000', (string) $contract->monthly_amount);
        $this->assertCount(1, $contract->seats);

        $this->assertSame('10000.0000', $this->contracts->monthlyTotal($contract));

        // Un solo contrato abierto por empresa.
        $this->expectException(ValidationException::class);
        try {
            $this->contracts->createIndividual(
                $company,
                'prestamista',
                'CR',
                $payer,
                now()->startOfMonth(),
                now()->addMonth()->startOfMonth(),
                $admin,
            );
        } finally {
            $this->assertNoActivation($snapshot);
        }
    }

    public function test_enterprise_contract_negotiated_total_seats_and_single_payer(): void
    {
        $snapshot = $this->activationSnapshot();
        [$company, $payer] = $this->company('emp');
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createEnterprise(
            $company,
            4,
            'CR',
            $payer,
            now()->startOfMonth(),
            now()->addYear()->startOfMonth(),
            $admin,
            '35000.0000',
            'Acuento anual de cortesía autorizado comercialmente.',
        );

        $this->assertSame(CobrosBillingContract::MODE_ENTERPRISE, $contract->billing_mode);
        $this->assertSame(4, $contract->seats_total);
        $this->assertSame($payer->id, $contract->payer_user_id, 'UN responsable de pago para todo el contrato.');
        $this->assertSame('35000.0000', (string) $contract->monthly_amount, 'Precio negociado del contrato empresarial.');
        $this->assertCount(4, $contract->seats);
        $this->assertSame(4, $this->contracts->seatsRemaining($contract));

        // Asignar cupos a cobradores; el pagador no cambia.
        $collectorA = User::factory()->create(['is_active' => true, 'email' => 'cob.a@emp.test']);
        $collectorB = User::factory()->create(['is_active' => true, 'email' => 'cob.b@emp.test']);

        $seatA = $this->contracts->assignSeat($contract, $collectorA);
        $this->assertSame(CobrosContractSeat::STATUS_ASSIGNED, $seatA->status);
        $this->assertSame($collectorA->id, $seatA->user_id);
        $this->assertSame(3, $this->contracts->seatsRemaining($contract->refresh()));

        $this->contracts->assignSeat($contract, $collectorB);

        // El mismo cobrador no ocupa dos cupos.
        try {
            $this->contracts->assignSeat($contract, $collectorA);
            $this->fail('La doble asignación debió bloquearse.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ya ocupa un cupo', implode(' ', \Illuminate\Support\Arr::flatten($exception->errors())));
        }

        // Liberar vuelve el cupo a disponible; los precios no cambian.
        $this->contracts->releaseSeat($contract, $collectorB);
        $this->assertSame(3, $this->contracts->seatsRemaining($contract->refresh()));
        $this->assertSame($payer->id, $contract->fresh()->payer_user_id);
        $this->assertSame('35000.0000', $this->contracts->monthlyTotal($contract->refresh()));

        $this->assertSame(0, CobrosPriceOverride::count());
        $this->assertNoActivation($snapshot);
    }

    public function test_mixed_billing_is_blocked_by_default_and_opens_with_panel_flag(): void
    {
        [$company, $payer] = $this->company('mix');
        $admin = $this->platformAdmin();

        try {
            $this->contracts->createMixed(
                $company,
                2,
                'CR',
                $payer,
                now()->startOfMonth(),
                now()->addYear()->startOfMonth(),
                $admin,
            );
            $this->fail('La modalidad mixta debió bloquearse con la bandera apagada.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('mixta no está habilitada', implode(' ', \Illuminate\Support\Arr::flatten($exception->errors())));
        }

        // Habilitada por la bandera del Panel Maestro (config, no interfaz).
        config(['cobros.billing.mixed_enabled' => true]);

        $contract = $this->contracts->createMixed(
            $company,
            2,
            'CR',
            $payer,
            now()->startOfMonth(),
            now()->addYear()->startOfMonth(),
            $admin,
        );

        $this->assertSame(CobrosBillingContract::MODE_MIXED, $contract->billing_mode);
    }

    public function test_custom_price_is_audited_and_isolated_per_contract(): void
    {
        $snapshot = $this->activationSnapshot();
        [$companyA, $payerA] = $this->company('ovr1');
        [$companyB, $payerB] = $this->company('ovr2');
        $admin = $this->platformAdmin();

        $contractA = $this->contracts->createIndividual(
            $companyA, 'cobrador_independiente', 'CR', $payerA,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
        $contractB = $this->contracts->createIndividual(
            $companyB, 'cobrador_independiente', 'CR', $payerB,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        // Precio de prueba real futura con Stripe: ₡1.000 autorizado.
        $override = $this->contracts->authorizeOverride(
            $contractA,
            CobrosPricingService::CONCEPT_MONTHLY_TOTAL,
            '1000.0000',
            'Precio de prueba autorizado para validación con Stripe.',
            now()->startOfMonth(),
            now()->addYear()->startOfMonth(),
            $admin,
        );

        $this->assertSame($admin->id, $override->authorized_by_user_id, 'Debe quedar quién autorizó.');
        $this->assertNotNull($override->authorized_at);
        $this->assertStringContainsString('Stripe', $override->reason);
        $this->assertSame($contractA->id, $override->billing_contract_id);

        // Afecta SOLO su contrato.
        $this->assertSame('1000.0000', $this->contracts->monthlyTotal($contractA->refresh()));
        $this->assertSame('10000.0000', $this->contracts->monthlyTotal($contractB->refresh()),
            'Un precio personalizado jamás altera otros contratos.');

        // La tarifa estándar global queda intacta.
        $this->assertSame('10000.0000', $this->pricing->standardAmount('CR', 'collector'));

        $this->assertNoActivation($snapshot);
    }

    public function test_pricing_management_requires_platform_admin(): void
    {
        [$company, $payer] = $this->company('perm');
        $owner = User::factory()->create(['is_active' => true, 'email' => 'dueno@perm.test']);

        try {
            $this->contracts->createIndividual(
                $company, 'prestamista', 'CR', $payer,
                now()->startOfMonth(), now()->addYear()->startOfMonth(), $owner,
            );
            $this->fail('Un dueño de empresa no debe poder administrar precios.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, CobrosBillingContract::count());
    }
}
