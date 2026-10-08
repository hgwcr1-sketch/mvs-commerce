<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosLateFeeApplication;
use App\Models\CobrosReceipt;
use App\Models\Company;
use App\Models\User;
use App\Support\Cobros\Money;
use Illuminate\Validation\ValidationException;

/**
 * 9B.1/9B.2A - aplicación de cobros al calendario de cuotas de un préstamo.
 *
 * Solo actúa cuando la CxC corresponde a un préstamo CobrOS DISBURSED.
 * CxC normal de Commerce: devuelve null y no toca nada (compatibilidad).
 * Regla 9B.2A: el pago se distribuye en orden cronológico desde la cuota
 * abierta más antigua, hasta el total real de la deuda (pago adelantado
 * permitido). Nunca salta una cuota ni supera su total.
 */
final class CreditInstallmentService
{
    public function __construct(
        private readonly CreditLateFeeService $lateFees,
        private readonly LateFeeSplitService $split,
    ) {}

    /** Crédito desembolsado dueño de esta CxC (tenant explícito, sin sesión). */
    public function disbursedCreditFor(Company $company, AccountReceivable $account, bool $lock = false): ?CobrosCredit
    {
        $query = CobrosCredit::query()
            ->withoutGlobalScope('cobros_company')
            ->where('company_id', $company->id)
            ->where('account_receivable_id', $account->id)
            ->where('status', CobrosCredit::STATUS_DISBURSED);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** Cuota más antigua con pendiente (> 0), por número. */
    public function oldestOpenInstallment(CobrosCredit $credit, bool $lock = false): ?CobrosCreditInstallment
    {
        $query = $credit->installments()
            ->whereColumn('contractual_amount', '>', 'applied_amount');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('number')->first();
    }

    /** Pendiente exacto de una cuota (BCMath, decimal:4). */
    public function installmentPending(CobrosCreditInstallment $installment): string
    {
        $pending = bcsub((string) $installment->contractual_amount, (string) $installment->applied_amount, 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }

    /** Deuda total real restante del préstamo: suma de pendientes (BCMath, sin floats). */
    public function totalPending(CobrosCredit $credit): string
    {
        $total = '0.0000';
        foreach ($credit->installments()->orderBy('number')->get() as $installment) {
            $total = bcadd($total, $this->installmentPending($installment), 4);
        }

        return $total;
    }

    /** Mora ya cobrada (suma de snapshots inmutables) para una cuota. 9B.3. */
    public function lateFeePaid(CobrosCreditInstallment $installment): string
    {
        $paid = '0.0000';
        $rows = CobrosLateFeeApplication::query()
            ->withoutGlobalScope('cobros_company')
            ->where('installment_id', $installment->id)
            ->pluck('late_fee_charged');
        foreach ($rows as $row) {
            $paid = bcadd($paid, (string) $row, 4);
        }

        return $paid;
    }

    /**
     * Mora exigible todavía NO cobrada de la cuota abierta más antigua.
     * Determinista (la obligación no cambia tras vencer) y nunca negativa.
     * 0 para CxC sin préstamo o sin mora configurada/vencida. 9B.3.
     */
    public function pendingLateFee(Company $company, AccountReceivable $account): string
    {
        $credit = $this->disbursedCreditFor($company, $account);
        if ($credit === null) {
            return '0.0000';
        }

        $installment = $this->oldestOpenInstallment($credit);
        if ($installment === null) {
            return '0.0000';
        }

        return $this->lateFeePendingFor($credit, $installment);
    }

    /** Exigible (motor puro) menos lo ya cobrado en snapshots. */
    private function lateFeePendingFor(CobrosCredit $credit, CobrosCreditInstallment $installment): string
    {
        $result = $this->lateFees->evaluate($credit, $installment, today());
        if (bccomp($result['late_fee_due'], '0', 4) <= 0) {
            return '0.0000';
        }

        $pending = bcsub($result['late_fee_due'], $this->lateFeePaid($installment), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }

    /**
     * Información humana para la pantalla COBRAR. null = CxC sin préstamo.
     *
     * @return array{credit: CobrosCredit, reference: string, installment: ?CobrosCreditInstallment, pending: string, saldo: string}|null
     */
    public function contextFor(Company $company, AccountReceivable $account): ?array
    {
        $credit = $this->disbursedCreditFor($company, $account);
        if ($credit === null) {
            return null;
        }

        $installment = $this->oldestOpenInstallment($credit);

        return [
            'credit' => $credit,
            'reference' => $credit->publicReference(),
            'installment' => $installment,
            'pending' => $installment !== null ? $this->installmentPending($installment) : '0.0000',
            'saldo' => (string) $account->fresh()->balance_due,
        ];
    }

    /**
     * Vista previa de distribución para la confirmación (9B.2A/9B.3): la mora
     * exigible pendiente se cobra PRIMERO y el resto recorre las cuotas en
     * orden cronológico desde la más antigua. `saldo_after` solo refleja
     * capital (la mora nunca toca la CxC).
     *
     * @return array{credit: CobrosCredit, reference: string, late_fee: ?array{pending: string, applies: string}, lines: array<int, array{installment: CobrosCreditInstallment, number: int, applies: string, completes: bool, pending_after: string}>, applied_total: string, saldo_after: string}|null
     */
    public function planFor(Company $company, AccountReceivable $account, string $amount): ?array
    {
        $credit = $this->disbursedCreditFor($company, $account);
        if ($credit === null) {
            return null;
        }

        $moraPending = $this->pendingLateFee($company, $account);
        $moraApplies = bccomp($amount, $moraPending, 4) < 0 ? $amount : $moraPending;
        $remaining = bcsub($amount, $moraApplies, 4);

        $lines = [];
        foreach ($credit->installments()
            ->whereColumn('contractual_amount', '>', 'applied_amount')
            ->orderBy('number')->get() as $installment) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
            $pending = $this->installmentPending($installment);
            $use = bccomp($remaining, $pending, 4) < 0 ? $remaining : $pending;
            $after = bcsub($pending, $use, 4);
            $lines[] = [
                'installment' => $installment,
                'number' => (int) $installment->number,
                'applies' => $use,
                'completes' => bccomp($after, '0', 4) === 0,
                'pending_after' => $after,
            ];
            $remaining = bcsub($remaining, $use, 4);
        }

        if ($lines === [] && bccomp($moraApplies, '0', 4) <= 0) {
            return null;
        }

        $appliedTotal = '0.0000';
        foreach ($lines as $line) {
            $appliedTotal = bcadd($appliedTotal, $line['applies'], 4);
        }

        return [
            'credit' => $credit,
            'reference' => $credit->publicReference(),
            'late_fee' => bccomp($moraApplies, '0', 4) > 0
                ? ['pending' => $moraPending, 'applies' => $moraApplies]
                : null,
            'lines' => $lines,
            'applied_total' => $appliedTotal,
            'saldo_after' => bcsub((string) $account->fresh()->balance_due, $appliedTotal, 4),
        ];
    }

    /**
     * Bloqueo 9B.2A/9B.3: el pago no puede superar la deuda total real
     * restante del préstamo (cuotas) MÁS la mora exigible pendiente.
     * Adelantar cuotas SÍ está permitido.
     */
    public function assertChargeable(Company $company, AccountReceivable $account, string $amount): void
    {
        $credit = $this->disbursedCreditFor($company, $account);
        if ($credit === null) {
            return;
        }

        $oldest = $this->oldestOpenInstallment($credit);
        $moraPending = $oldest !== null ? $this->lateFeePendingFor($credit, $oldest) : '0.0000';
        $totalPending = bcadd($this->totalPending($credit), $moraPending, 4);
        if (bccomp($amount, $totalPending, 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Este monto supera la deuda total del préstamo ('.Money::human($totalPending, $account->currency_code).'). '
                    . 'Puede abonar hasta '.Money::human($totalPending, $account->currency_code).'.',
            ]);
        }
    }

    /**
     * Distribuye el pago entre cuotas en orden cronológico (9B.2A), desde la
     * abierta más antigua, sin saltar ninguna ni superar su total. SOLO dentro
     * de la transacción del cobro (CxC y cuotas con locks): cualquier error
     * lanza y todo el cobro se revierte (rollback total, idempotencia real).
     *
     * @return array<int, CobrosCreditInstallmentApplication>
     */
    public function apply(Company $company, AccountReceivable $account, string $amount, CobrosReceipt $receipt): array
    {
        $credit = $this->disbursedCreditFor($company, $account, lock: true);
        if ($credit === null) {
            return []; // CxC normal de Commerce: sin lógica de cuotas.
        }

        $totalPending = $this->totalPending($credit);
        if (bccomp($amount, $totalPending, 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Este monto supera la deuda total del préstamo ('.Money::human($totalPending, $account->currency_code).').',
            ]);
        }

        $created = [];
        $remaining = $amount;
        $open = $credit->installments()
            ->whereColumn('contractual_amount', '>', 'applied_amount')
            ->orderBy('number')
            ->lockForUpdate()
            ->get();

        foreach ($open as $installment) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            $pending = $this->installmentPending($installment);
            $use = bccomp($remaining, $pending, 4) < 0 ? $remaining : $pending;
            $applied = bcadd((string) $installment->applied_amount, $use, 4);
            $completed = bccomp($applied, (string) $installment->contractual_amount, 4) >= 0;

            $installment->forceFill([
                'applied_amount' => $completed ? (string) $installment->contractual_amount : $applied,
                'status' => $completed ? CobrosCreditInstallment::STATUS_PAID : CobrosCreditInstallment::STATUS_PARTIAL,
                'paid_at' => $completed ? ($installment->paid_at ?? now()) : $installment->paid_at,
            ])->save();

            $created[] = CobrosCreditInstallmentApplication::create([
                'company_id' => $company->id,
                'credit_id' => $credit->id,
                'installment_id' => $installment->id,
                'account_receivable_id' => $account->id,
                'cobros_receipt_id' => $receipt->id,
                'amount' => $use,
            ]);

            $remaining = bcsub($remaining, $use, 4);
        }

