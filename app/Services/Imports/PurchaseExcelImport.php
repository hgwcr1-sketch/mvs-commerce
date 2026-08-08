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

    'name' =>
        trim($row[2]),

    'quantity' =>
        (float) $row[3],

    'cost' =>
        (float) $row[4],

];

        }


        return $rows;

    }

}