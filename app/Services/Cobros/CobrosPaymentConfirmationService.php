<?php

namespace App\Services\Cobros;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 17A-2 f1 — confirmación de pago CobrOS (independiente del proveedor).
 *
 * Reutiliza CobrosBillingContractService (total vigente + overrides),
 * CompanyLicenseService (contrato de licencia) y licenciamiento individual
 * (CollectorLicenseService). NO integra Stripe/SINPE ni correos/UI todavía.
 *
 * Reglas:
 *  - Acepta confirmaciones SOLO desde un mecanismo interno autorizado
 *    (plataforma autenticada). Nunca desde formularios públicos ni
 *    parámetros de retorno de pago.
 *  - Activación idempotente: un pago válido activa UNA vez el contrato.
 *  - Individual => activa SOLO la licencia del cobrador responsable.
 *  - Empresarial => cubre los cupos con UN único pago mensual del prestamista.
 *  - Valida precio vigente (con override), moneda, período, estado
 *    contractual y aislamiento entre empresas.
 *  - Renovación mensual + estados pendiente/pagado/vencido/suspendido sin
 *    borrar datos.
 */
class CobrosPaymentConfirmationService
{
    public function __construct(
        private readonly CobrosBillingContractService $contracts,
        private readonly CompanyLicenseService $licenseService,
        private readonly CollectorLicenseService $collectorLicenses,
    ) {
    }

    /**
     * Confirma un pago interno y activa el contrato de forma idempotente.
     *
     * Mecanismo INTERNO autorizado: requiere actor plataforma autenticado.
     * Stripe/SINPE se conectarán en 17B sobre esta misma entrada.
     */
    public function confirmInternal(
        CobrosBillingContract $contract,
        User $actor,
        string $transactionId,
        string $amount,
        int $periodYear,
        int $periodMonth,
        ?string $notes = null,
    ): CobrosPaymentConfirmation {
        abort_unless($actor->is_active && $actor->isPlatformAdmin(), 403,
            'La confirmación de pago solo puede realizarse desde el mecanismo interno autorizado (plataforma).');

        return $this->confirm($contract, $actor, $transactionId, $amount, $periodYear, $periodMonth, 'internal', $notes);
    }