        if (bccomp($remaining, '0', 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Este monto supera la deuda total del préstamo.',
            ]);
        }

        return $created;
    }

    /**
     * 9B.3 - cobra la mora exigible PRIMERO (antes de las cuotas), sobre la
     * cuota abierta más antigua, dentro de la MISMA transacción del cobro.
     * Snapshot inmutable: % efectivo vigente en este cobro, reparto
     * cobrador/prestamista y trazabilidad. Nunca toca capital ni CxC.
     *
     * @return CobrosLateFeeApplication
     */
    public function applyLateFee(Company $company, AccountReceivable $account, CobrosReceipt $receipt, User $collector, string $amount): CobrosLateFeeApplication
    {
        $credit = $this->disbursedCreditFor($company, $account, lock: true);
        $installment = $credit !== null ? $this->oldestOpenInstallment($credit, lock: true) : null;
        if ($credit === null || $installment === null) {
            throw ValidationException::withMessages(['amount' => 'No hay una cuota abierta para aplicar la mora.']);
        }

        $pending = $this->lateFeePendingFor($credit, $installment);
        if (bccomp($amount, $pending, 4) > 0) {
            throw ValidationException::withMessages(['amount' => 'La mora pendiente cambió durante el cobro; intente de nuevo.']);
        }

        $pct = $this->split->effectivePercentage($company, $collector);
        $snapshot = $this->split->snapshotFor($amount, $pct);

        return CobrosLateFeeApplication::create([
            'company_id' => $company->id,
            'credit_id' => $credit->id,
            'installment_id' => $installment->id,
            'account_receivable_id' => $account->id,
            'cobros_receipt_id' => $receipt->id,
            'collector_user_id' => $collector->id,
            'late_fee_charged' => $snapshot['late_fee_charged'],
            'effective_pct' => $snapshot['effective_pct'],
            'collector_amount' => $snapshot['collector_amount'],
            'lender_amount' => $snapshot['lender_amount'],
            'paid_at' => now(),
        ]);
    }

    /**
     * Resumen para el recibo: TODAS las aplicaciones del recibo (una fila por
     * cuota afectada), mora cobrada (9B.3), saldo del préstamo y próximo
     * compromiso contractual. Un recibo solo de mora también genera bloque.
     *
     * @return array{reference: string, number: int, amount: string, mora: string, pending_after: string, lines: array<int, array{number: int, amount: string, pending_after: string, completes: bool}>, saldo: string, cancelled: bool, next_due: ?string, next_amount: ?string}|null
     */
    public function applicationForReceipt(Company $company, CobrosReceipt $receipt): ?array
    {
        $applications = CobrosCreditInstallmentApplication::query()
            ->withoutGlobalScope('cobros_company')
            ->where('company_id', $company->id)
            ->where('cobros_receipt_id', $receipt->id)
            ->with('installment')
            ->orderBy('installment_id')
            ->get();
        $lateFees = CobrosLateFeeApplication::query()
            ->withoutGlobalScope('cobros_company')
            ->where('company_id', $company->id)
            ->where('cobros_receipt_id', $receipt->id)
            ->get();
        if ($applications->isEmpty() && $lateFees->isEmpty()) {
            return null;
        }

        $credit = CobrosCredit::query()
            ->withoutGlobalScope('cobros_company')
            ->where('company_id', $company->id)
            ->whereKey($applications->first()?->credit_id ?? $lateFees->first()->credit_id)
            ->first();
        if ($credit === null) {
            return null;
        }

        $lines = [];
        foreach ($applications as $application) {
            $installment = $application->installment;
            $pendingAfter = bcsub((string) $installment->contractual_amount, (string) $installment->applied_amount, 4);
            $pendingAfter = bccomp($pendingAfter, '0', 4) > 0 ? $pendingAfter : '0.0000';
            $lines[] = [
                'number' => (int) $installment->number,
                'amount' => (string) $application->amount,
                'pending_after' => $pendingAfter,
                'completes' => bccomp($pendingAfter, '0', 4) === 0,
            ];
        }

        $mora = '0.0000';
        foreach ($lateFees as $lateFee) {
            $mora = bcadd($mora, (string) $lateFee->late_fee_charged, 4);
        }

        // Próximo compromiso del calendario contractual (sin recalcular nada):
        // primera cuota que todavía tenga saldo, con SU fecha original.
        $next = $credit->installments()
            ->whereColumn('contractual_amount', '>', 'applied_amount')
            ->orderBy('number')->first();
        $nextPending = $next !== null
            ? bcsub((string) $next->contractual_amount, (string) $next->applied_amount, 4)
            : null;

        $appliedByReceipt = '0.0000';
        foreach ($applications as $application) {
            $appliedByReceipt = bcadd($appliedByReceipt, (string) $application->amount, 4);
        }
        $accountId = $applications->first()?->account_receivable_id ?? $lateFees->first()->account_receivable_id;

        return [
            'reference' => $credit->publicReference(),
            'number' => $lines[0]['number'] ?? (int) ($next?->number ?? 0),
            'amount' => $appliedByReceipt,
            'mora' => $mora,
            'pending_after' => $lines[0]['pending_after'] ?? ($nextPending ?? '0.0000'),
            'lines' => $lines,
            'saldo' => (string) AccountReceivable::query()->forCompany($company->id)->whereKey($accountId)->value('balance_due'),
            'cancelled' => $next === null,
            'next_due' => $next?->due_date?->format('d/m/Y'),
            'next_amount' => $nextPending,
        ];
    }
}
