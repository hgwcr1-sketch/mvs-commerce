<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Province;
use App\Models\Canton;
use App\Models\District;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    /**
     * ==========================================================
     * MVS Commerce ERP
     * Importador Oficial de Catálogos
     * Versión 0.1
     * ==========================================================
     */

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('==========================================');
        $this->command->info(' MVS Commerce - Catálogos');
        $this->command->info('==========================================');
        $this->command->info('');

        DB::beginTransaction();

        try {

            District::truncate();
            Canton::truncate();
            Province::truncate();
            Country::truncate();

            $this->importCountries();

            $this->importProvinces();

            $this->importCantons();

            $this->importDistricts();

            DB::commit();

            $this->command->info('');
            $this->command->info('Catálogos importados correctamente.');
            $this->command->info('');

        } catch (\Throwable $e) {

            DB::rollBack();

            $this->command->error($e->getMessage());

        }
}        
        /**
     * ==========================================================
     * Importar Países
     * ==========================================================
     */
    private function importCountries(): void
    {
        $rows = $this->readCsv(database_path('catalogs/countries.csv'));

        foreach ($rows as $row) {

            Country::updateOrCreate(

                ['iso2' => trim($row['iso2'])],

                [
                    'name'            => trim($row['name']),
                    'iso3'            => trim($row['iso3']),
                    'phone_code'      => trim($row['phone_code']),
                    'currency'        => trim($row['currency']),
                    'currency_symbol' => trim($row['currency_symbol']),
                    'is_default'      => (bool) $row['is_default'],
                    'is_active'       => (bool) $row['is_active'],
                ]

            );

        }

        $this->command->info('✓ Países importados');

    }

    /**
     * ==========================================================
     * Importar Provincias
     * ==========================================================
     */
    private function importProvinces(): void
    {

        $country = Country::where('iso2', 'CR')->first();

        if (! $country) {

            throw new \Exception('No existe Costa Rica.');

        }

        $rows = $this->readCsv(database_path('catalogs/provinces.csv'));

        foreach ($rows as $row) {

            Province::updateOrCreate(

                [
                    'country_id' => $country->id,
                    'code'       => trim($row['codigo']),
                ],

                [
                    'name'      => trim($row['nombre']),
                    'is_active' => true,
                ]

            );

        }

        $this->command->info('✓ Provincias importadas');

    }
         /**
     * ==========================================================
     * Importar Cantones
     * ==========================================================
     */
    private function importCantons(): void
{
    $rows = $this->readCsv(database_path('catalogs/cantones.csv'));

    foreach ($rows as $row) {

        $province = Province::where(
            'code',
            trim($row['CÓDIGO PROVINCIA'])
        )->first();

        if (! $province) {
            continue;
        }

        Canton::updateOrCreate(
            [
                'province_id' => $province->id,
                'code'        => trim($row['CÓDIGO CANTÓN']),
            ],
            [
                'name'      => trim($row['CANTÓN']),
                'is_active' => true,
            ]
        );
    }

    $this->command->info('✓ Cantones importados');
}
    /**
     * ==========================================================
     * Importar Distritos
     * ==========================================================
     */
    private function importDistricts(): void
{
    $rows = $this->readCsv(database_path('catalogs/distritos.csv'));

    foreach ($rows as $row) {

        $canton = Canton::where(
            'code',
            trim($row['CÓDIGO CANTÓN'])
        )->first();

        if (! $canton) {
            continue;
        }

        District::updateOrCreate(
            [
                'canton_id' => $canton->id,
                'code'      => trim($row['CÓDIGO DISTRITO']),
            ],
            [
                'name'      => trim($row['DISTRITO']),
                'is_active' => true,
            ]
        );
    }

    $this->command->info('✓ Distritos importados');
}
    /**
     * ==========================================================
     * Leer archivo CSV
     * ==========================================================
     */
    private function readCsv(string $file): array
{
    if (! file_exists($file)) {
        throw new Exception("No existe el archivo: {$file}");
    }

    $rows = [];

    $handle = fopen($file, 'r');

    if ($handle === false) {
        throw new Exception("No se pudo abrir: {$file}");
    }

    // Detectar separador automáticamente
    $firstLine = fgets($handle);
    rewind($handle);

    if (substr_count($firstLine, "\t") > 0) {
    $delimiter = "\t";
} elseif (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
    $delimiter = ';';
} else {
    $delimiter = ',';
}

    $headers = [];

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {

        if (empty($headers)) {

            $headers = array_map(function ($header) {
                return trim(mb_convert_encoding($header, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'));
            }, $data);

            continue;
        }

        $data = array_map(function ($value) {
            return trim(mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'));
        }, $data);

        if (count($headers) !== count($data)) {
            continue;
        }

        $rows[] = array_combine($headers, $data);
    }

    fclose($handle);

    return $rows;
}

}