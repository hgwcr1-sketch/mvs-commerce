<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\LoyaltyAccount;

class LoyaltyDashboardIndicatorService
{
    /** @return array{customers:int,total_earned:string,total_redeemed:string,total_expired:string,balance:string} */
    public function forCompany(Company $company): array
    {
        $totals = LoyaltyAccount::query()
            ->where('company_id', $company->id)
            ->selectRaw('COUNT(*) as customers')
            ->selectRaw('COALESCE(SUM(total_earned), 0) as total_earned')
            ->selectRaw('COALESCE(SUM(total_redeemed), 0) as total_redeemed')
            ->selectRaw('COALESCE(SUM(total_expired), 0) as total_expired')
            ->selectRaw('COALESCE(SUM(balance), 0) as balance')
            ->firstOrFail();

        return [
            'customers' => (int) $totals->customers,
            'total_earned' => $this->decimal($totals->total_earned),
            'total_redeemed' => $this->decimal($totals->total_redeemed),
            'total_expired' => $this->decimal($totals->total_expired),
            'balance' => $this->decimal($totals->balance),
        ];
    }

    private function decimal(string|int $value): string
    {
        return bcadd((string) $value, '0', 4);
    }
}