    /**
     * Núcleo de confirmación (común a internal/stripe/sinpe). Idempotente
     * por transaction_id: reintentos y reintentos de webhook no duplican
     * activación ni crean otra confirmación.
     *
     * La confirmación de pago se crea SIEMPRE (si pasa validaciones).
     * La activación se intenta aparte: si falla, la confirmación queda
     * CONFIRMADA y recuperable para reintento (no se revierte).
     */
    public function confirm(
        CobrosBillingContract $contract,
        User $actor,
        string $transactionId,
        string $amount,
        int $periodYear,
        int $periodMonth,
        string $provider = 'internal',
        ?string $notes = null,
    ): CobrosPaymentConfirmation {
        $transactionId = trim($transactionId);
        $this->assertTransactionId($transactionId);
        $this->assertValidPeriod($periodYear, $periodMonth);

        // 1) Crear/obtener confirmación de pago (transacción corta)
        $confirmation = DB::transaction(function () use ($contract, $actor, $transactionId, $amount, $periodYear, $periodMonth, $provider, $notes) {
            $existing = CobrosPaymentConfirmation::query()
                ->where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->assertContractOpen($contract);
            $this->assertAmountMatches($contract, $amount);
            $this->assertPeriodWithinTerm($contract, $periodYear, $periodMonth);
            $this->assertNoDuplicatePeriod($contract, $periodYear, $periodMonth);

            return CobrosPaymentConfirmation::create([
                'transaction_id' => $transactionId,
                'cobros_billing_contract_id' => $contract->id,
                'company_id' => $contract->company_id,
                'period_year' => $periodYear,
                'period_month' => $periodMonth,
                'amount' => $this->normalizeAmount($amount),
                'currency' => $contract->currency,
                'status' => CobrosPaymentConfirmation::STATUS_CONFIRMED,
                'provider' => $provider,
                'confirmed_by_user_id' => $actor->id,
                'confirmed_at' => now(),
                'notes' => $notes,
            ]);
        });

        // 2) Intentar activación (fuera de la transacción de confirmación)
        //    Si falla, la confirmación NO se revierte; queda para reintento.
        try {
            $this->activateContract($contract, $actor, $periodYear, $periodMonth, $provider);
        } catch (ValidationException $e) {
            // Log y continuar: la confirmación queda CONFIRMADA y recuperable
            \Illuminate\Support\Facades\Log::warning('Activación fallida tras pago confirmado; se reintentará', [
                'transaction_id' => $transactionId,
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $confirmation;
    }

    /** Renovación mensual: registra el período siguiente como pendiente. */
    public function prepareRenewal(CobrosBillingContract $contract, User $actor): CobrosPaymentConfirmation
    {
        if (! $actor->isPlatformAdmin()) {
            abort(403, 'Se requiere administrador de plataforma para preparar la renovación.');
        }

        $this->assertContractOpen($contract);

        [$year, $month] = $this->nextPeriod($contract);

        return CobrosPaymentConfirmation::create([
            'transaction_id' => 'renewal-'.$contract->id.'-'.sprintf('%04d%02d', $year, $month).'-'.uniqid(),
            'cobros_billing_contract_id' => $contract->id,
            'company_id' => $contract->company_id,
            'period_year' => $year,
            'period_month' => $month,
            'amount' => $this->contracts->monthlyTotal($contract),
            'currency' => $contract->currency,
            'status' => CobrosPaymentConfirmation::STATUS_PENDING,
            'provider' => 'internal',
            'confirmed_by_user_id' => $actor->id,
            'confirmed_at' => null,
            'notes' => 'Renovación mensual preparada; pendiente de pago.',
        ]);
    }

    /** Vence una confirmación pendiente (sin borrar datos). */
    public function expire(CobrosPaymentConfirmation $confirmation): CobrosPaymentConfirmation
    {
        if ($confirmation->status === CobrosPaymentConfirmation::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => 'Una confirmación pagada no se vence; use la suspensión si corresponde.',
            ]);
        }

        $confirmation->update(['status' => CobrosPaymentConfirmation::STATUS_EXPIRED]);

        return $confirmation->refresh();
    }

    /** Suspende una confirmación pagada (sin borrar carteras ni datos). */
    public function suspend(CobrosPaymentConfirmation $confirmation): CobrosPaymentConfirmation
    {
        if ($confirmation->status !== CobrosPaymentConfirmation::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => 'Solo se suspende una confirmación ya pagada.',
            ]);
        }

        $confirmation->update(['status' => CobrosPaymentConfirmation::STATUS_SUSPENDED]);

