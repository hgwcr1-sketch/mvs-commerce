<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyRegistrationIncentive;
use App\Models\LoyaltyRegistrationIncentiveClaim;
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

    public function configure(Company $company, bool $enabled, string $benefitType, string|int $benefitValue): LoyaltyRegistrationIncentive
    {
        $setting = $this->settingFor($company);
        $setting->update([
            'is_enabled' => $enabled,
            'benefit_type' => $this->validateType($benefitType),
            'benefit_value' => $this->validateValue($benefitType, $benefitValue),
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
            if (! $setting?->is_enabled) {
                return null;
            }

            $existing = LoyaltyRegistrationIncentiveClaim::where('company_id', $company->id)->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($existing) {
                return null;
            }

            $benefitType = $this->validateType($setting->benefit_type);
            $benefitValue = $this->validateValue($benefitType, $setting->benefit_value);
            $movement = null;

            if ($benefitType === LoyaltyRegistrationIncentive::TYPE_POINTS) {
                $accountService = app(LoyaltyAccountService::class);
                $account = $accountService->getOrCreateAccount($customer, $company);
                $movement = $accountService->addPoints($account, $benefitValue, LoyaltyMovement::TYPE_NEW_CUSTOMER, [
                    'branch' => $branchId,
                    'description' => 'Incentivo por registro',
                    'event_key' => 'registration_incentive:'.$company->id.':'.$customer->id,
                    'metadata' => ['incentive' => 'P14', 'configuration_phase' => 'P15', 'benefit_type' => $benefitType, 'benefit_value' => $benefitValue],
                ]);
            }

            return LoyaltyRegistrationIncentiveClaim::create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'loyalty_movement_id' => $movement?->id,
                'benefit_type' => $benefitType,
                'benefit_value' => $benefitValue,
                'awarded_points' => $movement ? $benefitValue : null,
                'branch_id' => $branchId,
            ]);
        });
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
}
