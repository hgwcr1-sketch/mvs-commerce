<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyRegistrationIncentive;
use App\Models\LoyaltyRegistrationIncentiveClaim;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyRegistrationIncentiveService
{
    public const P14_DEFAULT_POINTS = '10.0000';

    public function settingFor(Company $company): LoyaltyRegistrationIncentive
    {
        return $this->settingForCompanyId($company->id);
    }

    public function settingForCompanyId(int $companyId): LoyaltyRegistrationIncentive
    {
        return LoyaltyRegistrationIncentive::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'is_enabled' => false,
                'benefit_type' => LoyaltyRegistrationIncentive::TYPE_POINTS,
                'benefit_value' => self::P14_DEFAULT_POINTS,
                'minimum_purchase_enabled' => false,
                'minimum_purchase_amount' => '0.0000',
                'award_timing' => LoyaltyRegistrationIncentive::TIMING_REGISTRATION,
                'allow_on_first_purchase' => true,
                'bypass_redemption_minimum' => false,
                'expiration_enabled' => false,
                'expiration_days' => null,
            ],
        );
    }

    public function toggle(Company $company, bool $enabled): LoyaltyRegistrationIncentive
    {
        $setting = $this->settingFor($company);

        return $this->configure(
            $company,
            $enabled,
            $setting->benefit_type ?? LoyaltyRegistrationIncentive::TYPE_POINTS,
            $setting->benefit_value ?? self::P14_DEFAULT_POINTS,
        );
    }

    public function configure(Company $company, bool $enabled, string $benefitType, string|int $benefitValue, array $rules = []): LoyaltyRegistrationIncentive
    {
        $setting = $this->settingFor($company);
        $minimumEnabled = (bool) ($rules['minimum_purchase_enabled'] ?? $setting->minimum_purchase_enabled ?? false);
        $expirationEnabled = (bool) ($rules['expiration_enabled'] ?? $setting->expiration_enabled ?? false);
        $awardTiming = (string) ($rules['award_timing'] ?? $setting->award_timing ?? LoyaltyRegistrationIncentive::TIMING_REGISTRATION);
        if (! in_array($awardTiming, LoyaltyRegistrationIncentive::TIMINGS, true)) {
            throw ValidationException::withMessages(['award_timing' => 'El momento de concesión no es válido.']);
        }

        $minimumAmount = $this->nonNegativeDecimal($rules['minimum_purchase_amount'] ?? $setting->minimum_purchase_amount ?? '0');
        if ($minimumEnabled && bccomp($minimumAmount, '0', 4) <= 0) {
            throw ValidationException::withMessages(['minimum_purchase_amount' => 'El monto mínimo debe ser mayor que cero.']);
        }

        $expirationDays = $rules['expiration_days'] ?? $setting->expiration_days;
        if ($expirationEnabled && (! is_numeric($expirationDays) || (int) $expirationDays < 1 || (int) $expirationDays > 3650 || (string) (int) $expirationDays !== trim((string) $expirationDays))) {
            throw ValidationException::withMessages(['expiration_days' => 'La vigencia debe ser un número entero entre 1 y 3650 días.']);
        }

        $setting->update([
            'is_enabled' => $enabled,
            'benefit_type' => $this->validateType($benefitType),
            'benefit_value' => $this->validateValue($benefitType, $benefitValue),
            'minimum_purchase_enabled' => $minimumEnabled,
            'minimum_purchase_amount' => $minimumEnabled ? $minimumAmount : '0.0000',
            'award_timing' => $awardTiming,
            'allow_on_first_purchase' => (bool) ($rules['allow_on_first_purchase'] ?? $setting->allow_on_first_purchase ?? true),
            'bypass_redemption_minimum' => (bool) ($rules['bypass_redemption_minimum'] ?? $setting->bypass_redemption_minimum ?? false),
            'expiration_enabled' => $expirationEnabled,
            'expiration_days' => $expirationEnabled ? (int) $expirationDays : null,
        ]);

        return $setting->fresh();
    }

    /**
     * P14: intentar otorgar incentivo tras registro (una sola vez por cliente, nunca duplicar).
     * Reutiliza LoyaltyAccountService (F09) para puntos; beneficio por defecto 10 puntos (P15 lo hará configurable).
     * Retorna claim o null si no aplica.
     */
    public function tryAwardForRegistration(Customer $customer, Company $company, ?int $branchId = null): ?LoyaltyRegistrationIncentiveClaim
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }

        return DB::transaction(function () use ($customer, $company, $branchId) {
            $setting = LoyaltyRegistrationIncentive::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();
            if (! $setting?->is_enabled || $setting->award_timing !== LoyaltyRegistrationIncentive::TIMING_REGISTRATION) {
                return null;
            }

            return $this->award($customer, $company, $setting, now(), $branchId);
        });
    }

    public function tryAwardAfterPurchase(Sale $sale): ?LoyaltyRegistrationIncentiveClaim
    {
        if ($sale->status !== Sale::STATUS_COMPLETED || $sale->customer_id === null) {
            return null;
        }

        return DB::transaction(function () use ($sale) {
            $lockedSale = Sale::query()->lockForUpdate()->find($sale->id);
            $company = Company::query()->find($lockedSale?->company_id);
            $customer = Customer::query()->find($lockedSale?->customer_id);
            if ($lockedSale === null || $company === null || $customer === null || $lockedSale->status !== Sale::STATUS_COMPLETED) {
                return null;
            }

            $setting = LoyaltyRegistrationIncentive::query()->where('company_id', $company->id)->lockForUpdate()->first();
            if (! $setting?->is_enabled || $setting->award_timing !== LoyaltyRegistrationIncentive::TIMING_AFTER_FIRST_VALID_PURCHASE) {
                return null;
            }
            if (! $this->meetsMinimum($setting, (string) $lockedSale->total)) {
                return null;
            }

            return $this->award(
                $customer,
                $company,
                $setting,
                $lockedSale->completed_at ?? $lockedSale->created_at,
                $lockedSale->branch_id,
                $lockedSale,
            );
        });
    }

    /**
     * @return array{eligible:bool,reason:?string,claim_id:?int,benefit_type:?string,benefit_value:?string,discount_amount:string,bypass_redemption_minimum:bool,expires_at:?CarbonInterface}
     */
    public function evaluateForPurchase(
        Customer $customer,
        Company $company,
        string|int $purchaseAmount,
        CarbonInterface|string|null $at = null,
        ?int $currentSaleId = null,
    ): array {
        $this->validateCustomerCompany($customer, $company);
        $amount = $this->positiveDecimal($purchaseAmount);
        $instant = $this->localInstant($company, $at)->utc();

        return DB::transaction(function () use ($customer, $company, $amount, $instant, $currentSaleId) {
            $claim = LoyaltyRegistrationIncentiveClaim::query()
                ->where('company_id', $company->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();
            if ($claim === null) {
                return $this->evaluation(false, 'not_awarded');
            }
            if ($claim->used_at !== null) {
                return $this->evaluation(false, 'already_used', $claim);
            }
            if ($claim->available_at !== null && $instant->lt($claim->available_at)) {
                return $this->evaluation(false, 'not_available', $claim);
            }
            if ($claim->expires_at !== null && $instant->gt($claim->expires_at)) {
                if ($claim->expired_at === null) {
                    $claim->update(['expired_at' => $instant]);
                }

                return $this->evaluation(false, 'expired', $claim->fresh());
            }
            if (bccomp($amount, (string) $claim->minimum_purchase_amount, 4) < 0) {
                return $this->evaluation(false, 'minimum_purchase_not_reached', $claim);
            }
            if (! $claim->allow_on_first_purchase && ! $this->hasPreviousCompletedPurchase($customer, $company, $currentSaleId)) {
                return $this->evaluation(false, 'first_purchase_not_allowed', $claim);
            }

            $discountAmount = '0.0000';
            if ($claim->benefit_type === LoyaltyRegistrationIncentive::TYPE_PERCENTAGE) {
                $discountAmount = $this->percentageOf($amount, (string) $claim->benefit_value);
            } elseif ($claim->benefit_type === LoyaltyRegistrationIncentive::TYPE_FIXED) {
                if (bccomp((string) $claim->benefit_value, $amount, 4) > 0) {
                    return $this->evaluation(false, 'discount_exceeds_purchase', $claim);
                }
                $discountAmount = (string) $claim->benefit_value;
            }

            return $this->evaluation(true, null, $claim, $discountAmount);
        });
    }

    public function consume(
        int $claimId,
        Customer $customer,
        Company $company,
        ?int $saleId = null,
        CarbonInterface|string|null $at = null,
        ?string $discountAmount = null,
    ): LoyaltyRegistrationIncentiveClaim {
        $this->validateCustomerCompany($customer, $company);
        $instant = $this->localInstant($company, $at)->utc();

        return DB::transaction(function () use ($claimId, $customer, $company, $saleId, $instant, $discountAmount) {
            $claim = LoyaltyRegistrationIncentiveClaim::query()
                ->where('company_id', $company->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($claimId);
            if ($claim->used_at === null) {
                $claim->update([
                    'sale_id' => $saleId,
                    'used_at' => $instant,
                    'discount_amount' => $discountAmount === null ? $claim->discount_amount : $this->nonNegativeDecimal($discountAmount),
                ]);
            }

            return $claim->fresh();
        });
    }

    private function award(
        Customer $customer,
        Company $company,
        LoyaltyRegistrationIncentive $setting,
        CarbonInterface|string|null $at,
        ?int $branchId = null,
        ?Sale $qualificationSale = null,
    ): ?LoyaltyRegistrationIncentiveClaim {
        $existing = LoyaltyRegistrationIncentiveClaim::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return null;
        }

        $benefitType = $this->validateType($setting->benefit_type);
        $benefitValue = $this->validateValue($benefitType, $setting->benefit_value);
        $availableAt = $this->localInstant($company, $at);
        $expiresAt = $setting->expiration_enabled
            ? $availableAt->addDays((int) $setting->expiration_days)->endOfDay()->utc()
            : null;
        $movement = null;

        if ($benefitType === LoyaltyRegistrationIncentive::TYPE_POINTS) {
            $account = app(LoyaltyAccountService::class)->getOrCreateAccount($customer, $company);
            $movement = app(LoyaltyAccountService::class)->addPoints($account, $benefitValue, LoyaltyMovement::TYPE_NEW_CUSTOMER, [
                'branch' => $branchId,
                'description' => 'Incentivo por registro',
                'source_type' => $qualificationSale ? Sale::class : null,
                'source_id' => $qualificationSale?->id,
                'event_key' => 'registration_incentive:'.$company->id.':'.$customer->id,
                'effective_at' => $availableAt,
                'metadata' => [
                    'incentive' => 'P14',
                    'configuration_phase' => 'P16',
                    'benefit_type' => $benefitType,
                    'benefit_value' => $benefitValue,
                    'award_timing' => $setting->award_timing,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
            ]);
        }

        return LoyaltyRegistrationIncentiveClaim::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'loyalty_movement_id' => $movement?->id,
            'benefit_type' => $benefitType,
            'benefit_value' => $benefitValue,
            'award_timing' => $setting->award_timing,
            'minimum_purchase_amount' => $setting->minimum_purchase_enabled ? $setting->minimum_purchase_amount : '0.0000',
            'allow_on_first_purchase' => $setting->allow_on_first_purchase,
            'bypass_redemption_minimum' => $setting->bypass_redemption_minimum,
            'awarded_points' => $movement ? $benefitValue : null,
            'branch_id' => $branchId,
            'qualification_sale_id' => $qualificationSale?->id,
            'available_at' => $availableAt->utc(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function meetsMinimum(LoyaltyRegistrationIncentive $setting, string $purchaseAmount): bool
    {
        return ! $setting->minimum_purchase_enabled
            || bccomp($this->nonNegativeDecimal($purchaseAmount), (string) $setting->minimum_purchase_amount, 4) >= 0;
    }

    private function hasPreviousCompletedPurchase(Customer $customer, Company $company, ?int $currentSaleId): bool
    {
        return Sale::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->where('status', Sale::STATUS_COMPLETED)
            ->when($currentSaleId !== null, fn ($query) => $query->whereKeyNot($currentSaleId))
            ->exists();
    }

    private function validateCustomerCompany(Customer $customer, Company $company): void
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }
    }

    private function localInstant(Company $company, CarbonInterface|string|null $at): CarbonImmutable
    {
        $timezone = in_array((string) $company->timezone, DateTimeZone::listIdentifiers(), true)
            ? (string) $company->timezone
            : (string) config('app.timezone');

        return $at instanceof CarbonInterface
            ? CarbonImmutable::instance($at)->setTimezone($timezone)
            : CarbonImmutable::parse($at ?? 'now', $timezone);
    }

    private function percentageOf(string $amount, string $percentage): string
    {
        $raw = bcdiv(bcmul($amount, $percentage, 8), '100', 8);

        return bcadd(bcadd($raw, '0.00005', 5), '0', 4);
    }

    private function evaluation(bool $eligible, ?string $reason, ?LoyaltyRegistrationIncentiveClaim $claim = null, string $discountAmount = '0.0000'): array
    {
        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'claim_id' => $claim?->id,
            'benefit_type' => $claim?->benefit_type,
            'benefit_value' => $claim?->benefit_value,
            'discount_amount' => $discountAmount,
            'bypass_redemption_minimum' => $eligible && (bool) $claim?->bypass_redemption_minimum,
            'expires_at' => $claim?->expires_at,
        ];
    }

    private function validateType(string $benefitType): string
    {
        if (! in_array($benefitType, LoyaltyRegistrationIncentive::TYPES, true)) {
            throw ValidationException::withMessages(['benefit_type' => 'El tipo de incentivo no es válido.']);
        }

        return $benefitType;
    }

    private function validateValue(string $benefitType, string|int $benefitValue): string
    {
        $value = trim((string) $benefitValue);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['benefit_value' => 'El valor debe ser un decimal con máximo cuatro decimales.']);
        }

        $value = bcadd($value, '0', 4);
        if (bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages(['benefit_value' => 'El valor del incentivo debe ser mayor que cero.']);
        }
        if (bccomp($value, '999999999999999.9999', 4) > 0) {
            throw ValidationException::withMessages(['benefit_value' => 'El valor del incentivo supera el máximo permitido.']);
        }
        if ($benefitType === LoyaltyRegistrationIncentive::TYPE_PERCENTAGE && bccomp($value, '100.0000', 4) > 0) {
            throw ValidationException::withMessages(['benefit_value' => 'El porcentaje del incentivo no puede superar 100%.']);
        }

        return $value;
    }

    private function positiveDecimal(string|int $value): string
    {
        $value = $this->nonNegativeDecimal($value);
        if (bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages(['purchase_amount' => 'El monto de compra debe ser mayor que cero.']);
        }

        return $value;
    }

    private function nonNegativeDecimal(string|int $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser un decimal con máximo cuatro decimales.']);
        }

        $value = bcadd($value, '0', 4);
        if (bccomp($value, '999999999999999.9999', 4) > 0) {
            throw ValidationException::withMessages(['amount' => 'El monto supera el máximo permitido.']);
        }

        return $value;
    }
}
