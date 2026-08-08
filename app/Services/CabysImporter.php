<?php

namespace App\Services;

use App\Models\Cabys;

class CabysImporter
{
    public function import(string $file): int
    {
        if (! file_exists($file)) {
            throw new \Exception("No se encontró el archivo CABYS.");
        }

        $handle = fopen($file, 'r');

        if (! $handle) {
            throw new \Exception("No fue posible abrir el archivo CABYS.");
        }

        // Detectar separador
        $firstLine = fgets($handle);

        $delimiter = substr_count($firstLine, ';') >
                     substr_count($firstLine, ',')
                     ? ';'
                     : ',';

        rewind($handle);

        // Encabezados
        $headers = fgetcsv($handle, 0, $delimiter);

        $headers = array_map(function ($header) {
            return trim(preg_replace('/^\xEF\xBB\xBF/', '', $header));
        }, $headers);

        $count = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {

            if (count($row) < count($headers)) {
                continue;
            }

            $data = array_combine($headers, $row);

            if (!$data) {
                continue;
            }

            $code = trim($data['Categoría 9'] ?? '');

            if ($code == '') {
                continue;
            }

            Cabys::updateOrCreate(

                [
                    'code' => $code,
                ],

                [

                    'description' => trim($data['Descripción Categoría 9'] ?? ''),

                    'category1_code' => $data['Categoría 1'] ?? null,
                    'category1_description' => $data['Descripción Categoría 1'] ?? null,

                    'category2_code' => $data['Categoría 2'] ?? null,
                    'category2_description' => $data['Descripción Categoría 2'] ?? null,

                    'category3_code' => $data['Categoría 3'] ?? null,
                    'category3_description' => $data['Descripción Categoría 3'] ?? null,

                    'category4_code' => $data['Categoría 4'] ?? null,
                    'category4_description' => $data['Descripción Categoría 4'] ?? null,

                    'category5_code' => $data['Categoría 5'] ?? null,
                    'category5_description' => $data['Descripción Categoría 5'] ?? null,

                    'category6_code' => $data['Categoría 6'] ?? null,
                    'category6_description' => $data['Descripción Categoría 6'] ?? null,

                    'category7_code' => $data['Categoría 7'] ?? null,
                    'category7_description' => $data['Descripción Categoría 7'] ?? null,

                    'category8_code' => $data['Categoría 8'] ?? null,
                    'category8_description' => $data['Descripción Categoría 8'] ?? null,

                    'category9_code' => $data['Categoría 9'] ?? null,
                    'category9_description' => $data['Descripción Categoría 9'] ?? null,

                    'tax_rate' => is_numeric($data['Impuesto'] ?? null)
                        ? $data['Impuesto']
                        : 13,

                    'note1' => $data['Nota Explicativa 1'] ?? null,
                    'note2' => $data['Nota Explicativa 2'] ?? null,

                    'is_active' => true,

                ]

            );

            $count++;
        }

        fclose($handle);

        return $count;
    }
}