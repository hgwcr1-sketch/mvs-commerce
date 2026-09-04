<?php

require dirname(__DIR__).'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$base = dirname(__DIR__);
$path = $base.'/docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx';
$reader = IOFactory::createReader('Xlsx');
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($path);

$searchSheets = ['Resumen', 'Fidelización Pendiente', 'Portal Detalle'];
foreach ($searchSheets as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    if (! $sheet) {
        continue;
    }
    echo "=== $sheetName (first 10 rows with P37.1 mentions) ===\n";
    $rows = $sheet->getHighestRow();
    $cols = $sheet->getHighestColumn();
    $colIndex = Coordinate::columnIndexFromString($cols);
    $found = 0;
    for ($r = 1; $r <= $rows && $found < 10; $r++) {
        $hasP371 = false;
        $rowData = [];
        for ($c = 1; $c <= $colIndex; $c++) {
            $val = $sheet->getCell(Coordinate::stringFromColumnIndex($c).$r)->getValue();
            if ($val === null) {
                $val = '';
            }
            $rowData[] = (string) $val;
            if (stripos((string) $val, 'P37.1') !== false) {
                $hasP371 = true;
            }
        }
        if ($hasP371) {
            echo "L$r: ".implode(' | ', array_map(function ($v) {
                return str_replace(["\n", "\r"], ' ', $v);
            }, $rowData))."\n";
            $found++;
        }
    }
    if ($found === 0) {
        echo "(no P37.1 mentions found)\n";
    }
    echo "\n";
}
