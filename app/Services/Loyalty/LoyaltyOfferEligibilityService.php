<?php

namespace App\Services\Loyalty;

use App\Models\Sale;

class LoyaltyOfferEligibilityService
{
    /** @return array{subtotal:string,normal_amount:string,offer_amount:string,earn_on_offers:bool,eligible_amount:string} */
    public function forSale(Sale $sale, bool $earnOnOffers): array
    {
        $sale->loadMissing('items:id,sale_id,subtotal,is_offer');
        $normal = '0.0000';
        $offers = '0.0000';

        foreach ($sale->items as $item) {
            if ($item->is_offer) {
                $offers = bcadd($offers, $item->subtotal, 4);
            } else {
                $normal = bcadd($normal, $item->subtotal, 4);
            }
        }

        return [
            'subtotal' => bcadd((string) $sale->subtotal, '0', 4),
            'normal_amount' => $normal,
            'offer_amount' => $offers,
            'earn_on_offers' => $earnOnOffers,
            'eligible_amount' => $earnOnOffers ? bcadd($normal, $offers, 4) : $normal,
        ];
    }
}
