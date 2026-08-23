<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyExpirationService
{
    private const SCALE = 4;

    public function __construct(private readonly LoyaltyAccountService $accountService) {}

    /**
     * Procesa todas las empresas con vencimiento automático habilitado.
     *
     * @return array{expired_accounts: int, expired_points: string, skipped: int}
     */
    public function process(?int $companyId = null): array
    {
        $expiredAccounts = 0;
        $expiredPoints = bcadd('0', '0', self::SCALE);
        $skipped = 0;

        LoyaltySetting::query()
            ->where('is_active', true)
            ->where('expiration_enabled', true)
            ->where('expiration_months', '>=', 1)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->with('company')
            ->chunkById(100, function ($settings) use (&$expiredAccounts, &$expiredPoints, &$skipped) {
                foreach ($settings as $setting) {
                    $company = $setting->company;

                    if ($company === null || ! $company->is_active) {
                        continue;
                    }

                    $timezone = $this->timezone($company);
                    $today = CarbonImmutable::now($timezone)->startOfDay();

                    LoyaltyAccount::query()
                        ->where('company_id', $setting->company_id)
                        ->where('balance', '>', 0)
                        ->chunkById(100, function ($accounts) use ($setting, $timezone, $today, &$expiredAccounts, &$expiredPoints, &$skipped) {
                            foreach ($accounts as $account) {
                                try {
                                    $movement = $this->handle($setting, $timezone, $today, $account);
                                } catch (ValidationException) {
                                    $movement = null;
                                }

                                if ($movement === null) {
                                    $skipped++;

                                    continue;
                                }

                                $expiredAccounts++;
                                $expiredPoints = bcadd($expiredPoints, ltrim((string) $movement->points, '-'), self::SCALE);
                            }
                        });
                }
            });

        return [
            'expired_accounts' => $expiredAccounts,
            'expired_points' => $expiredPoints,
            'skipped' => $skipped,
        ];
    }

    /**
     * Intenta vencer los puntos de una cuenta si su período de inactividad ya venció.
     */
    public function expireAccount(
        Company $company,
        LoyaltyAccount $account,
        CarbonInterface|string|null $at = null,
    ): ?LoyaltyMovement {
        $setting = LoyaltySetting::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('expiration_enabled', true)
            ->where('expiration_months', '>=', 1)
            ->first();

        if ($setting === null || ! $company->is_active) {
            return null;
        }

        $timezone = $this->timezone($company);
        $today = $at instanceof CarbonInterface
            ? CarbonImmutable::instance($at)
            : ($at !== null ? CarbonImmutable::parse($at, $timezone) : CarbonImmutable::now($timezone));

        return $this->handle(
            $setting,
            $timezone,
            $today->setTimezone($timezone)->startOfDay(),
            $account,
        );
    }

    private function handle(
        LoyaltySetting $setting,
        string $timezone,
        CarbonImmutable $today,
        LoyaltyAccount $account,
    ): ?LoyaltyMovement {
        return DB::transaction(function () use ($setting, $timezone, $today, $account) {
            $locked = LoyaltyAccount::query()->lockForUpdate()->find($account->id);

            if ($locked === null
                || (int) $locked->company_id !== (int) $setting->company_id
                || bccomp((string) $locked->balance, '0', self::SCALE) <= 0
                || $locked->last_qualifying_purchase_at === null) {
                return null;
            }

            $lastPurchase = CarbonImmutable::instance($locked->last_qualifying_purchase_at)
                ->setTimezone($timezone)
                ->startOfDay();
            $dueDate = $lastPurchase->addMonthsNoOverflow(max(1, (int) $setting->expiration_months));

            if ($today->lt($dueDate)) {
                return null;
            }

            return $this->accountService->subtractPoints(
                $locked,
                (string) $locked->balance,
                LoyaltyMovement::TYPE_EXPIRATION,
                [
                    'description' => 'Vencimiento de puntos por inactividad',
                    'source_type' => 'loyalty_expiration',
                    'event_key' => "expiration:{$locked->id}:{$dueDate->toDateString()}",
                    'effective_at' => $today,
                    'metadata' => [
                        'due_date' => $dueDate->toDateString(),
                        'expiration_months' => (int) $setting->expiration_months,
                        'last_qualifying_purchase_at' => $lastPurchase->toIso8601String(),
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
