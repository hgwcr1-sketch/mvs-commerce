<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

class LoyaltyReturningCustomerService
{
    public function __construct(private readonly LoyaltyAccountService $accountService) {}

    public function awardIfEligible(
        Customer $customer,
        Company $company,
        int $saleId,
        CarbonInterface|string|null $effectiveAt = null,
        array $context = [],
    ): ?LoyaltyMovement {
        $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();
        if ($setting === null
            || ! $setting->is_active
            || ! $setting->returning_customer_enabled
            || $setting->returning_customer_days < 1
            || bccomp((string) $setting->returning_customer_points, '0', 4) <= 0) {
            return null;
        }

        $timezone = $this->timezone($company);
        $current = $effectiveAt instanceof CarbonInterface
            ? CarbonImmutable::instance($effectiveAt)->setTimezone($timezone)
            : CarbonImmutable::parse($effectiveAt ?? 'now', $timezone);

        return DB::transaction(function () use ($customer, $company, $saleId, $current, $setting, $context) {
            $account = LoyaltyAccount::query()
                ->where('company_id', $company->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if ($account === null || $account->last_qualifying_purchase_at === null) {
                return null;
            }

            $previous = CarbonImmutable::instance($account->last_qualifying_purchase_at)
                ->setTimezone($current->timezone);
            $interval = $previous->startOfDay()->diff($current->startOfDay());
            if ($interval->invert === 1 || $interval->days < $setting->returning_customer_days) {
                return null;
            }

            return $this->accountService->addPoints(
                $account,
                (string) $setting->returning_customer_points,
                LoyaltyMovement::TYPE_RETURN_CUSTOMER,
                [
                    'branch' => $context['branch'] ?? $context['branch_id'] ?? null,
                    'user' => $context['user'] ?? $context['user_id'] ?? null,
                    'description' => $context['description'] ?? 'Bono por retorno de cliente',
                    'source_type' => $context['source_type'] ?? null,
                    'source_id' => $saleId,
                    'event_key' => "returning_customer:sale:{$saleId}",
                    'effective_at' => $effectiveAt ?? $current,
                    'metadata' => ($context['metadata'] ?? []) + [
                        'previous_qualifying_purchase_at' => $previous->toIso8601String(),
                        'inactivity_days' => $interval->days,
                        'required_days' => $setting->returning_customer_days,
                    ],
                ],
            );
        });
    }

    private function timezone(Company $company): string
    {
        $timezone = trim((string) $company->timezone);

        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : config('app.timezone');
    }
}
