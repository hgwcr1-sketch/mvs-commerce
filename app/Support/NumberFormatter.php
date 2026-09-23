<?php

namespace App\Support;

class NumberFormatter
{
    /**
     * Quita los ceros decimales finales de un valor numérico sin perder precisión.
     *
     * No convierte el valor a float; trabaja sobre su representación string.
     */
    public static function trimDecimalZeros(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $numeric = (string) $value;

        if (! is_numeric($numeric)) {
            return '0';
        }

        if (str_contains($numeric, '.')) {
            $numeric = rtrim(rtrim($numeric, '0'), '.');
        }

        return $numeric === '' || $numeric === '-' ? '0' : $numeric;
    }
}
