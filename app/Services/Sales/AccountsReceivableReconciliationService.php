<?php

namespace App\Services\Sales;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableAdjustment;
use App\Models\CreditNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
}
