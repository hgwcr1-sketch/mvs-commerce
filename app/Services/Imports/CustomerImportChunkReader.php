<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Bounded cell hydration, including legacy XLS/CSV, using the installed reader. */
class CustomerImportChunkReader
{
    public function read(string $path, int $start, int $size): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $info = $reader->listWorksheetInfo($path)[0];
        $reader->setReadDataOnly(true);
        $reader->setValueBinder(new StringValueBinder);
        $reader->setLoadSheetsOnly($info['worksheetName']);
        $reader->setReadFilter(new class($start, $size) implements IReadFilter
        {
            public function __construct(private int $start, private int $size) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === 1 || ($row >= $this->start && $row < $this->start + $this->size);
            }
        });
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheet(0);
            $end = min((int) $info['totalRows'], $start + $size - 1);
            $column = Coordinate::stringFromColumnIndex(max(1, (int) $info['totalColumns']));

            return [
                'headers' => $sheet->rangeToArray('A1:'.$column.'1', null, false, false)[0],
                'rows' => $end >= $start ? $sheet->rangeToArray('A'.$start.':'.$column.$end, null, false, false) : [],
                'last_row' => (int) $info['totalRows'],
                'end' => $end,
            ];
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
