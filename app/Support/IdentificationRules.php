<?php

namespace App\Support;

/**
 * Reglas centralizadas de identificación tributaria (POS + Clientes).
 *
 * Límites exigidos (fuente única para POS + Clientes):
 * - 01 Cédula física: EXACTAMENTE 9 dígitos; visual X-XXXX-XXXX (máx. 11).
 * - 02 Cédula jurídica: EXACTAMENTE 10 dígitos; visual X-XXX-XXXXXX (máx. 12).
 * - 03 DIMEX: 11 o 12 dígitos, SIN guiones (máx. 12).
 * - 04 NITE: EXACTAMENTE 10 dígitos, SIN guiones (máx. 10).
 * - 05 Extranjero no domiciliado: máximo 20 caracteres alfanuméricos.
 *
 * La máscara visual con guiones nunca altera el valor normalizado que se
 * envía/consulta a Hacienda (siempre se usan solo dígitos).
 *
 * Compatibilidad: los valores heredados no numéricos de los tipos 01-04
 * (p. ej. "ID-123") solo se limitan por el tope histórico de 50, para no
 * bloquear la edición de clientes existentes.
 */
final class IdentificationRules
{
    public const TYPES = [
        '01' => ['label' => 'Cédula Física', 'digits' => 9, 'groups' => [1, 4, 4], 'example' => '1-0987-0988', 'mask' => true, 'max_length' => 11],
        '02' => ['label' => 'Cédula Jurídica', 'digits' => 10, 'groups' => [1, 3, 6], 'example' => '3-101-000000', 'mask' => true, 'max_length' => 12],
        '03' => ['label' => 'DIMEX', 'digits' => [11, 12], 'groups' => [], 'example' => '11 o 12 dígitos', 'mask' => false, 'max_length' => 12],
        '04' => ['label' => 'NITE', 'digits' => 10, 'groups' => [], 'example' => '10 dígitos', 'mask' => false, 'max_length' => 10],
        '05' => ['label' => 'Extranjero no domiciliado', 'digits' => null, 'groups' => [], 'example' => 'Pasaporte u otra identificación', 'mask' => false, 'max_length' => 20],
    ];

    public const LEGACY_MAX_LENGTH = 50;

    public static function types(): array
    {
        return self::TYPES;
    }

    public static function label(?string $type): string
    {
        return self::TYPES[$type]['label'] ?? '';
    }

    public static function example(?string $type): string
    {
        return self::TYPES[$type]['example'] ?? 'Opcional si no tiene';
    }

    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function isNumeric(string $value): bool
    {
        return preg_match('/^[0-9\-\.\s]+$/', $value) === 1;
    }

    public static function maxLength(?string $type): int
    {
        return self::TYPES[$type]['max_length'] ?? self::LEGACY_MAX_LENGTH;
    }

    /**
     * @return list<int>|null Cantidad(es) de dígitos oficiales; null cuando no aplica regla numérica.
     */
    public static function digitCounts(?string $type): ?array
    {
        $digits = self::TYPES[$type]['digits'] ?? null;

        if ($digits === null) {
            return null;
        }

        return is_array($digits) ? array_values($digits) : [$digits];
    }

    public static function isValid(?string $type, ?string $value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return true;
        }

        $counts = self::digitCounts($type);

        // Tipos sin regla numérica (05 Extranjero y tipos desconocidos):
        // el máximo aplica siempre, sea alfanumérico o numérico.
        if ($counts === null) {
            return mb_strlen($value) <= self::maxLength($type);
        }

        if (mb_strlen($value) > self::LEGACY_MAX_LENGTH) {
            return false;
        }

        if (! self::isNumeric($value)) {
            return true;
        }

        if (mb_strlen($value) > self::maxLength($type)) {
            return false;
        }

        return in_array(strlen(self::digits($value)), $counts, true);
    }

    public static function message(?string $type, ?string $value): string
    {
        $value = trim((string) $value);
        $max = self::maxLength($type);
        $counts = self::digitCounts($type);

        if ($counts === null) {
            return isset(self::TYPES[$type])
                ? 'La identificación ('.self::label($type).') no puede superar los '.$max.' caracteres.'
                : 'La identificación no puede superar los '.self::LEGACY_MAX_LENGTH.' caracteres.';
        }

        if (mb_strlen($value) > self::LEGACY_MAX_LENGTH) {
            return 'La identificación no puede superar los '.self::LEGACY_MAX_LENGTH.' caracteres.';
        }

        if (self::isNumeric($value) && mb_strlen($value) > $max) {
            return 'La identificación ('.self::label($type).') no puede superar los '.$max.' caracteres.';
        }

        return 'La identificación ('.self::label($type).') debe tener '.self::digitRule($type)
            .' sin contar guiones; se encontraron '.strlen(self::digits($value)).' dígitos.';
    }

    public static function digitRule(?string $type): string
    {
        $counts = self::digitCounts($type) ?? [];

        if (count($counts) === 1) {
            return $counts[0].' dígitos';
        }

        return implode(' o ', $counts).' dígitos';
    }

    /**
     * Aplica la máscara oficial al valor capturado; solo se reformattea
     * cuando el valor es numérico (los datos heredados se conservan tal cual).
     */
    public static function format(?string $type, string $value): string
    {
        $value = trim($value);

        if ($value === '' || ! isset(self::TYPES[$type]) || ! self::isNumeric($value)) {
            return $value;
        }

        $counts = self::digitCounts($type);

        if ($counts === null) {
            return $value;
        }

        $digits = substr(self::digits($value), 0, max($counts));
        $groups = self::TYPES[$type]['groups'];

        if ($groups === []) {
            return $digits;
        }

        $parts = [];
        $offset = 0;

        foreach ($groups as $group) {
            if ($offset >= strlen($digits)) {
                break;
            }

            $parts[] = substr($digits, $offset, $group);
            $offset += $group;
        }

        if ($offset < strlen($digits)) {
            $parts[] = substr($digits, $offset);
        }

        return implode('-', $parts);
    }

    /**
     * Identificación completa y válida para disparar la consulta a Hacienda.
     */
    public static function isComplete(?string $type, ?string $value): bool
    {
        $value = trim((string) $value);

        if ($value === '' || ! self::isNumeric($value)) {
            return false;
        }

        $counts = self::digitCounts($type);
        $length = strlen(self::digits($value));

        if ($counts === null) {
            return $length >= 9 && $length <= 12;
        }

        return in_array($length, $counts, true);
    }
}
