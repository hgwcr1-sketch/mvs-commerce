<?php

namespace App\Services\Facturaencr;

class FacturaencrUnitMapper
{
    private const MVS_TO_FACTURAENCR = [
        'un' => 'Unid',
        'kg' => 'kg',
    ];

    public function map(string $mvsUnit): string
    {
        if (!isset(self::MVS_TO_FACTURAENCR[$mvsUnit])) {
            throw new \InvalidArgumentException("Unidad de medida no soportada por Facturaencr: {$mvsUnit}");
        }

        return self::MVS_TO_FACTURAENCR[$mvsUnit];
    }

    public function isSupported(string $mvsUnit): bool
    {
        return isset(self::MVS_TO_FACTURAENCR[$mvsUnit]);
    }
}