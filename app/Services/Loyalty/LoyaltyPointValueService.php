<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\LoyaltySetting;
use Illuminate\Validation\ValidationException;

class LoyaltyPointValueService
{
    private const SCALE = 4;

    private const DEFAULT_POINT_VALUE = '1.0000';

    public function pointValue(Company $company): string
    {
        $value = LoyaltySetting::query()->where('company_id', $company->id)->value('point_value')
            ?? self::DEFAULT_POINT_VALUE;

        return $this->positiveDecimal($value, 'point_value');
    }

    public function moneyFromPoints(string|int $points, Company $company): string
    {
        $points = $this->nonNegativeDecimal($points, 'points');

        return bcmul($points, $this->pointValue($company), self::SCALE);
    }

    public function pointsForMoney(string|int $amount, Company $company): string
    {
        $amount = $this->nonNegativeDecimal($amount, 'amount');
        $raw = bcdiv($amount, $this->pointValue($company), 8);
        $truncated = bcadd($raw, '0', self::SCALE);

        return bccomp($raw, $truncated, 8) > 0
            ? bcadd($truncated, '0.0001', self::SCALE)
            : $truncated;
    }

    private function positiveDecimal(mixed $value, string $field): string
    {
        $value = $this->nonNegativeDecimal($value, $field);
        if (bccomp($value, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages([$field => 'El valor debe ser mayor que cero.']);
        }

        return $value;
    }

    private function nonNegativeDecimal(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'El valor debe tener como máximo cuatro decimales.']);
        }
        $decimal = bcadd($value, '0', self::SCALE);
        if (bccomp($decimal, '0', self::SCALE) < 0) {
            throw ValidationException::withMessages([$field => 'El valor no puede ser negativo.']);
        }

        return $decimal;
    }
}
