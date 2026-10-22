<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * REN-03B1 — Cotización de renovación de crédito (solo lectura, cero escrituras).
 *
 * Reglas de negocio:
 *  - Deuda pendiente anterior separada en capital e intereses (prorrateo
 *    capital/total del crédito; la mora queda excluida, nunca se capitaliza).
 *  - Intereses nuevos calculados EXCLUSIVAMENTE sobre el capital nuevo.
 *  - Total consolidado = deuda pendiente + capital nuevo + intereses nuevos.
 *  - Un solo calendario propuesto; el desembolso real es solo el capital nuevo.
 *  - La deuda anterior NUNCA se registra como pago en efectivo.
 */
final class CreditRenewalQuoteService
{
    public function __construct(
        private readonly CreditInstallmentService $installments,
        private readonly InstallmentScheduleService $schedule,
    ) {}

    /**
     * @return array{
     *   credit: CobrosCredit,
     *   previous: array{principal: string, interest: string, total: string},
     *   new_money: array{principal: string, interest: string, total: string},
     *   consolidated_total: string,
     *   disbursement: string,
     *   late_fee_excluded: string,
     *   schedule: array{installment_count: int, frequency: string, first_due_date: string, rows: array}
     * }
     */
    public function quote(
        User $actor,
        Company $company,
        AccountReceivable $account,
        string $newCapital,
        int $installmentCount,
        string $frequency,
        string $firstDueDate,
        string $modality = 'difference',
    ): array {
        if (! AccountReceivable::query()->forCompany($company->id)->whereKey($account->id)->exists()) {
            abort(404, 'Cuenta por cobrar no encontrada en esta empresa.');
        }

        $credit = $this->installments->disbursedCreditFor($company, $account);
        if ($credit === null) {
            throw ValidationException::withMessages(['account' => 'La cuenta no tiene un crédito desembolsado para renovar.']);
        }

        $this->authorize($actor, $company, $credit);

        $pendingTotal = $this->installments->totalPending($credit);
        if (bccomp($pendingTotal, '0', 4) <= 0) {
            throw ValidationException::withMessages(['account' => 'El crédito no tiene deuda pendiente que consolidar.']);
        }

        if (!in_array($modality, ['full', 'difference', 'no_disbursement', 'partial_payment'], true)) {
            throw ValidationException::withMessages(['modality' => 'Modalidad de renovación no válida.']);
        }
        if ($modality === 'partial_payment' && bccomp($pendingTotal, '0', 4) <= 0) {
            throw ValidationException::withMessages(['account' => 'El saldo actualizado no permite renovar.']);
        }
        if ($modality === 'no_disbursement') {
            $newCapital = '0.0000';
        } elseif ($modality === 'full') {
            if (bccomp($newCapital, $pendingTotal, 4) < 0) {
                throw ValidationException::withMessages(['new_capital' => 'El monto total debe cubrir la deuda pendiente.']);
            }
            $newCapital = bcsub($newCapital, $pendingTotal, 4);
        }

        try {
            $newCapital = $this->schedule->money($newCapital);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['new_capital' => 'Monto no válido.']);
        }
        if (bccomp($newCapital, '0', 4) <= 0 && !in_array($modality, ['full', 'no_disbursement', 'partial_payment'], true)) {
            throw ValidationException::withMessages(['new_capital' => 'El capital nuevo debe ser mayor que cero.']);
        }
        if ($installmentCount < 1) {
            throw ValidationException::withMessages(['installment_count' => 'El calendario requiere al menos una cuota.']);
        }

        $today = today($company->timezone ?: config('app.timezone'))->toDateString();
        if ($firstDueDate < $today) {
            throw ValidationException::withMessages(['first_due_date' => 'La primera cuota no puede ser anterior a hoy.']);
        }

        // Capital e intereses de la deuda anterior, separados por prorrateo.
        $principal = $this->schedule->money((string) $credit->principal);
        $creditTotal = $this->schedule->money((string) $credit->total_due);
        $previousPrincipal = bccomp($creditTotal, '0', 4) > 0
            ? $this->schedule->round4(bcdiv(bcmul($pendingTotal, $principal, 10), $creditTotal, 10))
            : $pendingTotal;
        $previousInterest = $this->schedule->round4(bcsub($pendingTotal, $previousPrincipal, 4));

        // Intereses nuevos SOBRE EL CAPITAL NUEVO (nunca sobre la deuda vieja).
        $newTotalWithInterest = $this->schedule->totalWithInterest($newCapital, (string) $credit->interest_type, (string) $credit->interest_value);
        $newInterest = $this->schedule->round4(bcsub($newTotalWithInterest, $newCapital, 4));

        $consolidated = $this->schedule->round4(bcadd(bcadd($pendingTotal, $newCapital, 4), $newInterest, 4));

        try {
            $rows = $this->schedule->build($consolidated, $installmentCount, $frequency, $firstDueDate);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['frequency' => $e->getMessage()]);
        }

        return [
            'credit' => $credit,
            'previous' => [
                'principal' => $previousPrincipal,
                'interest' => $previousInterest,
                'total' => $pendingTotal,
            ],
            'previous_pending_principal' => $previousPrincipal,
            'previous_pending_interest' => $previousInterest,
            'previous_pending_late_fee' => $this->installments->pendingLateFee($company, $account),
            'modality' => $modality,
            'new_money' => [
                'principal' => $newCapital,
                'interest' => $newInterest,
                'total' => $this->schedule->round4(bcadd($newCapital, $newInterest, 4)),
            ],
            'consolidated_total' => $consolidated,
            'disbursement' => $newCapital,
            'late_fee_excluded' => $this->installments->pendingLateFee($company, $account),
            'schedule' => [
                'installment_count' => $installmentCount,
                'frequency' => $frequency,
                'first_due_date' => $firstDueDate,
                'rows' => $rows,
            ],
        ];
    }

    /** Cobrador asignado con cobros.cobrar, o dueño/supervisor de la empresa. */
    private function authorize(User $actor, Company $company, CobrosCredit $credit): void
    {
        $isAssignedCollector = (int) $credit->collector_user_id === (int) $actor->id
            && $actor->hasPermission('cobros.cobrar', $company);
        $isSuperior = $actor->hasPermission('cobros.portal_dueno', $company)
            || $actor->hasPermission('cobros.supervisar', $company);

        if (! $isAssignedCollector && ! $isSuperior) {
            abort(403, 'No tiene permiso para preparar la renovación de este crédito.');
        }
    }
}
