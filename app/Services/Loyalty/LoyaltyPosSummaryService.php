<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltySetting;
use Illuminate\Validation\ValidationException;

class LoyaltyPosSummaryService
{
    public function __construct(
        private readonly LoyaltyPointValueService $pointValues,
        private readonly LoyaltyRedemptionEligibilityService $eligibility,
        private readonly LoyaltyRedemptionLimitService $limits,
    ) {}

    /** @return array{available:bool,reason:?string,balance_points:?string,point_value:?string,minimum_enabled:bool,minimum_amount:?string,eligible:bool,available_points:?string,available_money:?string,maximum_redemption_percent:?string,max_redeemable_money:?string,max_redeemable_points:?string,offers_allowed:bool} */
    public function summary(Customer $customer, Company $company, string|int $eligibleAmount, bool $hasOffers = false): array
    {
        $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();

        if ($setting === null || ! $setting->is_active) {
            return $this->unavailable('inactive');
        }

        $account = LoyaltyAccount::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->first();

        if ($account === null || ! $account->is_active) {
            return $this->unavailable('no_account');
        }

        try {
            $eligibility = $this->eligibility->evaluate($account, $company);
            $limit = $this->limits->calculate($account, $company, $eligibleAmount);
            $pointValue = $this->pointValues->pointValue($company);
        } catch (ValidationException) {
            return $this->unavailable('invalid_configuration');
        }

        return [
            'available' => true,
            'reason' => null,
            'balance_points' => (string) $account->balance,
            'point_value' => $pointValue,
            'minimum_enabled' => (bool) $setting->redemption_minimum_enabled,
            'minimum_amount' => (string) $setting->redemption_minimum_amount,
            'eligible' => (bool) $eligibility['eligible'],
            'available_points' => (string) $eligibility['available_points'],
            'available_money' => (string) $eligibility['available_money'],
            'maximum_redemption_percent' => (string) $limit['percentage'],
            'max_redeemable_money' => (string) $limit['max_redeemable_money'],
            'max_redeemable_points' => (string) $limit['max_redeemable_points'],
            'offers_allowed' => ! $hasOffers || (bool) $setting->redeem_on_offers,
        ];
    }

    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'balance_points' => null,
            'point_value' => null,
            'minimum_enabled' => false,
            'minimum_amount' => null,
            'eligible' => false,
            'available_points' => null,
            'available_money' => null,
            'maximum_redemption_percent' => null,
            'max_redeemable_money' => null,
            'max_redeemable_points' => null,
            'offers_allowed' => false,
        ];
    }
}
