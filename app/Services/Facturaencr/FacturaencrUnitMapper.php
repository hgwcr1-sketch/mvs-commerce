<?php

namespace App\Services\Facturaencr;

class FacturaencrUnitMapper
{
    /**
     * Códigos oficiales de unidadMedida documentados en el contrato
     * Facturaencr/OpenAPI (Hacienda v4.4, case-sensitive en el payload).
     */
    private const OFFICIAL_CODES = [
        'unid' => 'Unid',
        'sp' => 'Sp',
        'kg' => 'kg',
        'g' => 'g',
        'l' => 'L',
        'ml' => 'mL',
        'm' => 'm',
        'cm' => 'cm',
        'm2' => 'm2',
        'm3' => 'm3',
        'h' => 'h',
        'd' => 'd',
        'al' => 'Al',
        'os' => 'Os',
    ];

    /**
     * Abreviaturas/nombres habituales en MVS (la tabla units guarda
     * abreviaturas en mayúsculas generadas por importación) mapeados al
     * código oficial equivalente. No agrega códigos fiscales nuevos.
     */
    private const MVS_TO_FACTURAENCR = [
        'un' => 'Unid',
        'u' => 'Unid',
        'und' => 'Unid',
        'unidad' => 'Unid',
        'unidades' => 'Unid',
        'servicio' => 'Sp',
        'servicios' => 'Sp',
        'kgs' => 'kg',
        'kilogramo' => 'kg',
        'kilogramos' => 'kg',
        'gr' => 'g',
        'gramo' => 'g',
        'gramos' => 'g',
        'lt' => 'L',
        'litro' => 'L',
        'litros' => 'L',
        'mililitro' => 'mL',
        'mililitros' => 'mL',
        'mt' => 'm',
        'metro' => 'm',
        'metros' => 'm',
        'centimetro' => 'cm',
        'centimetros' => 'cm',
        'm²' => 'm2',
        'm³' => 'm3',
        'hr' => 'h',
        'hora' => 'h',
        'horas' => 'h',
        'dia' => 'd',
        'día' => 'd',
        'dias' => 'd',
        'días' => 'd',
        'alquiler' => 'Al',
        'otro' => 'Os',
        'otros' => 'Os',
    ];

    public function map(string $mvsUnit): string
    {
        $code = $this->resolve($mvsUnit);

        if ($code === null) {
            throw new \InvalidArgumentException("Unidad de medida no soportada por Facturaencr: {$mvsUnit}");
        }

        return $code;
    }

    public function isSupported(string $mvsUnit): bool
    {
        return $this->resolve($mvsUnit) !== null;
    }

    private function resolve(string $mvsUnit): ?string
    {
        $key = mb_strtolower(trim($mvsUnit));

        if ($key === '') {
            return null;
        }

        return self::OFFICIAL_CODES[$key] ?? self::MVS_TO_FACTURAENCR[$key] ?? null;
    }
}
