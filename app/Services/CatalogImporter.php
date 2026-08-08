<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CatalogImporter
{
    public function import(string $file, callable $callback): int
    {
        if (! file_exists($file)) {
            throw new \Exception("Archivo no encontrado: {$file}");
        }

        $handle = fopen($file, 'r');

        // Detectar separador automáticamente
        $firstLine = fgets($handle);

        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',')
            ? ';'
            : ',';

        rewind($handle);

        // Saltar encabezado
        fgetcsv($handle, 0, $delimiter);

        $total = 0;

        DB::beginTransaction();

        try {

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {

                if (count(array_filter($row)) === 0) {
                    continue;
                }

                $row = array_map(fn ($value) => trim($value), $row);

                $callback($row);

                $total++;
            }

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            fclose($handle);

            throw $e;
        }

        fclose($handle);

        return $total;
    }
}