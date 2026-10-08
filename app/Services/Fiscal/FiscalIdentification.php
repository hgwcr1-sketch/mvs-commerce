<?php

namespace App\Services\Fiscal;

/**
 * Normalización de identificaciones de contribuyentes (MF03).
 *
 * La API oficial `/fe/ae` acepta 9 a 12 dígitos numéricos. El usuario, en
 * cambio, escribe `1-2345-6789`, `123456789` o `1 2345 6789`. Comparar
 * identificaciones sin normalizar produce duplicados de cliente: el mismo
 * contribuyente guardado dos veces con dos cadenas distintas, y la consulta
 * oficial recortada por acentos o guiones.
 *
 * Normalizar es solo formato. NO se completa con ceros a la izquierda ni se
 * recorta longitud: el padding pertenece a las claves fiscales de 50
 * posiciones (documentadas en `MVS_FISCAL_REQUISITOS_HACIENDA.md` §3.2) y
 * aplicarlo aquí crearía identificaciones que Hacienda nunca vio.
 */
final class FiscalIdentification
{
    public const MIN_LENGTH = 9;

    public const MAX_LENGTH = 12;

    /**
     * @return string|null la identificación normalizada, o null si no tiene
     *                     forma de identificación válida.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return null;
        }

        return $digits;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * Enmascarado para logs: conserva los últimos cuatro dígitos, que son los
     * que el usuario necesita para reconocer, y oculta el resto.
     */
    public static function mask(?string $value): string
    {
        $digits = self::normalize($value);

        if ($digits === null) {
            return '***';
        }

        $length = strlen($digits);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($digits, -4);
    }
}
