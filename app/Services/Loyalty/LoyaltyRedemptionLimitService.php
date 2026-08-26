<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltySetting;
use Illuminate\Validation\ValidationException;

class LoyaltyRedemptionLimitService
{
    private const SCALE = 4;

    private const DEFAULT_PERCENTAGE = '100.0000';

    public function __construct(
        private readonly LoyaltyPointValueService $pointValues,
        private readonly LoyaltyRedemptionEligibilityService $eligibility,
    ) {}

    /** @return array{eligible:bool,reason:?string,percentage:string,eligible_purchase_amount:string,max_money_by_percentage:string,available_money:string,max_redeemable_money:string,max_redeemable_points:string} */
    public function calculate(LoyaltyAccount $account, Company $company, string|int $eligiblePurchaseAmount, bool $ignoreMinimum = false): array
    {
        $amount = $this->nonNegativeDecimal($eligiblePurchaseAmount);
        $eligibility = $this->eligibility->evaluate($account, $company, $ignoreMinimum);
        $percentage = $this->percentage($company);
        $percentageLimit = bcdiv(bcmul($amount, $percentage, 8), '100', self::SCALE);

        if (! $eligibility['eligible']) {
            return $this->result(false, $eligibility['reason'], $percentage, $amount, $percentageLimit, $eligibility['available_money'], '0.0000', '0.0000');
        }

        if (bccomp($amount, '0', self::SCALE) === 0 || bccomp($percentageLimit, '0', self::SCALE) === 0) {
            return $this->result(false, 'no_eligible_purchase_amount', $percentage, $amount, $percentageLimit, $eligibility['available_money'], '0.0000', '0.0000');
        }

        $moneyLimit = bccomp($percentageLimit, $eligibility['available_money'], self::SCALE) <= 0
            ? $percentageLimit
            : $eligibility['available_money'];
        $points = $this->pointValues->pointsForMoney($moneyLimit, $company);
        $precisePointMoney = bcmul($points, $this->pointValues->pointValue($company), 8);

        if (bccomp($points, $eligibility['available_points'], self::SCALE) > 0
            || bccomp($precisePointMoney, $moneyLimit, 8) > 0) {
            $points = bcsub($points, '0.0001', self::SCALE);
        }

        if (bccomp($points, '0', self::SCALE) < 0) {
            $points = '0.0000';
        }
        $redeemableMoney = $this->pointValues->moneyFromPoints($points, $company);

        return $this->result(bccomp($points, '0', self::SCALE) > 0, null, $percentage, $amount, $percentageLimit, $eligibility['available_money'], $redeemableMoney, $points);
    }

    private function percentage(Company $company): string
    {
        $value = LoyaltySetting::query()->where('company_id', $company->id)->value('maximum_redemption_percent') ?? self::DEFAULT_PERCENTAGE;
        $value = bcadd((string) $value, '0', self::SCALE);
        if (bccomp($value, '0', self::SCALE) <= 0 || bccomp($value, '100', self::SCALE) > 0) {
            throw ValidationException::withMessages(['maximum_redemption_percent' => 'El porcentaje máximo debe ser mayor que cero y no superar 100.']);
        }

        return $value;
    }

    private function nonNegativeDecimal(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['eligible_purchase_amount' => 'El monto elegible debe tener como máximo cuatro decimales.']);
        }

        return bcadd($value, '0', self::SCALE);
    }

    private function result(bool $eligible, ?string $reason, string $percentage, string $amount, string $percentageLimit, string $availableMoney, string $redeemableMoney, string $points): array
    {
        return ['eligible' => $eligible, 'reason' => $reason, 'percentage' => $percentage, 'eligible_purchase_amount' => $amount, 'max_money_by_percentage' => $percentageLimit, 'available_money' => $availableMoney, 'max_redeemable_money' => $redeemableMoney, 'max_redeemable_points' => $points];
    }
}
