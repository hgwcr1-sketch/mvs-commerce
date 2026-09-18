<?php

namespace App\Services\Sales;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableAdjustment;
use App\Models\AccountReceivablePayment;
use App\Models\CreditNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsReceivableReconciliationService
{
    private const SCALE = 4;

    public function reconcile(CreditNote $creditNote, User $user): ?AccountReceivableAdjustment
    {
        return DB::transaction(function () use ($creditNote, $user): ?AccountReceivableAdjustment {
            $ar = AccountReceivable::query()
                ->where('company_id', $creditNote->company_id)
                ->where('sale_id', $creditNote->sale_id)
                ->lockForUpdate()
                ->first();

            if ($ar === null) {
                $creditNote->update(['requires_ar_review' => false]);

                return null;
            }

            if ($ar->status === AccountReceivable::STATUS_CANCELLED) {
                $creditNote->update(['requires_ar_review' => false]);

                return null;
            }

            if ((int) $ar->customer_id !== (int) $creditNote->customer_id) {
                return null;
            }

            if ((int) $ar->branch_id !== (int) $creditNote->branch_id) {
                return null;
            }

            if ($ar->currency_code !== $creditNote->currency_code) {
                return null;
            }

            if (bccomp((string) $ar->balance_due, '0', self::SCALE) <= 0) {
                $creditNote->update(['requires_ar_review' => false]);

                return null;
            }

            $ncBalance = bcsub(
                (string) $creditNote->issued_amount,
                (string) $creditNote->offset_amount,
                self::SCALE,
            );
            $ncBalance = bcsub(
                $ncBalance,
                (string) $creditNote->applied_amount,
                self::SCALE,
            );

            if (bccomp($ncBalance, '0', self::SCALE) <= 0) {
                $creditNote->update(['requires_ar_review' => false]);

                return null;
            }

            $balanceBefore = (string) $ar->balance_due;

            $offsetAmount = bccomp(
                $ncBalance,
                $balanceBefore,
                self::SCALE,
            ) <= 0 ? $ncBalance : $balanceBefore;

            $idempotencyKey = 'credit-note-ar-offset:'.$creditNote->id.':'.$ar->id;

            $existing = AccountReceivableAdjustment::query()
                ->where('company_id', $creditNote->company_id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', AccountReceivableAdjustment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $balanceAfter = bcsub($balanceBefore, $offsetAmount, self::SCALE);
            $balanceAfter = bccomp($balanceAfter, '0', self::SCALE) < 0 ? '0' : $balanceAfter;

            $ar->update([
                'balance_due' => $balanceAfter,
                'status' => bccomp($balanceAfter, '0', self::SCALE) <= 0
                    ? AccountReceivable::STATUS_PAID
                    : $ar->status,
            ]);

            $newOffset = bcadd((string) $creditNote->offset_amount, $offsetAmount, self::SCALE);
            $newNcBalance = bcsub((string) $creditNote->issued_amount, $newOffset, self::SCALE);
            $newNcBalance = bcsub($newNcBalance, (string) $creditNote->applied_amount, self::SCALE);

            $creditNote->update([
                'offset_amount' => $newOffset,
                'balance' => $newNcBalance,
                'requires_ar_review' => false,
                'status' => bccomp($newNcBalance, '0', self::SCALE) <= 0
                    ? CreditNote::STATUS_APPLIED
                    : CreditNote::STATUS_PARTIALLY_APPLIED,
            ]);

            return AccountReceivableAdjustment::create([
                'company_id' => $creditNote->company_id,
                'branch_id' => $creditNote->branch_id,
                'account_receivable_id' => $ar->id,
                'credit_note_id' => $creditNote->id,
                'type' => AccountReceivableAdjustment::TYPE_CREDIT_NOTE_OFFSET,
                'amount' => $offsetAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reason' => 'Conciliación NC/'.$creditNote->credit_note_number,
                'status' => AccountReceivableAdjustment::STATUS_ACTIVE,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Revierte una compensación previamente aplicada entre NC y CxC.
     *
     * El adjustment original permanece immutable como evidencia histórica.
     * Se crea un segundo adjustment de tipo credit_note_offset_reversal
     * que registra la restitución del saldo.
     *
     * La reversión NO es un pago, NO crea movimientos de caja.
     * La reversión incrementa AR.balance_due y decrementa NC.offset_amount.
     *
     * Lock order: AR → NC → adjustment (consistente con reconcile).
     * Idempotencia: credit-note-ar-offset-reversal:{adjustment_id} UNIQUE.
     *
     * Invariante post-reversión:
     *   issued_amount = offset_amount + applied_amount + balance
     */
    public function reverseOffset(
        AccountReceivableAdjustment $adjustment,
        User $user,
        string $reason,
    ): AccountReceivableAdjustment {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar el motivo de la reversión.',
            ]);
        }

        if ($adjustment->status !== AccountReceivableAdjustment::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'adjustment' => 'Solo se pueden revertir compensaciones con estado activo.',
            ]);
        }

        if ($adjustment->type !== AccountReceivableAdjustment::TYPE_CREDIT_NOTE_OFFSET) {
            throw ValidationException::withMessages([
                'adjustment' => 'Solo se pueden revertir compensaciones de tipo offset.',
            ]);
        }

        return DB::transaction(function () use ($adjustment, $user, $reason): AccountReceivableAdjustment {
            // Lock order: AR → NC → adjustment
            $ar = AccountReceivable::query()
                ->whereKey($adjustment->account_receivable_id)
                ->lockForUpdate()
                ->firstOrFail();

            $nc = CreditNote::query()
                ->whereKey($adjustment->credit_note_id)
                ->lockForUpdate()
                ->firstOrFail();

            $adj = AccountReceivableAdjustment::query()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotencia
            $idempotencyKey = 'credit-note-ar-offset-reversal:'.$adjustment->id;

            $existing = AccountReceivableAdjustment::query()
                ->where('company_id', $adjustment->company_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Calcular monto reversible
            $reversalAmount = bcsub(
                (string) $adj->amount,
                (string) $adj->reversed_amount,
                self::SCALE,
            );

            if (bccomp($reversalAmount, '0', self::SCALE) <= 0) {
                throw ValidationException::withMessages([
                    'adjustment' => 'Esta compensación ya ha sido completamente revertida.',
                ]);
            }

            // 6. Actualizar AR
            $arBalanceBefore = (string) $ar->balance_due;
            $arBalanceAfter = bcadd($arBalanceBefore, $reversalAmount, self::SCALE);

            // No superar original_amount
            if (bccomp($arBalanceAfter, (string) $ar->original_amount, self::SCALE) > 0) {
                $arBalanceAfter = (string) $ar->original_amount;
            }

            // Recalcular status del AR
            if (bccomp($arBalanceAfter, '0', self::SCALE) <= 0) {
                $arStatus = AccountReceivable::STATUS_PAID;
            } elseif ($ar->due_date->isBefore(today())) {
                $arStatus = AccountReceivable::STATUS_OVERDUE;
            } else {
                $tienePagos = AccountReceivablePayment::query()
                    ->where('account_receivable_id', $ar->id)
                    ->exists();
                $arStatus = $tienePagos
                    ? AccountReceivable::STATUS_PARTIAL
                    : AccountReceivable::STATUS_PENDING;
            }

            $ar->update([
                'balance_due' => $arBalanceAfter,
                'status' => $arStatus,
            ]);

            // 7. Actualizar NC
            $ncNewOffset = bcsub((string) $nc->offset_amount, $reversalAmount, self::SCALE);
            $ncNewBalance = bcsub(
                (string) $nc->issued_amount,
                $ncNewOffset,
                self::SCALE,
            );
            $ncNewBalance = bcsub($ncNewBalance, (string) $nc->applied_amount, self::SCALE);

            if (bccomp($ncNewBalance, '0', self::SCALE) <= 0) {
                $ncStatus = CreditNote::STATUS_APPLIED;
            } elseif (bccomp((string) $nc->applied_amount, '0', self::SCALE) > 0) {
                $ncStatus = CreditNote::STATUS_PARTIALLY_APPLIED;
            } else {
                $ncStatus = CreditNote::STATUS_ISSUED;
            }

            $nc->update([
                'offset_amount' => $ncNewOffset,
                'balance' => $ncNewBalance,
                'status' => $ncStatus,
            ]);

            // 8. Actualizar adjustment original
            $adj->update([
                'reversed_amount' => bcadd(
                    (string) $adj->reversed_amount,
                    $reversalAmount,
                    self::SCALE,
                ),
            ]);

            // 9. Crear reversal adjustment
            $reversal = AccountReceivableAdjustment::create([
                'company_id' => $adj->company_id,
                'branch_id' => $adj->branch_id,
                'account_receivable_id' => $adj->account_receivable_id,
                'credit_note_id' => $adj->credit_note_id,
                'type' => AccountReceivableAdjustment::TYPE_CREDIT_NOTE_OFFSET_REVERSAL,
                'amount' => $reversalAmount,
                'reversed_amount' => '0',
                'reversal_adjustment_id' => null,
                'balance_before' => $arBalanceBefore,
                'balance_after' => $arBalanceAfter,
                'reason' => $reason,
                'status' => AccountReceivableAdjustment::STATUS_ACTIVE,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);

            // 10. Enlazar original → reversal
            $adj->update(['reversal_adjustment_id' => $reversal->id]);

            return $reversal;
        });
    }
}
