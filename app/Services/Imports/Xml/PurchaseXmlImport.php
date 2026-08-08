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


            'lineas' => []

        ];

    }

}