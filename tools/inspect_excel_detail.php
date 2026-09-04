<?php

require dirname(__DIR__).'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$base = dirname(__DIR__);
$path = $base.'/docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx';
$spreadsheet = IOFactory::load($path);

// Show Cronograma Maestro header and P37.1 row structure
$sheet = $spreadsheet->getSheetByName('Cronograma Maestro');
$rows = $sheet->getHighestRow();
$cols = $sheet->getHighestColumn();
$colIndex = Coordinate::columnIndexFromString($cols);

echo "=== Cronograma Maestro headers ===\n";
for ($c = 1; $c <= $colIndex; $c++) {
    echo 'Col '.Coordinate::stringFromColumnIndex($c).': '.$sheet->getCell(Coordinate::stringFromColumnIndex($c).'4')->getValue()."\n";
}

echo "\n=== P37.1 row (46) raw ===\n";
for ($c = 1; $c <= $colIndex; $c++) {
    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($c).'46');
    $val = $cell->getValue();
    if ($val === null) {
        $val = '';
    }
    echo 'Col '.Coordinate::stringFromColumnIndex($c).': '.str_replace(["\n", "\r"], ' ', (string) $val)."\n";
}

echo "\n=== Decisiones headers ===\n";
$sheet2 = $spreadsheet->getSheetByName('Decisiones');
$rows2 = $sheet2->getHighestRow();
$cols2 = $sheet2->getHighestColumn();
$colIndex2 = Coordinate::columnIndexFromString($cols2);
for ($c = 1; $c <= $colIndex2; $c++) {
    echo 'Col '.Coordinate::stringFromColumnIndex($c).': '.$sheet2->getCell(Coordinate::stringFromColumnIndex($c).'3')->getValue()."\n";
}

echo "\n=== Decisiones last rows ===\n";
for ($r = max(1, $rows2 - 5); $r <= $rows2; $r++) {
    echo "L$r: ";
    for ($c = 1; $c <= $colIndex2; $c++) {
        $val = $sheet2->getCell(Coordinate::stringFromColumnIndex($c).$r)->getValue();
        if ($val === null) {
            $val = '';
        }
        echo str_replace(["\n", "\r"], ' ', (string) $val).' | ';
    }
    echo "\n";
}