        return $confirmation->refresh();
    }

    /**
     * Activación idempotente del contrato tras un pago válido:
     *  - Activa el contrato (draft -> active).
     *  - Individual: habilita licencias individuales y activa SOLO la licencia
     *    del cobrador responsable (payer).
     *  - Empresarial: habilita cupo de empresa con límite = cupos contratados
     *    (UN pago mensual del prestamista cubre todos los cupos).
     */
    private function activateContract(CobrosBillingContract $contract, User $actor, int $periodYear, int $periodMonth, string $provider = 'internal'): void
    {
        $company = Company::query()->findOrFail($contract->company_id);

        $config = [
            'license_mode' => $contract->billing_mode === CobrosBillingContract::MODE_INDIVIDUAL ? 'individual' : 'company_cupo',
            'collector_limit' => $contract->billing_mode === CobrosBillingContract::MODE_ENTERPRISE ? (int) $contract->seats_total : null,
            'admin_license_charged' => (bool) $contract->admin_included,
        ];

        // Activación automática (webhook Stripe/SINPE): aplica configuración sin requerir platform admin.
        // Activación manual (internal): usa updateCobrosContract que valida y requiere platform admin.
        if ($provider === 'internal') {
            $this->licenseService->updateCobrosContract($company, $actor, $config);
        } else {
            $this->licenseService->applyCobrosContractConfiguration($company, $config);
        }

        if ($contract->billing_mode === CobrosBillingContract::MODE_INDIVIDUAL) {
            $payer = $contract->payer;
            if ($payer === null) {
                throw ValidationException::withMessages(['payer' => 'El contrato individual no tiene responsable de pago válido.']);
            }

            $periodEnd = $this->periodEnd($periodYear, $periodMonth);
            $this->collectorLicenses->renew($payer, $company, $periodEnd);
        }

        if ($contract->status !== CobrosBillingContract::STATUS_ACTIVE) {
            $contract->update(['status' => CobrosBillingContract::STATUS_ACTIVE]);
        }
    }

    private function assertContractOpen(CobrosBillingContract $contract): void
    {
        if (! $contract->isOpen()) {
            throw ValidationException::withMessages([
                'contract' => 'El contrato no está abierto (draft/active); no puede recibir pagos.',
            ]);
        }
    }

    private function assertAmountMatches(CobrosBillingContract $contract, string $amount): void
    {
        $expected = $this->contracts->monthlyTotal($contract);

        if (bccomp($this->normalizeAmount($amount), $expected, 4) !== 0) {
            throw ValidationException::withMessages([
                'amount' => "El importe del pago ({$amount}) no coincide con el total vigente del contrato ({$expected}).",
            ]);
        }
    }

    private function assertPeriodWithinTerm(CobrosBillingContract $contract, int $year, int $month): void
    {
        $start = $this->periodStart($year, $month);
        $validFrom = $contract->valid_from?->startOfDay();
        $validUntil = $contract->valid_until?->endOfDay();

        if ($validFrom !== null && $start->lt($validFrom)) {
            throw ValidationException::withMessages([
                'period' => 'El período es anterior al inicio de vigencia del contrato.',
            ]);
        }

        if ($validUntil !== null && $start->gt($validUntil)) {
            throw ValidationException::withMessages([
                'period' => 'El período es posterior al fin de vigencia del contrato.',
            ]);
        }
    }

    private function assertNoDuplicatePeriod(CobrosBillingContract $contract, int $year, int $month): void
    {
        $duplicate = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', CobrosPaymentConfirmation::STATUS_CONFIRMED)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'period' => 'Este período ya tiene un pago confirmado para este contrato.',
            ]);
        }
    }

    private function assertTransactionId(string $transactionId): void
    {
        if ($transactionId === '' || mb_strlen($transactionId) > 120) {
            throw ValidationException::withMessages([
                'transaction_id' => 'El identificador de transacción es obligatorio (máx. 120 caracteres).',
            ]);
        }
    }

    private function assertValidPeriod(int $year, int $month): void
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages([
                'period' => 'Período de facturación inválido.',
            ]);
        }
    }

    /** @return array{0:int,1:int} siguiente período [año, mes] del contrato */
    private function nextPeriod(CobrosBillingContract $contract): array
    {
        $last = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        if ($last === null) {
            $start = $contract->valid_from?->startOfMonth() ?? now()->startOfMonth();

            return [$start->year, $start->month];
        }

        $next = CarbonImmutable::create($last->period_year, $last->period_month, 1)->addMonth();

        return [$next->year, $next->month];
    }

    private function periodStart(int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1)->startOfMonth();
    }

    private function periodEnd(int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1)->endOfMonth();
    }

    private function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);

        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $amount, $parts) !== 1) {
            throw ValidationException::withMessages(['amount' => 'El importe debe ser un valor positivo válido.']);
        }

        return $parts[1].'.'.str_pad($parts[2] ?? '', 4, '0');
    }
}
