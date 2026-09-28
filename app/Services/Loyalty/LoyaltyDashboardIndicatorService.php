<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use Illuminate\Support\Facades\DB;

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

    /** @return array<int, array{branch_id:?int,branch_name:string,customers:int,total_earned:string,total_redeemed:string,total_expired:string}> */
    public function byBranch(Company $company): array
    {
        $earningTypes = [
            LoyaltyMovement::TYPE_PURCHASE,
            LoyaltyMovement::TYPE_NEW_CUSTOMER,
            LoyaltyMovement::TYPE_BIRTHDAY,
            LoyaltyMovement::TYPE_RETURN_CUSTOMER,
            LoyaltyMovement::TYPE_PROMOTION,
        ];
        $redemptionTypes = [LoyaltyMovement::TYPE_REDEMPTION, LoyaltyMovement::TYPE_REWARD];
        $reversalTypes = [LoyaltyMovement::TYPE_RETURN, LoyaltyMovement::TYPE_VOID];

        $rows = DB::table('loyalty_movements as movement')
            ->leftJoin('loyalty_movements as original', 'original.id', '=', 'movement.related_movement_id')
            ->where('movement.company_id', $company->id)
            ->selectRaw('movement.branch_id, COUNT(DISTINCT movement.customer_id) as customers')
            ->selectRaw($this->netTotalSql($earningTypes, $reversalTypes, $earningTypes, false).' as total_earned')
            ->selectRaw($this->netTotalSql($redemptionTypes, $reversalTypes, $redemptionTypes, true).' as total_redeemed')
            ->selectRaw($this->netTotalSql([LoyaltyMovement::TYPE_EXPIRATION], $reversalTypes, [LoyaltyMovement::TYPE_EXPIRATION], true).' as total_expired')
            ->groupBy('movement.branch_id')
            ->get()
            ->keyBy(fn ($row) => $row->branch_id === null ? 'unassigned' : (string) $row->branch_id);

        $branches = Branch::query()
            ->where('company_id', $company->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $branch) => $this->branchRow(
                $branch->id,
                $branch->name,
                $rows->get((string) $branch->id),
            ));

        if ($rows->has('unassigned')) {
            $branches->push($this->branchRow(null, 'Sin sucursal', $rows->get('unassigned')));
        }

        return $branches->all();
    }

    private function decimal(string|int $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    /** @param array<int, string> $directTypes
     * @param  array<int, string>  $reversalTypes
     * @param  array<int, string>  $originalTypes
     */
    private function netTotalSql(array $directTypes, array $reversalTypes, array $originalTypes, bool $invert): string
    {
        $direct = "'".implode("','", $directTypes)."'";
        $reversals = "'".implode("','", $reversalTypes)."'";
        $originals = "'".implode("','", $originalTypes)."'";
        $sign = $invert ? '-1 * ' : '';

        return "COALESCE(SUM(CASE WHEN movement.type IN ({$direct}) THEN {$sign}movement.points WHEN movement.type IN ({$reversals}) AND original.type IN ({$originals}) THEN {$sign}movement.points ELSE 0 END), 0)";
    }

    /** @return array{branch_id:?int,branch_name:string,customers:int,total_earned:string,total_redeemed:string,total_expired:string} */
    private function branchRow(?int $branchId, string $branchName, ?object $totals): array
    {
        return [
            'branch_id' => $branchId,
            'branch_name' => $branchName,
            'customers' => (int) ($totals->customers ?? 0),
            'total_earned' => $this->decimal($totals->total_earned ?? 0),
            'total_redeemed' => $this->decimal($totals->total_redeemed ?? 0),
            'total_expired' => $this->decimal($totals->total_expired ?? 0),
        ];
    }
}
