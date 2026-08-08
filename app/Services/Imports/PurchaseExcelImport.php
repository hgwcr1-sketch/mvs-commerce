<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\IOFactory;

class PurchaseExcelImport
{

    public function read($file)
    {
        $spreadsheet = IOFactory::load($file);

        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];


        foreach ($sheet->toArray() as $index => $row) {


            // Saltar encabezado
            if ($index === 0) {
                continue;
            }


            // Saltar filas vacías
            if (empty($row[0])) {
                continue;
            }


            $rows[] = [

    'supplier' =>
        trim($row[0]),

    'code' =>
        trim($row[1]),

    'quantity' =>
        (float) $row[2],

    'cost' =>
        (float) $row[3],

];

        }


        return $rows;

    }

}