<?php

namespace App\Services\Purchases;

use App\Models\AccountPayable;
use App\Models\Purchase;
use App\Models\User;

class PurchaseAccountPayableService
{
    public function createFor(Purchase $purchase): ?AccountPayable
    {
        if ($purchase->payment_type !== 'credit') {
            return null;
        }

        return AccountPayable::query()->firstOrCreate(
            ['purchase_id' => $purchase->id],
            [
                'company_id' => $purchase->company_id,
                'branch_id' => $purchase->branch_id,
                'supplier_id' => $purchase->supplier_id,
                'original_amount' => $purchase->total,
                'paid_amount' => 0,
                'balance_due' => $purchase->total,
                'issue_date' => $purchase->purchase_date,
                'due_date' => $purchase->due_date,
                'status' => AccountPayable::STATUS_PENDING,
                'currency_code' => $purchase->company?->currency ?? 'CRC',
                'notes' => $purchase->notes,
                'created_by' => $purchase->user_id,
            ],
        );
    }

    public function cancelFor(Purchase $purchase, ?User $user, string $reason): ?AccountPayable
    {
        $account = AccountPayable::query()
            ->where('purchase_id', $purchase->id)
            ->lockForUpdate()
            ->first();

        if (! $account || $account->status === AccountPayable::STATUS_CANCELLED) {
            return $account;
        }

        $account->update([
            'status' => AccountPayable::STATUS_CANCELLED,
            'balance_due' => 0,
            'cancelled_by' => $user?->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $account->fresh();
    }
}
