<?php

require dirname(__DIR__).'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$base = dirname(__DIR__);
$spreadsheet = IOFactory::load($base.'/docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx');
$sheets = $spreadsheet->getSheetNames();
foreach ($sheets as $s) {
    echo $s.PHP_EOL;
}
