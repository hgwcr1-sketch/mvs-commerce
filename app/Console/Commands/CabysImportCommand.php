<?php

namespace App\Console\Commands;

use App\Services\Cabys\CabysCatalogImporter;
use Illuminate\Console\Command;

/**
 * Importa el catálogo CABYS versionado desde un archivo LOCAL.
 *
 * Sin red. Idempotente por checksum. La versión activa solo cambia cuando la
 * importación termina bien (activación transaccional).
 *
 *   php artisan cabys:import
 *   php artisan cabys:import --file=/ruta/catalogo-oficial.csv --catalogo-version=2025
 */
class CabysImportCommand extends Command
{
    protected $signature = 'cabys:import
        {--file= : Ruta del archivo CABYS (por defecto database/catalogs/cabys.csv)}
        {--catalogo-version= : Versión explícita del catálogo}
        {--draft : Importa sin activar la versión}';

    protected $description = 'Importa el catálogo CABYS local en versiones, con checksum y activación transaccional';

    public function handle(CabysCatalogImporter $importer): int
    {
        $path = (string) ($this->option('file') ?: database_path('catalogs/cabys.csv'));
        $version = (string) ($this->option('catalogo-version') ?: '');

        $result = $importer->import(
            $path,
            $version !== '' ? $version : null,
            activate: ! $this->option('draft'),
        );

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
