<?php

namespace App\Support;

/**
 * Número a letras en español (Costa Rica). Uso: montos en colones
 * de comprobantes fiscales ("CIENTO DOCE MIL ... COLONES 00/100").
 */
class NumberToWordsEs
{
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE',
        'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIÚN', 'VEINTIDÓS', 'VEINTITRÉS',
        'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    private const DECENAS = [
        '', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA',
    ];

    private const CENTENAS = [
        '', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS',
    ];

    public static function convert(float|int $amount): string
    {
        $cents = (int) round(((float) $amount - floor((float) $amount)) * 100);
        $whole = (int) floor((float) $amount);

        $words = $whole === 0 ? 'CERO' : self::integer($whole);

        return $words.' COLONES '.str_pad((string) $cents, 2, '0', STR_PAD_LEFT).'/100';
    }

    private static function integer(int $n): string
    {
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        if ($n < 100) {
            $ten = intdiv($n, 10);
            $unit = $n % 10;
            if ($unit === 0) {
                return self::DECENAS[$ten];
            }
            if ($unit === 1) {
                return self::DECENAS[$ten].' Y UNO';
            }

            return self::DECENAS[$ten].' Y '.self::UNIDADES[$unit];
        }
        if ($n === 100) {
            return 'CIEN';
        }
        if ($n < 1000) {
            $hundreds = intdiv($n, 100);
            $rest = $n % 100;

            return self::CENTENAS[$hundreds].($rest > 0 ? ' '.self::integer($rest) : '');
        }
        if ($n < 1000000) {
            $thousands = intdiv($n, 1000);
            $rest = $n % 1000;
            $prefix = $thousands === 1 ? 'MIL' : self::integer($thousands).' MIL';

            return $prefix.($rest > 0 ? ' '.self::integer($rest) : '');
        }
        if ($n < 1000000000) {
            $millions = intdiv($n, 1000000);
            $rest = $n % 1000000;
            $prefix = $millions === 1 ? 'UN MILLÓN' : self::integer($millions).' MILLONES';

            return $prefix.($rest > 0 ? ' '.self::integer($rest) : '');
        }

        $billions = intdiv($n, 1000000000);
        $rest = $n % 1000000000;

        return ($billions === 1 ? 'UN BILLÓN' : self::integer($billions).' BILLONES').($rest > 0 ? ' '.self::integer($rest) : '');
    }
}
