<?php

require dirname(__DIR__).'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$base = dirname(__DIR__);
$spreadsheet = IOFactory::load($base.'/docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx');

$searchSheets = ['Portal Detalle', 'Cronograma Maestro', 'Decisiones', 'Reconciliación'];

foreach ($searchSheets as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    if (! $sheet) {
        continue;
    }
    echo "=== Hoja: $sheetName ===\n";
    $rows = $sheet->getHighestRow();
    $cols = $sheet->getHighestColumn();
    $colIndex = Coordinate::columnIndexFromString($cols);
    for ($r = 1; $r <= $rows; $r++) {
        $rowData = [];
        for ($c = 1; $c <= $colIndex; $c++) {
            $val = $sheet->getCell(Coordinate::stringFromColumnIndex($c).$r)->getValue();
            if ($val === null) {
                $val = '';
            }
            $rowData[] = $val;
        }
        $line = implode(' | ', array_map(function ($v) {
            return str_replace(["\n", "\r"], ' ', (string) $v);
        }, $rowData));
        $line = trim($line);
        if ($line !== str_repeat(' | ', $colIndex - 1)) {
            echo "L$r: ".$line."\n";
        }
    }
    echo "\n";
}
