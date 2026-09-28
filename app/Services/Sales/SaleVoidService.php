<?php

namespace App\Services\Sales;

use App\Models\AccountReceivable;
use App\Models\LoyaltyMovement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Loyalty\LoyaltyAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleVoidService
{
    public function __construct(
        private readonly InventoryPostingService $inventoryPostingService,
        private readonly LoyaltyAccountService $loyaltyAccountService,
    ) {}

    public function void(Sale $sale, User $user, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $user, $reason) {
            $sale = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->status !== Sale::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'sale' => 'Solo se puede anular una venta completada.',
                ]);
            }

            if ($sale->is_historical) {
                throw ValidationException::withMessages([
                    'sale' => 'Las ventas históricas importadas no admiten anulaciones operativas.',
                ]);
            }

            if ((int) $sale->company_id !== (int) session('active_company_id')) {
                abort(404);
            }

            if ((int) $sale->branch_id !== (int) session('active_branch_id')) {
                abort(404);
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Debe indicar el motivo de la anulación.',
                ]);
            }

            $sale->load([
                'items.product',
                'payments',
                'accountReceivable.payments',
            ]);

            if ($sale->accountReceivable?->payments->isNotEmpty()) {
                throw ValidationException::withMessages(['sale' => 'No se puede anular una venta a crédito que ya tiene abonos registrados.']);
            }

            foreach ($sale->items as $item) {
                if (
                    $item->product !== null
                    && $item->product->track_inventory
                ) {
                    $this->inventoryPostingService->voidSale(
                        $sale,
                        $item->product,
                        (float) $item->quantity,
                        $user->id,
                    );
                }
            }

            foreach ($sale->payments as $payment) {
                if ($payment->status === SalePayment::STATUS_COMPLETED) {
                    $payment->update([
                        'status' => SalePayment::STATUS_VOIDED,
                        'voided_by' => $user->id,
                        'voided_at' => now(),
                        'void_reason' => $reason,
                    ]);
                }
            }

            $this->reverseLoyaltyRedemption($sale, $user, $reason);

            $sale->accountReceivable?->update(['status' => AccountReceivable::STATUS_CANCELLED, 'balance_due' => 0]);

            $sale->update([
                'status' => Sale::STATUS_VOIDED,
                'voided_by' => $user->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $sale->fresh([
                'items',
                'payments',
            ]);
        });
    }

    private function reverseLoyaltyRedemption(Sale $sale, User $user, string $reason): void
    {
        $movement = LoyaltyMovement::query()
            ->where('company_id', $sale->company_id)
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('type', LoyaltyMovement::TYPE_REDEMPTION)
            ->first();

        if ($movement === null) {
            return;
        }

        $this->loyaltyAccountService->reverseMovement($movement, LoyaltyMovement::TYPE_VOID, [
            'branch' => $sale->branch_id,
            'user' => $user->id,
            'source_type' => Sale::class,
            'source_id' => $sale->id,
            'event_key' => "sale:{$sale->id}:loyalty:redemption:void",
            'description' => "Reversión de canje por anulación de venta {$sale->sale_number}",
            'effective_at' => now(),
            'metadata' => [
                'sale_number' => $sale->sale_number,
                'void_reason' => $reason,
            ],
        ]);
    }
}
