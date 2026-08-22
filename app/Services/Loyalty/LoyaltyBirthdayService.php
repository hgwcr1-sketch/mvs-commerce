<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

class LoyaltyBirthdayService
{
    public function __construct(private readonly LoyaltyAccountService $accountService) {}

    public function awardIfEligible(
        Customer $customer,
        Company $company,
        CarbonInterface|string|null $effectiveAt = null,
        array $context = [],
    ): ?LoyaltyMovement {
        $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();
        if ($setting === null || ! $setting->is_active || ! $setting->birthday_enabled) {
            return null;
        }

        $birthDate = Customer::query()
            ->where('company_id', $company->id)
            ->whereKey($customer->id)
            ->value('birth_date');
        if ($birthDate === null || bccomp((string) $setting->birthday_points, '0', 4) <= 0) {
            return null;
        }

        $timezone = $this->timezone($company);
        $localDate = $effectiveAt instanceof CarbonInterface
            ? $effectiveAt->copy()->timezone($timezone)
            : CarbonImmutable::parse($effectiveAt ?? 'now', $timezone);
        $birthday = CarbonImmutable::parse($birthDate, $timezone);

        if ($birthday->month !== $localDate->month || $birthday->day !== $localDate->day) {
            return null;
        }

        $account = $this->accountService->getOrCreateAccount($customer, $company, $context['user'] ?? null);

        return $this->accountService->addPoints(
            $account,
            (string) $setting->birthday_points,
            LoyaltyMovement::TYPE_BIRTHDAY,
            [
                'branch' => $context['branch'] ?? $context['branch_id'] ?? null,
                'user' => $context['user'] ?? $context['user_id'] ?? null,
                'description' => $context['description'] ?? 'Bono de cumpleaños',
                'source_type' => $context['source_type'] ?? null,
                'source_id' => $context['source_id'] ?? null,
                'event_key' => "birthday:{$customer->id}:{$localDate->year}",
                'effective_at' => $effectiveAt ?? $localDate,
                'metadata' => ($context['metadata'] ?? []) + [
                    'birthday_year' => $localDate->year,
                    'birth_month_day' => $birthday->format('m-d'),
                ],
            ],
        );
    }

    private function timezone(Company $company): string
    {
        $timezone = trim((string) $company->timezone);

        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : config('app.timezone');
    }
}
