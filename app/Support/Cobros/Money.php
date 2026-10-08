<?php

namespace App\Support\Cobros;

final class Money
{
    /** Round for display only, half away from zero, without float conversion. */
    public static function format(string $amount): string
    {
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $amount, $parts)) {
            throw new \InvalidArgumentException('Invalid decimal amount.');
        }

        $fraction = str_pad($parts[3] ?? '', 3, '0');
        $absolute = $parts[2].'.'.substr($fraction, 0, 2);
        $rounded = bcadd($absolute, $fraction[2] >= '5' ? '0.01' : '0', 2);
        [$whole, $cents] = explode('.', $rounded);
        $sign = $parts[1] === '-' && bccomp($rounded, '0', 2) !== 0 ? '-' : '';

        return $sign.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$cents;
    }

    /**
     * Símbolo de moneda según configuración del país (CRC: ₡).
     * Centraliza la presentación: ningún consumidor debe hardcodear ₡.
     */
    public static function symbol(string $currencyCode = 'CRC'): string
    {
        return strtoupper($currencyCode) === 'CRC' ? '₡' : $currencyCode.' ';
    }

    /**
     * Formato humano por moneda (Costa Rica: ₡200.000,00). Solo presentación;
     * el dinero sigue siendo DECIMAL(19,4) en el dominio.
     */
    public static function human(string $amount, string $currencyCode = 'CRC'): string
    {
        $formatted = self::format($amount);
        $negative = str_starts_with($formatted, '-');
        [$whole, $cents] = array_pad(explode('.', ltrim($formatted, '-'), 2), 2, '00');
        $number = ($negative ? '-' : '').str_replace(',', '.', $whole).','.$cents;

        return self::symbol($currencyCode).$number;
    }
}
