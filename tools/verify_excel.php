<?php

require dirname(__DIR__).'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$base = dirname(__DIR__);
$path = $base.'/docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx';

try {
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
} catch (Throwable $e) {
    echo 'Read error: '.$e->getMessage()."\n";
    exit(1);
}

$sheet = $spreadsheet->getSheetByName('Cronograma Maestro');
if (! $sheet) {
    echo "Sheet not found\n";
    exit(1);
}

echo "=== P37.1 row (46) ===\n";
$cols = $sheet->getHighestColumn();
$colIndex = Coordinate::columnIndexFromString($cols);
for ($c = 1; $c <= $colIndex; $c++) {
    $val = $sheet->getCell(Coordinate::stringFromColumnIndex($c).'46')->getValue();
    if ($val === null) {
        $val = '';
    }
    echo 'Col '.Coordinate::stringFromColumnIndex($c).': '.str_replace(["\n", "\r"], ' ', (string) $val)."\n";
}

$sheet2 = $spreadsheet->getSheetByName('Decisiones');
if ($sheet2) {
    echo "\n=== Decisiones last rows ===\n";
    $rows2 = $sheet2->getHighestRow();
    $cols2 = $sheet2->getHighestColumn();
    $colIndex2 = Coordinate::columnIndexFromString($cols2);
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
}
