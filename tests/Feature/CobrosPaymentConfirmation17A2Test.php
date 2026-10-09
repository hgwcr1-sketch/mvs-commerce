<?php

namespace Tests\Feature;

use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * BLOQUE 17A-2 f1 — confirmación de pago y activación de licencias.
 *
 * Cubre: confirmación interna autorizada, activación idempotente, contratos
 * individuales (solo licencia del cobrador), empresariales (cupo con UN pago
 * mensual), validación de precio/moneda/período/aislamiento, renovación
 * preparada y estados vencido/suspendido sin borrar datos.
 */
class CobrosPaymentConfirmation17A2Test extends TestCase
{
    use RefreshDatabase;

    private CobrosBillingContractService $contracts;

    private CobrosPaymentConfirmationService $payments;

    private CompanyLicenseService $licenseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = app(CobrosBillingContractService::class);
        $this->payments = app(CobrosPaymentConfirmationService::class);
        $this->licenseService = app(CompanyLicenseService::class);
    }

    private function company(string $suffix = 'a'): array
    {
        $owner = User::factory()->create(['is_active' => true, 'email' => "pago.{$suffix}@cobros.test"]);
        $company = Company::create([
            'trade_name' => 'Pagos '.ucfirst($suffix),
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

    public function test_internal_confirmation_requires_platform_admin(): void
    {
        [$company, $payer] = $this->company('perm');
        $owner = User::factory()->create(['is_active' => true, 'email' => 'dueno@perm.test']);
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createIndividual(
            $company, 'cobrador_independiente', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        try {
            $this->payments->confirmInternal(
                $contract, $owner, 'tx-perm-1', '10000.0000',
                now()->year, now()->month,
            );
            $this->fail('Un dueño no debe confirmar pagos internamente.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, CobrosPaymentConfirmation::count());
        $this->assertSame(CobrosBillingContract::STATUSES[0], $contract->fresh()->status);
    }

    public function test_individual_confirmation_activates_contract_and_collector_license(): void
    {
        [$company, $payer] = $this->company('ind');
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createIndividual(
            $company, 'cobrador_independiente', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        $confirmation = $this->payments->confirmInternal(
            $contract, $admin, 'tx-ind-1', '10000.0000',
            now()->year, now()->month,
        );

        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $confirmation->status);
        $this->assertSame($contract->id, $confirmation->cobros_billing_contract_id);
        $this->assertSame($company->id, $confirmation->company_id);
        $this->assertSame('10000.0000', (string) $confirmation->amount);
        $this->assertSame('CRC', $confirmation->currency);
        $this->assertSame(now()->year, $confirmation->period_year);
        $this->assertSame(now()->month, $confirmation->period_month);
        $this->assertSame($admin->id, $confirmation->confirmed_by_user_id);

        // Contrato activo.
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);

        // Módulo cobros en modo individual.
        $module = $company->modules()->where('module_key', 'cobros')->first();
        $this->assertNotNull($module);
        $this->assertTrue($module->is_enabled);
        $this->assertSame('individual', $module->license_mode);

        // Licencia del cobrador responsable (payer) activa.
        $license = CobrosCollectorLicense::query()
            ->where('company_id', $company->id)
            ->where('user_id', $payer->id)
            ->first();
        $this->assertNotNull($license);
        $this->assertSame(CobrosCollectorLicense::STATUS_ACTIVE, $license->status);
        $this->assertNotNull($license->expires_at);
    }

    public function test_enterprise_confirmation_covers_seats_with_single_monthly_payment(): void
    {
        [$company, $payer] = $this->company('emp');
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createEnterprise(
            $company, 4, 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
            '35000.0000', 'Acuento empresarial autorizado.',
        );

        $confirmation = $this->payments->confirmInternal(
            $contract, $admin, 'tx-emp-1', '35000.0000',
            now()->year, now()->month,
        );

        $this->assertSame(CobrosPaymentConfirmation::STATUS_CONFIRMED, $confirmation->status);
        $this->assertSame('35000.0000', (string) $confirmation->amount);
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contract->fresh()->status);

        // Módulo cobros en modo cupo con límite = cupos contratados.
        $module = $company->modules()->where('module_key', 'cobros')->first();
        $this->assertSame('company_cupo', $module->license_mode);
        $this->assertSame(4, $module->collector_limit);

        // UN pago cubre todos los cupos: no se crean licencias individuales.
        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $company->id)->count());
    }

    public function test_confirmation_is_idempotent_by_transaction_id(): void
    {
        [$company, $payer] = $this->company('idem');
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createIndividual(
            $company, 'cobrador_independiente', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        $first = $this->payments->confirmInternal(
            $contract, $admin, 'tx-idem-1', '10000.0000',
            now()->year, now()->month,
        );

        // Reintento con la MISMA transacción: devuelve la misma, sin duplicar.
        $second = $this->payments->confirmInternal(
            $contract, $admin, 'tx-idem-1', '10000.0000',
            now()->year, now()->month,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CobrosPaymentConfirmation::query()->where('transaction_id', 'tx-idem-1')->count());

        // La licencia no se duplica (una sola fila por usuario+empresa).
        $this->assertSame(1, CobrosCollectorLicense::query()
            ->where('company_id', $company->id)
            ->where('user_id', $payer->id)
            ->count());
    }

    public function test_confirmation_validates_amount_period_and_duplicate(): void
    {
        [$company, $payer] = $this->company('val');
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createIndividual(
            $company, 'cobrador_independiente', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        // Importe que no coincide con el total vigente.
        try {
            $this->payments->confirmInternal(
                $contract, $admin, 'tx-val-1', '9999.0000',
                now()->year, now()->month,
            );
            $this->fail('Un importe distinto al total vigente debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no coincide', implode(' ', \Illuminate\Support\Arr::flatten($exception->errors())));
        }

        // Período fuera de la vigencia del contrato.
        try {
            $this->payments->confirmInternal(
                $contract, $admin, 'tx-val-2', '10000.0000',
                now()->year - 2, now()->month,
            );
            $this->fail('Un período fuera de vigencia debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('anterior al inicio', implode(' ', \Illuminate\Support\Arr::flatten($exception->errors())));
        }

        // Confirmación válida.
        $this->payments->confirmInternal(
            $contract, $admin, 'tx-val-3', '10000.0000',
            now()->year, now()->month,
        );

        // Mismo período con OTRA transacción: doble cargo bloqueado.
        try {
            $this->payments->confirmInternal(
                $contract, $admin, 'tx-val-4', '10000.0000',
                now()->year, now()->month,
            );
            $this->fail('Un segundo pago del mismo período debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ya tiene un pago confirmado', implode(' ', \Illuminate\Support\Arr::flatten($exception->errors())));
        }

        $this->assertSame(1, CobrosPaymentConfirmation::query()->where('cobros_billing_contract_id', $contract->id)->count());
    }

    public function test_confirmation_is_isolated_between_companies(): void
    {
        [$companyA, $payerA] = $this->company('iso1');
        [$companyB, $payerB] = $this->company('iso2');
        $admin = $this->platformAdmin();

        $contractA = $this->contracts->createIndividual(
            $companyA, 'cobrador_independiente', 'CR', $payerA,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );
        $contractB = $this->contracts->createIndividual(
            $companyB, 'cobrador_independiente', 'CR', $payerB,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        $this->payments->confirmInternal(
            $contractA, $admin, 'tx-iso-a', '10000.0000',
            now()->year, now()->month,
        );

        // A activo; B sigue en draft sin licencia.
        $this->assertSame(CobrosBillingContract::STATUS_ACTIVE, $contractA->fresh()->status);
        $this->assertSame(CobrosBillingContract::STATUSES[0], $contractB->fresh()->status);
        $this->assertSame(0, CobrosCollectorLicense::query()->where('company_id', $companyB->id)->count());

        // La confirmación de A quedó asociada solo a su empresa.
        $confirmation = CobrosPaymentConfirmation::query()->where('transaction_id', 'tx-iso-a')->first();
        $this->assertSame($companyA->id, $confirmation->company_id);
    }

    public function test_renewal_is_prepared_and_states_transition_without_deleting(): void
    {
        [$company, $payer] = $this->company('ren');
        $admin = $this->platformAdmin();

        $contract = $this->contracts->createIndividual(
            $company, 'cobrador_independiente', 'CR', $payer,
            now()->startOfMonth(), now()->addYear()->startOfMonth(), $admin,
        );

        $this->payments->confirmInternal(
            $contract, $admin, 'tx-ren-1', '10000.0000',
            now()->year, now()->month,
        );

        // Renovación preparada como pendiente (siguiente período).
        $renewal = $this->payments->prepareRenewal($contract, $admin);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_PENDING, $renewal->status);
        $this->assertSame(now()->addMonth()->year, $renewal->period_year);
        $this->assertSame(now()->addMonth()->month, $renewal->period_month);

        // Vencer la renovación pendiente sin borrar datos.
        $expired = $this->payments->expire($renewal);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_EXPIRED, $expired->status);
        $this->assertNotNull($expired->fresh());

        // Suspende la confirmación pagada sin borrar carteras ni licencias.
        $paid = CobrosPaymentConfirmation::query()->where('transaction_id', 'tx-ren-1')->first();
        $suspended = $this->payments->suspend($paid);
        $this->assertSame(CobrosPaymentConfirmation::STATUS_SUSPENDED, $suspended->status);

        // La licencia del cobrador NO se borró.
        $this->assertSame(1, CobrosCollectorLicense::query()
            ->where('company_id', $company->id)
            ->where('user_id', $payer->id)
            ->count());
    }
}
