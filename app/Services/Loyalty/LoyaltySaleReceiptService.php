<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyMovement;
use App\Models\Sale;

class LoyaltySaleReceiptService
{
    private const EARNING_TYPES = [
        LoyaltyMovement::TYPE_PURCHASE,
        LoyaltyMovement::TYPE_NEW_CUSTOMER,
        LoyaltyMovement::TYPE_BIRTHDAY,
        LoyaltyMovement::TYPE_RETURN_CUSTOMER,
        LoyaltyMovement::TYPE_PROMOTION,
    ];

    /** @return array{earned:string,redeemed:string,balance_before:string,balance_after:string,adjusted:bool}|null */
    public function forSale(Sale $sale): ?array
    {
        if ($sale->customer_id === null) {
            return null;
        }

        $movements = LoyaltyMovement::query()
            ->where('company_id', $sale->company_id)
            ->where('customer_id', $sale->customer_id)
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->orderBy('id')
            ->get();

        if ($movements->isEmpty()) {
            return null;
        }

        $earned = '0.0000';
        $redeemed = '0.0000';
        foreach ($movements as $movement) {
            if (in_array($movement->type, self::EARNING_TYPES, true)) {
                $earned = bcadd($earned, (string) $movement->points, 4);
            }
            if ($movement->type === LoyaltyMovement::TYPE_REDEMPTION) {
                $redeemed = bcadd($redeemed, ltrim((string) $movement->points, '-'), 4);
            }
        }

        return [
            'earned' => $earned,
            'redeemed' => $redeemed,
            'balance_before' => (string) $movements->first()->balance_before,
            'balance_after' => (string) $movements->last()->balance_after,
            'adjusted' => $movements->contains(fn (LoyaltyMovement $movement) => in_array($movement->type, [LoyaltyMovement::TYPE_VOID, LoyaltyMovement::TYPE_RETURN], true)),
        ];
    }
}
