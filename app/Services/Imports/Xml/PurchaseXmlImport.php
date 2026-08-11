<?php

namespace App\Services\Imports\Xml;

use SimpleXMLElement;

class PurchaseXmlImport
{

    public function read($file)
    {

        $xml = simplexml_load_file($file);


        if (!$xml) {

            throw new \Exception(
                'XML inválido'
            );

        }

        $lines = [];

        if (isset($xml->DetalleServicio->LineaDetalle)) {

            foreach ($xml->DetalleServicio->LineaDetalle as $line) {

                $lines[] = [

                    'cabys' =>
                        (string) ($line->CodigoCABYS ?? null),

                    'name' =>
                        (string) ($line->Detalle ?? null),

                    'quantity' =>
                        (float) ($line->Cantidad ?? 0),

                    'unit' =>
                        (string) ($line->UnidadMedida ?? null),

                    'unit_cost' =>
                        (float) ($line->PrecioUnitario ?? 0),

                    'tax_rate' =>
                        isset($line->Impuesto->Tarifa)
                            ? (float) $line->Impuesto->Tarifa
                            : null,

                ];

            }

        }


        return [

            'clave' =>
                (string) ($xml->Clave ?? null),


            'fecha' =>
                (string) ($xml->FechaEmision ?? null),


            'proveedor' => [

                'nombre' =>
                    (string) ($xml->Emisor->Nombre ?? null),

                'identificacion' =>
                    (string) ($xml->Emisor->Identificacion->Numero ?? null),

            ],


            'lineas' => $lines

        ];

    }

}
