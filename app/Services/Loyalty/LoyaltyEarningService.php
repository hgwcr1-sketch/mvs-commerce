<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use Illuminate\Validation\ValidationException;

class LoyaltyEarningService
{
    private const SCALE = 4;

    public function __construct(
        private readonly LoyaltyAccountService $accountService,
        private readonly LoyaltyMultiplierResolver $multiplierResolver,
    ) {}

    public function earnFromEligibleAmount(
        Customer $customer,
        Company $company,
        string|int $eligibleAmount,
        array $context = [],
    ): ?LoyaltyMovement {
        $this->validateCompanyAndCustomer($company, $customer);
        $eligibleAmount = $this->decimal($eligibleAmount, 'eligible_amount');

        if (bccomp($eligibleAmount, '0', self::SCALE) < 0) {
            throw ValidationException::withMessages(['eligible_amount' => 'El monto elegible no puede ser negativo.']);
        }

        $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();
        if ($setting === null) {
            throw ValidationException::withMessages(['loyalty' => 'La empresa no tiene configuración de Fidelización.']);
        }
        if (! $setting->is_active) {
            throw ValidationException::withMessages(['loyalty' => 'Fidelización está desactivada para la empresa.']);
        }

        $percentage = $this->decimal($setting->earning_percentage, 'earning_percentage');
        if (bccomp($percentage, '0', self::SCALE) <= 0 || bccomp($percentage, '100', self::SCALE) > 0) {
            throw ValidationException::withMessages(['earning_percentage' => 'El porcentaje de acumulación debe ser mayor que cero y no superar 100%.']);
        }

        $pointValue = $this->decimal($setting->point_value, 'point_value');
        if (bccomp($pointValue, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages(['point_value' => 'El valor del punto debe ser mayor que cero.']);
        }

        if (bccomp($eligibleAmount, '0', self::SCALE) === 0
            || (($context['is_offer'] ?? false) && ! $setting->earn_on_offers)) {
            return null;
        }

        $basePoints = $this->calculatePoints($eligibleAmount, $percentage);
        $effectiveAt = $context['effective_at'] ?? now();
        $multiplier = $this->multiplierResolver->resolve($company, $context['branch'] ?? $context['branch_id'] ?? null, $effectiveAt);
        $factor = $multiplier?->multiplier ?? '1.0000';
        $points = $this->multiplyPoints($basePoints, $factor);
        if (bccomp($points, '0', self::SCALE) === 0) {
            return null;
        }

        $account = $this->accountService->getOrCreateAccount($customer, $company, $context['user'] ?? null);
        $metadata = array_merge($context['metadata'] ?? [], [
            'base_points' => $basePoints,
            'multiplier' => bcadd((string) $factor, '0', self::SCALE),
            'multiplier_id' => $multiplier?->id,
            'multiplier_name' => $multiplier?->name,
            'final_points' => $points,
        ]);

        return $this->accountService->addPoints($account, $points, LoyaltyMovement::TYPE_PURCHASE, [
            'branch' => $context['branch'] ?? $context['branch_id'] ?? null,
            'user' => $context['user'] ?? $context['user_id'] ?? null,
            'base_amount' => $eligibleAmount,
            'earning_percentage' => $percentage,
            'point_value' => $pointValue,
            'description' => $context['description'] ?? 'Puntos por compra',
            'source_type' => $context['source_type'] ?? null,
            'source_id' => $context['source_id'] ?? null,
            'event_key' => $context['event_key'] ?? null,
            'effective_at' => $effectiveAt,
            'qualifying_purchase_at' => $effectiveAt,
            'metadata' => $metadata,
        ]);
    }

    private function calculatePoints(string $eligibleAmount, string $percentage): string
    {
        $product = bcmul($eligibleAmount, $percentage, 8);
        $unrounded = bcdiv($product, '100', 8);

        return bcadd($unrounded, '0.00005', self::SCALE);
    }

    private function multiplyPoints(string $basePoints, string $multiplier): string
    {
        $unrounded = bcmul($basePoints, $multiplier, 8);

        return bcadd($unrounded, '0.00005', self::SCALE);
    }

    private function validateCompanyAndCustomer(Company $company, Customer $customer): void
    {
        if (! Company::query()->whereKey($company->id)->exists()) {
            throw ValidationException::withMessages(['company' => 'La empresa no existe.']);
        }
        $persistedCustomer = Customer::withTrashed()->find($customer->id);
        if ($persistedCustomer === null) {
            throw ValidationException::withMessages(['customer' => 'El cliente no existe.']);
        }
        if ((int) $persistedCustomer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }
    }

    private function decimal(string|int $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'El valor debe ser decimal y tener como mÃ¡ximo cuatro decimales.']);
        }

        return bcadd($value, '0', self::SCALE);
    }
}
