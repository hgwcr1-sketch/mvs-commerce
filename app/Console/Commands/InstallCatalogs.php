<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\Province;
use App\Models\Canton;
use App\Models\District;
use App\Services\CatalogImporter;
use App\Services\CabysImporter;
use Illuminate\Console\Command;

class InstallCatalogs extends Command
{
    protected $signature = 'catalogs:install';

    protected $description = 'Instala los catálogos del sistema';

    public function handle(
        CatalogImporter $importer,
        CabysImporter $cabysImporter
    ): int
    {
        $this->newLine();
        $this->info('====================================');
        $this->info(' MVS Commerce - Instalador de Catálogos');
        $this->info('====================================');
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | Países
        |--------------------------------------------------------------------------
        */

        $countries = $importer->import(
            database_path('catalogs/countries.csv'),
            function (array $row) {

                Country::updateOrCreate(
                    [
                        'iso3' => $row[2],
                    ],
                    [
                        'name' => $row[0],
                        'iso2' => $row[1],
                        'iso3' => $row[2],
                        'phone_code' => $row[3],
                        'currency' => $row[4],
                        'currency_symbol' => $row[5],
                        'is_default' => (bool) $row[6],
                        'is_active' => (bool) $row[7],
                    ]
                );
            }
        );

        $this->info("✔ Países importados: {$countries}");

        /*
        |--------------------------------------------------------------------------
        | Provincias
        |--------------------------------------------------------------------------
        */

        $provinces = $importer->import(
            database_path('catalogs/provinces.csv'),
            function (array $row) {

                $country = Country::where('iso2', $row[0])->first();

                if (!$country) {
                    return;
                }

                Province::updateOrCreate(
                    [
                        'country_id' => $country->id,
                        'code' => $row[1],
                    ],
                    [
                        'name' => $row[2],
                        'is_active' => true,
                    ]
                );
            }
        );

        $this->info("✔ Provincias importadas: {$provinces}");

        /*
        |--------------------------------------------------------------------------
        | Cantones
        |--------------------------------------------------------------------------
        */

        $cantons = $importer->import(
            database_path('catalogs/cantones.csv'),
            function (array $row) {

                $province = Province::where('name', trim($row[0]))->first();

                if (!$province) {
                    return;
                }

                Canton::updateOrCreate(
                    [
                        'province_id' => $province->id,
                        'name' => trim($row[1]),
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }
        );

        $this->info("✔ Cantones importados: {$cantons}");

        /*
        |--------------------------------------------------------------------------
        | Distritos
        |--------------------------------------------------------------------------
        */

        $districts = $importer->import(
            database_path('catalogs/distritos.csv'),
            function (array $row) {

                $province = Province::where('name', trim($row[0]))->first();

                if (!$province) {
                    return;
                }

                $canton = Canton::where('province_id', $province->id)
                    ->where('name', trim($row[1]))
                    ->first();

                if (!$canton) {
                    return;
                }

                District::updateOrCreate(
                    [
                        'canton_id' => $canton->id,
                        'name' => trim($row[2]),
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }
        );

        $this->info("✔ Distritos importados: {$districts}");

        /*
        |--------------------------------------------------------------------------
        | CABYS
        |--------------------------------------------------------------------------
        */

        $cabys = $cabysImporter->import(
            database_path('catalogs/Cabys.csv')
        );

        $this->info("✔ CABYS importados: {$cabys}");

        $this->newLine();
        $this->info('====================================');
        $this->info(' Catálogos instalados correctamente');
        $this->info('====================================');

        return self::SUCCESS;
    }
}