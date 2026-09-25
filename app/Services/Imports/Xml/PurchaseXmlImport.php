<?php

namespace App\Services\Imports\Xml;

use SimpleXMLElement;

class PurchaseXmlImport
{
    private const TAX_FIELDS = [
        'Codigo', 'CodigoTarifaIVA', 'Tarifa', 'Monto', 'FactorCalculoIVA',
        'Exoneracion',
    ];

    public function read($file)
    {
        $xml = simplexml_load_file($file);

        if (!$xml) {
            throw new \Exception('XML inválido');
        }

        $lines = [];

        if (isset($xml->DetalleServicio->LineaDetalle)) {
            foreach ($xml->DetalleServicio->LineaDetalle as $line) {
                $impuestos = $this->readTaxes($line);

                $lines[] = [
                    'cabys' => (string) ($line->CodigoCABYS ?? null),
                    'name' => (string) ($line->Detalle ?? null),
                    'quantity' => (float) ($line->Cantidad ?? 0),
                    'unit' => (string) ($line->UnidadMedida ?? null),
                    'unit_cost' => (float) ($line->PrecioUnitario ?? 0),
                    'tax_rate' => $this->primaryRate($impuestos),
                    'impuestos' => $impuestos,
                ];
            }
        }

        return [
            'clave' => (string) ($xml->Clave ?? null),
            'fecha' => (string) ($xml->FechaEmision ?? null),
            'proveedor' => [
                'nombre' => (string) ($xml->Emisor->Nombre ?? null),
                'identificacion' => (string) ($xml->Emisor->Identificacion->Numero ?? null),
            ],
            'lineas' => $lines,
        ];
    }

    /**
     * Lee TODOS los Impuesto de la línea (IVA + impuestos adicionales),
     * preservando Codigo, CodigoTarifaIVA/Tarifa, Monto, FactorCalculoIVA,
     * TarifaExoneracion, la Exoneración completa y cualquier otro elemento
     * especial disponible, sin colapsarlos en la primera tarifa.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readTaxes(SimpleXMLElement $line): array
    {
        if (!isset($line->Impuesto)) {
            return [];
        }

        $impuestos = [];

        foreach ($line->Impuesto as $impuesto) {
            $codigo = $this->text($impuesto->Codigo ?? null);
            $codigoTarifa = $this->text($impuesto->CodigoTarifaIVA ?? null);
            $tarifa = $impuesto->Tarifa ?? null;
            $monto = $impuesto->Monto ?? null;
            $factor = $impuesto->FactorCalculoIVA ?? null;
            $exoneracion = $this->readExoneration($impuesto);

            $otros = [];
            foreach ($impuesto->children() as $childName => $childValue) {
                if (in_array((string) $childName, self::TAX_FIELDS, true)) {
                    continue;
                }
                $otros[(string) $childName] = (string) $childValue;
            }

            $impuestos[] = [
                'codigo' => $codigo,
                'codigo_tarifa' => $codigoTarifa,
                'tarifa' => $tarifa !== null && is_numeric((string) $tarifa) ? (float) (string) $tarifa : null,
                'monto' => $monto !== null && is_numeric((string) $monto) ? (float) (string) $monto : null,
                'factor' => $factor !== null && is_numeric((string) $factor) ? (float) (string) $factor : null,
                'exoneracion' => $exoneracion,
                'otros' => $otros === [] ? null : $otros,
            ];
        }

        return $impuestos;
    }

    /**
     * @return array<string, string>|null
     */
    private function readExoneration(SimpleXMLElement $impuesto): ?array
    {
        if (!isset($impuesto->Exoneracion)) {
            return null;
        }

        $exoneracion = $impuesto->Exoneracion;
        $data = [];

        foreach ([
            'NumeroDocumento' => 'documento',
            'FechaEmision' => 'fecha_emision',
            'NombreInstitucion' => 'nombre_institucion',
            'MontoImpuesto' => 'monto_impuesto',
            'PorcentajeCompra' => 'porcentaje_compra',
            'FechaVence' => 'fecha_vencimiento',
        ] as $source => $target) {
            $value = $this->text($exoneracion->{$source} ?? null);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }

        // TarifaExoneracion puede vivir dentro de la Exoneración en algunas versiones.
        $tarifaExoneracion = $this->text($exoneracion->TarifaExoneracion ?? null);
        if ($tarifaExoneracion !== null) {
            $data['tarifa_exoneracion'] = $tarifaExoneracion;
        }

        return $data === [] ? null : $data;
    }

    private function primaryRate(array $impuestos): ?float
    {
        foreach ($impuestos as $impuesto) {
            if ($impuesto['codigo'] === '01' && $impuesto['tarifa'] !== null) {
                return $impuesto['tarifa'];
            }
        }

        foreach ($impuestos as $impuesto) {
            if ($impuesto['tarifa'] !== null) {
                return $impuesto['tarifa'];
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
