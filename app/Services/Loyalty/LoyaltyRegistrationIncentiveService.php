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
            ['is_enabled' => false],
        );
    }

    public function toggle(Company $company, bool $enabled): LoyaltyRegistrationIncentive
    {
        $setting = $this->settingFor($company);
        $setting->update(['is_enabled' => $enabled]);

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

        $setting = $this->settingFor($company);
        if (! $setting->is_enabled) {
            return null;
        }

        // Una sola vez por cliente (company+customer unique)
        if (LoyaltyRegistrationIncentiveClaim::where('company_id', $company->id)->where('customer_id', $customer->id)->exists()) {
            return null;
        }

        // Reutilizar motor existente: otorgar puntos via LoyaltyAccountService (compatible con F09 new_customer)
        return DB::transaction(function () use ($customer, $company, $branchId) {
            // Doble check dentro de transacción
            $existing = LoyaltyRegistrationIncentiveClaim::where('company_id', $company->id)->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($existing) {
                return null;
            }

            $points = self::P14_DEFAULT_POINTS; // P15 hará configurables el tipo y el valor.
            $accountService = app(LoyaltyAccountService::class);
            $account = $accountService->getOrCreateAccount($customer, $company);
            $movement = $accountService->addPoints($account, $points, LoyaltyMovement::TYPE_NEW_CUSTOMER, [
                'branch' => $branchId,
                'description' => 'Incentivo por registro',
                'event_key' => 'registration_incentive:'.$company->id.':'.$customer->id,
                'metadata' => ['incentive' => 'P14', 'benefit_type' => 'points', 'benefit_value' => $points],
            ]);

            return LoyaltyRegistrationIncentiveClaim::create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'loyalty_movement_id' => $movement->id,
                'benefit_type' => 'points',
                'benefit_value' => $points,
                'awarded_points' => $points,
                'branch_id' => $branchId,
            ]);
        });
    }
}
