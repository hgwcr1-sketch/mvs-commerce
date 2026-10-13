<?php

namespace App\Services\Cobros;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private readonly ReferralBonusService $referralBonuses,
        private readonly ReferralCreditDiscountService $referralCredits,
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
                // El flujo interno de confirmación puede reenviar una solicitud
                // preexistente (ej. renovación preparada con proveedor interno).
                if ($existing->status !== CobrosPaymentConfirmation::STATUS_CONFIRMED) {
                    $existing->update([
                        'status' => CobrosPaymentConfirmation::STATUS_CONFIRMED,
                        'confirmed_by_user_id' => $actor->id,
                        'confirmed_at' => now(),
                    ]);
                }

                return $existing;
            }

            $this->assertContractOpen($contract);
            $this->assertAmountMatches($contract, $amount, $periodYear, $periodMonth);
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
        $activated = true;
        try {
            $this->activateContract($contract, $actor, $periodYear, $periodMonth, $provider);
        } catch (ValidationException $e) {
            $activated = false;
            // Log y continuar: la confirmación queda CONFIRMADA y recuperable
            \Illuminate\Support\Facades\Log::warning('Activación fallida tras pago confirmado; se reintentará', [
                'transaction_id' => $transactionId,
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }

        // PRE-19B-4B: consumo idempotente de reservas de saldo a favor SOLO
        // tras activación legítima; ante fallo el saldo permanece reservado.
        if ($activated) {
            $this->referralCredits->settleReservations($contract, $periodYear, $periodMonth);
        }

        // PRE-19B-3: emisión idempotente de bonificaciones del programa
        // (referido comercial / crecimiento) tras el primer pago confirmado.
        $this->referralBonuses->onConfirmedPayment($confirmation->fresh());

        return $confirmation;
    }

    /**
     * PRE-19B-4B — liquidación interna AUTORIZADA de una mensualidad cubierta
     * íntegramente por saldo a favor (neto ₡0). NO genera cargo externo ni
     * solicita transferencia: confirma una liquidación de ₡0 con referencia
     * estable, activa por la vía de sistema (sin exigir platform admin al
     * pagador) y consume las reservas una sola vez. Idempotente por
     * transaction_id (reintentos devuelven la misma confirmación).
     */
    public function liquidateCreditInternal(
        CobrosBillingContract $contract,
        User $payer,
        int $periodYear,
        int $periodMonth,
    ): CobrosPaymentConfirmation {
        abort_unless(
            $payer->is_active && (int) $contract->payer_user_id === (int) $payer->id,
            403,
            'Solo el pagador del contrato puede liquidar con saldo a favor.'
        );

        $net = $this->referralCredits->netAmountForPeriod($contract, $periodYear, $periodMonth);
        if (bccomp($net, '0', 4) !== 0) {
            throw ValidationException::withMessages([
                'credit' => 'La mensualidad no está cubierta íntegramente por saldo a favor.',
            ]);
        }

        // Provider 'credit' => activación por configuración de sistema
        // (misma vía que webhook), sin exigir platform admin al pagador.
        return $this->confirm(
            $contract,
            $payer,
            sprintf('CRED-%d-%04d%02d', $contract->id, $periodYear, $periodMonth),
            $net,
            $periodYear,
            $periodMonth,
            'credit',
            'Liquidación interna con saldo a favor de referidos.',
        );
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

    /**
     * 17B-5A — Registra una solicitud SINPE pendiente para el período vigente.
     *
     * Solo PENDIENTE: nunca confirma, nunca activa licencias (la aprobación
     * posterior es un proceso aparte, verificado manualmente). El importe, la
     * moneda y el estado salen del contrato y del servicio; el navegador no
     * puede definirlos. Idempotente por contrato/período: repetir la solicitud
     * devuelve la misma referencia y no duplica filas.
     *
     * La referencia única se persiste con restricción de unicidad en BD
     * (transaction_id + contrato/período) y no depende solo de COB-{id}-{YYYYMM}.
     */
    public function requestSinpe(
        CobrosBillingContract $contract,
        User $payer,
        ?string $notes = null,
    ): CobrosPaymentConfirmation {
        abort_unless(
            $payer->is_active && (int) $contract->payer_user_id === (int) $payer->id,
            403,
            'Solo el pagador del contrato puede registrar comprobantes SINPE.'
        );

        $year = now()->year;
        $month = now()->month;

        return DB::transaction(function () use ($contract, $payer, $notes, $year, $month) {
            // Idempotencia: una sola solicitud por contrato/período.
            $existing = CobrosPaymentConfirmation::query()
                ->where('cobros_billing_contract_id', $contract->id)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->assertContractOpen($contract);
            $this->assertPeriodWithinTerm($contract, $year, $month);

            // Referencia única persistida con reintento ante colisión de índice.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    return CobrosPaymentConfirmation::create([
                        'transaction_id' => sprintf(
                            'SINPE-%d-%04d%02d-%s',
                            $contract->id,
                            $year,
                            $month,
                            strtoupper(Str::random(8))
                        ),
                        'cobros_billing_contract_id' => $contract->id,
                        'company_id' => $contract->company_id,
                        'period_year' => $year,
                        'period_month' => $month,
                        // PRE-19B-4B: importe neto server-side (total menos
                        // saldo a favor reservado/liquidado del período).
                        'amount' => $this->referralCredits->netAmountForPeriod($contract, $year, $month),
                        'currency' => $contract->currency,
                        'status' => CobrosPaymentConfirmation::STATUS_PENDING,
                        'provider' => 'sinpe',
                        'confirmed_by_user_id' => null,
                        'confirmed_at' => null,
                        'notes' => $notes,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Colisión de índice único: reintentar con otra referencia.
                }
            }

            throw ValidationException::withMessages([
                'reference' => 'No fue posible generar una referencia de pago única; intente nuevamente.',
            ]);
        });
    }

    /**
     * 17B-5B — Aprobación manual SINPE tras verificación humana externa.
     *
     * Autorización explícita de Panel Maestro: requiere identidad de
     * platform admin (is_platform_admin); NUNCA se infiere de permisos
     * ordinarios CobrOS del tenant. Sin endpoint público: entrada de servicio
     * interno para futura integración con el Panel Maestro existente.
     *
     * Solo solicitudes provider=sinpe en estado pending. Valida empresa,
     * contrato, pagador, importe, moneda y período; registra actor y marca
     * temporal. La activación reutiliza activateContract() (idempotente;
     * no duplica licencias). Doble aprobación es idempotente.
     */
    public function approveSinpe(
        CobrosPaymentConfirmation $confirmation,
        User $approver,
        ?string $notes = null,
    ): CobrosPaymentConfirmation {
        abort_unless(
            $approver->isPlatformAdmin(),
            403,
            'La aprobación manual SINPE requiere autorización explícita de Panel Maestro.'
        );

        if ($confirmation->provider !== 'sinpe') {
            $this->assertSinpePending($confirmation); // lanza el error de proveedor
        }

        if ($confirmation->status === CobrosPaymentConfirmation::STATUS_CONFIRMED) {
            // Doble aprobación: idempotente, sin nueva marca ni reactivación.
            return $confirmation;
        }

        $this->assertSinpePending($confirmation);

        $approvedNow = false;
        $confirmation = DB::transaction(function () use ($confirmation, $approver, $notes, &$approvedNow) {
            $fresh = CobrosPaymentConfirmation::query()
                ->lockForUpdate()
                ->findOrFail($confirmation->getKey());

            if ($fresh->status === CobrosPaymentConfirmation::STATUS_CONFIRMED) {
                // Doble aprobación: idempotente, sin nueva marca ni reactivación.
                return $fresh;
            }

            $this->assertSinpePending($fresh);
            $contract = $this->assertConfirmationMatchesContract($fresh);
            $this->assertContractOpen($contract);
            $this->assertAmountMatches($contract, (string) $fresh->amount, $fresh->period_year, $fresh->period_month);
            $this->assertPeriodWithinTerm($contract, $fresh->period_year, $fresh->period_month);

            $fresh->update([
                'status' => CobrosPaymentConfirmation::STATUS_CONFIRMED,
                'confirmed_by_user_id' => $approver->id,
                'confirmed_at' => now(),
                'notes' => $this->mergeNote($fresh->notes, $notes ?? 'Aprobación manual SINPE tras verificación humana.'),
            ]);

            $approvedNow = true;

            return $fresh;
        });

        if ($approvedNow) {
            // Activación fuera de la transacción de decisión (patrón confirm()).
            $activated = true;
            try {
                $this->activateContract(
                    $confirmation->contract,
                    $approver,
                    $confirmation->period_year,
                    $confirmation->period_month,
                    'sinpe'
                );
            } catch (ValidationException $e) {
                $activated = false;
                \Illuminate\Support\Facades\Log::warning('Activación fallida tras aprobación manual SINPE; se reintentará', [
                    'transaction_id' => $confirmation->transaction_id,
                    'contract_id' => $confirmation->cobros_billing_contract_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // PRE-19B-4B: consumo idempotente de reservas de saldo a favor SOLO
            // tras activación legítima; ante fallo el saldo permanece reservado.
            if ($activated) {
                $this->referralCredits->settleReservations(
                    $confirmation->contract,
                    $confirmation->period_year,
                    $confirmation->period_month,
                );
            }

            // PRE-19B-3: emisión idempotente de bonificaciones del programa
            // (referido comercial / crecimiento) tras el primer pago confirmado.
            $this->referralBonuses->onConfirmedPayment($confirmation);
        }

        return $confirmation;
    }

    /**
     * 17B-5B — Rechazo auditable de una solicitud SINPE pendiente.
     *
     * Nunca borra la solicitud ni activa licencias. Registra actor, marca
     * temporal y motivo (notes). Misma autorización explícita de Panel Maestro
     * que la aprobación; sin endpoint público.
     */
    public function rejectSinpe(
        CobrosPaymentConfirmation $confirmation,
        User $actor,
        ?string $notes = null,
    ): CobrosPaymentConfirmation {
        abort_unless(
            $actor->isPlatformAdmin(),
            403,
            'El rechazo manual SINPE requiere autorización explícita de Panel Maestro.'
        );

        $this->assertSinpePending($confirmation);

        return DB::transaction(function () use ($confirmation, $actor, $notes) {
            $fresh = CobrosPaymentConfirmation::query()
                ->lockForUpdate()
                ->findOrFail($confirmation->getKey());

            $this->assertSinpePending($fresh);

            $fresh->update([
                'status' => CobrosPaymentConfirmation::STATUS_REJECTED,
                'confirmed_by_user_id' => $actor->id,
                'confirmed_at' => now(),
                'notes' => $this->mergeNote($fresh->notes, $notes ?? 'Rechazo manual SINPE tras verificación humana.'),
            ]);

            return $fresh;
        });
    }

    /**
     * 17B-6A — Renovación mensual derivada de un pago confirmado legítimo:
     * Stripe (webhook verificado) o SINPE aprobado por Panel Maestro. Nunca
     * automática ni a petición del navegador.
     *
     * Idempotente por contrato/período: la única fila de confirmación por
     * período (índice único) y activateContract() — renew() actualiza la
     * licencia existente y applyCobrosContractConfiguration usa
     * updateOrCreate — impiden duplicar licencias o cobrar dos veces tarifas
     * consolidadas (prestamista-cobrador).
     */
    public function applyRenewal(CobrosPaymentConfirmation $confirmation): CobrosBillingContract
    {
        if ($confirmation->status !== CobrosPaymentConfirmation::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => 'La renovación solo se aplica tras un pago confirmado legítimamente.',
            ]);
        }

        if (! in_array($confirmation->provider, ['stripe', 'sinpe'], true)) {
            throw ValidationException::withMessages([
                'provider' => 'La renovación requiere un pago Stripe verificado o un SINPE aprobado por Panel Maestro.',
            ]);
        }

        $contract = $confirmation->contract;

        try {
            $this->activateContract(
                $contract,
                $confirmation->confirmedBy ?? $contract->payer,
                $confirmation->period_year,
                $confirmation->period_month,
                $confirmation->provider
            );
        } catch (ValidationException $e) {
            \Illuminate\Support\Facades\Log::warning('Renovación fallida tras pago confirmado; se reintentará', [
                'transaction_id' => $confirmation->transaction_id,
                'contract_id' => $confirmation->cobros_billing_contract_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $contract->refresh();
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

    /**
     * PRE-19B-4B: el importe esperado es el NETO del período (total vigente
     * menos saldo a favor reservado/liquidado). Sin reservas el neto coincide
     * con la cotización mensual, por lo que el comportamiento canónico sin
     * programa de referidos no cambia.
     */
    private function assertAmountMatches(CobrosBillingContract $contract, string $amount, int $periodYear, int $periodMonth): void
    {
        $expected = $this->referralCredits->netAmountForPeriod($contract, $periodYear, $periodMonth);

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

    /** 17B-5B: solo solicitudes SINPE pendientes admiten aprobación/rechazo. */
    private function assertSinpePending(CobrosPaymentConfirmation $confirmation): void
    {
        if ($confirmation->provider !== 'sinpe') {
            throw ValidationException::withMessages([
                'provider' => 'Solo se procesan solicitudes de pago SINPE.',
            ]);
        }

        if ($confirmation->status !== CobrosPaymentConfirmation::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'La solicitud SINPE no está pendiente de verificación.',
            ]);
        }
    }

    /** 17B-5B: coherencia empresa/contrato/pagador/moneda antes de decidir. */
    private function assertConfirmationMatchesContract(CobrosPaymentConfirmation $confirmation): CobrosBillingContract
    {
        $contract = $confirmation->contract;

        if ($contract === null) {
            throw ValidationException::withMessages([
                'contract' => 'La solicitud SINPE no tiene un contrato válido asociado.',
            ]);
        }

        if ((int) $confirmation->company_id !== (int) $contract->company_id) {
            throw ValidationException::withMessages([
                'company' => 'La empresa de la solicitud no coincide con la del contrato.',
            ]);
        }

        if ($confirmation->currency !== $contract->currency) {
            throw ValidationException::withMessages([
                'currency' => 'La moneda de la solicitud no coincide con la del contrato.',
            ]);
        }

        if ($contract->payer === null) {
            throw ValidationException::withMessages([
                'payer' => 'El contrato no tiene un pagador válido asociado.',
            ]);
        }

        return $contract;
    }

    /** 17B-5B: acumula motivos sin exceder la columna notes (500). */
    private function mergeNote(?string $existing, string $note): string
    {
        return mb_substr(trim(($existing ?? '').' '.$note), 0, 500);
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
