<?php

namespace App\Services\Facturaencr;

/**
 * Descuento por línea al contrato de FacturaEnCR
 * (https://facturaencr.com/docs, API v2 / Hacienda v4.4):
 *
 *   "descuento": [ { "montoDescuento": 100, "codigoDescuento": "07" } ]
 *
 * El campo es SIEMPRE una lista de objetos. Un escalar (formato anterior)
 * produce `validation_error` en el proveedor apuntando a
 * `detalle.N.descuento`, y `codigoDescuento` es obligatorio en todo
 * descuento (sin él Hacienda rechaza con -41).
 *
 * Serialización única compartida por FE/TE (FacturaencrInvoiceMapper) y
 * NC/ND (FacturaencrAdjustmentMapper): un solo lugar para el contrato.
 */
class FacturaencrDiscountMapper
{
    /** Catálogo oficial de descuentos del Anexo v4.4. */
    public const CODES = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '99'];

    /**
     * Código por defecto: 07 "Descuento Comercial", el neutro para el
     * descuento de línea del POS (no afirma regalía, volumen ni temporada).
     */
    public const DEFAULT_CODE = '07';

    /**
     * @return array<int, array{montoDescuento: float, codigoDescuento: string}>
     */
    public function map(string|float|int|null $amount): array
    {
        if (bccomp($this->money($amount), '0', 4) <= 0) {
            return [];
        }

        return [[
            'montoDescuento' => (float) $amount,
            'codigoDescuento' => self::DEFAULT_CODE,
        ]];
    }

    public function isValidCode(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /**
     * Preflight del contrato sobre `detalle[]` ya construido: detecta un
     * descuento inválido ANTES del POST, nunca se envía payload malformado.
     *
     * @return array<string, string> errores con la ruta punto del proveedor
     *                               (`detalle.N.descuento`) para que el
     *                               mensaje coincida con el del proveedor.
     */
    public function contractErrors(array $detalle): array
    {
        $errors = [];

        foreach ($detalle as $index => $line) {
            if (! is_array($line) || ! array_key_exists('descuento', $line)) {
                continue;
            }

            $path = "detalle.{$index}.descuento";
            $value = $line['descuento'];

            if (! is_array($value) || $value === [] || ! array_is_list($value)) {
                $errors[$path] = 'El descuento de la línea debe ser una lista de objetos {montoDescuento, codigoDescuento}.';

                continue;
            }

            foreach ($value as $position => $entry) {
                $entryPath = "{$path}.{$position}";

                if (! is_array($entry)) {
                    $errors[$entryPath] = 'Cada descuento debe ser un objeto {montoDescuento, codigoDescuento}.';

                    continue;
                }

                $monto = $entry['montoDescuento'] ?? null;

                if (! is_numeric($monto) || bccomp($this->money($monto), '0', 4) <= 0) {
                    $errors["{$entryPath}.montoDescuento"] = 'montoDescuento debe ser un importe numérico mayor que 0.';
                }

                $code = $entry['codigoDescuento'] ?? null;

                if (! is_string($code) || ! $this->isValidCode($code)) {
                    $errors["{$entryPath}.codigoDescuento"] = 'codigoDescuento es obligatorio y debe pertenecer al catálogo oficial (01-09, 99).';
                }
            }
        }

        return $errors;
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd((string) $value, '0', 4);
    }
}
