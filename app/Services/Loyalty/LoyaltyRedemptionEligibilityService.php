<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltySetting;
use Illuminate\Validation\ValidationException;

class LoyaltyRedemptionEligibilityService
{
    public function __construct(private readonly LoyaltyPointValueService $pointValues) {}

    /** @return array{eligible:bool,available_points:string,available_money:string,minimum_enabled:bool,minimum_money:string,required_points:string,missing_money:string,reason:?string} */
    public function evaluate(LoyaltyAccount $account, Company $company): array
    {
        if ((int) $account->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['account' => 'La cuenta no pertenece a la empresa.']);
        }

        $points = bcadd((string) $account->balance, '0', 4);
        $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();
        $enabled = (bool) $setting?->redemption_minimum_enabled;
        $minimum = bcadd((string) ($setting?->redemption_minimum_amount ?? 0), '0', 4);

        if (bccomp($points, '0', 4) <= 0) {
            return $this->result(false, $points, '0.0000', $enabled, $minimum, '0.0000', $minimum, 'insufficient_points');
        }

        $availableMoney = $this->pointValues->moneyFromPoints($points, $company);
        if (! $enabled) {
            return $this->result(true, $points, $availableMoney, false, '0.0000', '0.0000', '0.0000', null);
        }

        if (bccomp($minimum, '0', 4) <= 0) {
            return $this->result(false, $points, $availableMoney, true, $minimum, '0.0000', '0.0000', 'invalid_minimum_configuration');
        }

        $requiredPoints = $this->pointValues->pointsForMoney($minimum, $company);
        $eligible = bccomp($availableMoney, $minimum, 4) >= 0;
        $missing = $eligible ? '0.0000' : bcsub($minimum, $availableMoney, 4);

        return $this->result($eligible, $points, $availableMoney, true, $minimum, $requiredPoints, $missing, $eligible ? null : 'minimum_not_reached');
    }

    private function result(bool $eligible, string $points, string $money, bool $enabled, string $minimum, string $requiredPoints, string $missing, ?string $reason): array
    {
        return ['eligible' => $eligible, 'available_points' => $points, 'available_money' => $money, 'minimum_enabled' => $enabled, 'minimum_money' => $minimum, 'required_points' => $requiredPoints, 'missing_money' => $missing, 'reason' => $reason];
    }
}
