<?php

namespace App\Services\Sales;

use Illuminate\Validation\ValidationException;

/**
 * Reglas únicas de descuento compartidas por ventas POS y apartados.
 *
 * PosSaleProcessor y LayawayService delegan aquí para no duplicar
 * el cálculo de descuentos por producto ni del general.
 */
class DiscountRules
{
    /**
     * Resuelve un descuento (fijo o porcentual) contra una base.
     * Lanza ValidationException con clave 'discount' cuando el valor es inválido.
     */
    public static function resolveAmount(
        float $value,
        string $type,
        float $base,
        string $context,
    ): float {
        if ($value < 0) {
            throw ValidationException::withMessages([
                'discount' => "El descuento para {$context} no puede ser negativo.",
            ]);
        }

        if (! in_array(
            $type,
            ['fixed', 'percentage'],
            true,
        )) {
            throw ValidationException::withMessages([
                'discount' => "El tipo de descuento para {$context} no es válido.",
            ]);
        }

        if ($type === 'percentage') {
            if ($value > 100) {
                throw ValidationException::withMessages([
                    'discount' => "El descuento porcentual para {$context} no puede superar 100%.",
                ]);
            }

            return self::decimal4(
                $base * ($value / 100),
            );
        }

        if ($value > $base) {
            throw ValidationException::withMessages([
                'discount' => "El descuento fijo para {$context} supera el importe disponible.",
            ]);
        }

        return self::decimal4($value);
    }

    /**
     * Distribuye el descuento general entre las líneas en proporción a su base.
     *
     * @param  array<int, array{lineBase: float}>  $lines
     * @return array<int, float>
     */
    public static function allocateGeneral(
        array $lines,
        float $generalDiscount,
        float $baseAfterLineDiscounts,
    ): array {
        if ($generalDiscount <= 0) {
            return array_fill(
                0,
                count($lines),
                0.0,
            );
        }

        $allocations = [];
        $allocated = 0.0;
        $lastPositiveIndex = null;

        foreach ($lines as $index => $line) {
            if ($line['lineBase'] > 0) {
                $lastPositiveIndex = $index;
            }

            $share = $baseAfterLineDiscounts > 0
                ? self::decimal4(
                    $generalDiscount
                    * (
                        $line['lineBase']
                        / $baseAfterLineDiscounts
                    ),
                )
                : 0.0;

            $share = min(
                $share,
                $line['lineBase'],
            );

            $allocations[$index] = $share;

            $allocated = self::decimal4(
                $allocated + $share,
            );
        }

        $remainder = self::decimal4(
            $generalDiscount - $allocated,
        );

        if (
            $lastPositiveIndex !== null
            && abs($remainder) > 0.0000001
        ) {
            $adjusted = self::decimal4(
                $allocations[$lastPositiveIndex]
                + $remainder,
            );

            if (
                $adjusted < 0
                || $adjusted
                    > $lines[$lastPositiveIndex]['lineBase']
            ) {
                throw ValidationException::withMessages([
                    'discount_total' => 'No fue posible distribuir el descuento general de forma consistente.',
                ]);
            }

            $allocations[$lastPositiveIndex] =
                $adjusted;
        }

        return $allocations;
    }

    public static function decimal4(float $value): float
    {
        return round(
            $value,
            4,
            PHP_ROUND_HALF_UP,
        );
    }
}
