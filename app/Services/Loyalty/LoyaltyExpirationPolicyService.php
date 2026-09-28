<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/** Política de solo lectura compartida por el portal y el proceso de vencimiento. */
class LoyaltyExpirationPolicyService
{
    /** @return array<string, mixed>|null */
    public function resolve(Company $company, ?LoyaltySetting $setting, ?LoyaltyAccount $account, CarbonInterface|string|null $at = null): ?array
    {
        if (! $company->is_active || ! $setting?->is_active || ! $setting->expiration_enabled
            || (int) $setting->expiration_months < 1 || $account === null
            || (int) $setting->company_id !== (int) $company->id
            || (int) $account->company_id !== (int) $company->id
            || bccomp((string) $account->balance, '0', 4) <= 0) {
            return null;
        }

        $reference = $account->last_qualifying_purchase_at;
        $source = 'qualifying_purchase';
        $legacy = null;
        if ($reference === null) {
            // No usar relaciones precargadas: expiración debe resolver de nuevo bajo lock.
            $candidates = LoyaltyMovement::query()
                ->where('company_id', $company->id)
                ->where('loyalty_account_id', $account->id)
                ->where('customer_id', $account->customer_id)
                ->where('type', LoyaltyMovement::TYPE_ADJUSTMENT)
                ->where('points', '>', 0)
                ->where('source_type', 'LoyaltyMigration')
                ->where('metadata->migration', 'P37')
                ->where('metadata->kind', 'legacy_initial_balance')
                ->orderBy('id')->limit(2)->get();

            // Más de una referencia es ambigua: no elegir una fecha arbitrariamente.
            if ($candidates->count() !== 1) {
                return null;
            }
            $legacy = $candidates->first();
            $reference = $legacy->effective_at;
            $source = 'legacy_initial_balance';
        }
        if ($reference === null) {
            return null;
        }

        $timezone = $this->timezone($company);
        $reference = CarbonImmutable::instance($reference)->setTimezone($timezone)->startOfDay();
        $due = $reference->addMonthsNoOverflow((int) $setting->expiration_months);
        $today = ($at instanceof CarbonInterface ? CarbonImmutable::instance($at)
            : ($at !== null ? CarbonImmutable::parse($at, $timezone) : CarbonImmutable::now($timezone)))
            ->setTimezone($timezone)->startOfDay();
        $days = (int) $today->diffInDays($due, false);

        return [
            'reference_date' => $reference,
            'reference_source' => $source,
            'legacy_movement_id' => $legacy?->id,
            'date' => $due,
            'days' => max(0, $days),
            'due' => $days <= 0,
            'overdue' => $days < 0,
            'near' => abs($days) <= 30,
            'urgent' => $days <= 7,
            'points' => (string) $account->balance,
            'months' => (int) $setting->expiration_months,
        ];
    }

    public function timezone(Company $company): string
    {
        $timezone = trim((string) $company->timezone);

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : config('app.timezone');
    }
}
