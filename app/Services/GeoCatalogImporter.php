<?php

namespace App\Services;

use App\Models\Country;
use App\Models\GeoCatalogLevel;
use App\Models\GeoCatalogSource;
use App\Models\GeoDivision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Importación reutilizable de catálogos geográficos por país (15B-1).
 * Idempotente (updateOrCreate por país+código), UTF-8, sin truncate,
 * valida padre-hijo y códigos duplicados antes de escribir.
 */
class GeoCatalogImporter
{
    /**
     * Importa todos los niveles configurados de un país en orden jerárquico
     * y registra fuente, versión y fecha del catálogo.
     *
     * @return array<int, int> divisiones importadas por nivel
     */
    public function importCountry(string $iso2): array
    {
        $country = Country::query()->where('iso2', $iso2)->where('is_active', true)->first();

        if (! $country) {
            throw new RuntimeException("País no configurado o inactivo: {$iso2}.");
        }

        $meta = (array) config("geography.catalog_sources.{$iso2}", []);
        $specs = (array) config("geography.catalog_files.{$iso2}", []);

        if ($meta === [] || $specs === []) {
            throw new RuntimeException("Catálogo sin fuente o sin archivos definidos para {$iso2}.");
        }

        $summary = [];

        foreach ($specs as $spec) {
            $level = (int) $spec['level'];
            $summary[$level] = $this->importFile($country, $spec, $meta);
        }

        $errors = $this->verifyIntegrity($country);

        if ($errors !== []) {
            throw new RuntimeException('Integridad territorial fallida: ' . implode(' | ', $errors));
        }

        GeoCatalogSource::updateOrCreate(
            ['country_id' => $country->id, 'source' => $meta['source']],
            [
                'source_url' => $meta['source_url'] ?? null,
                'version' => $meta['version'],
                'source_date' => $meta['source_date'] ?? null,
                'imported_at' => now(),
                'division_count' => array_sum($summary),
                'notes' => $meta['notes'] ?? null,
            ]
        );

        return $summary;
    }

    /**
     * Importa un archivo de nivel. Valida todo el archivo en memoria
     * (UTF-8, código/nombre, duplicados, padre existente) antes de escribir.
     *
     * @param array{file: string, level: int, delimiter: string, columns: array<string, int>} $spec
     * @param array{source: string, version: string} $meta
     */
    public function importFile(Country $country, array $spec, array $meta): int
    {
        $isAbsolute = str_starts_with($spec['file'], DIRECTORY_SEPARATOR)
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $spec['file']);
        $path = $isAbsolute ? $spec['file'] : database_path($spec['file']);

        if (! is_file($path)) {
            throw new RuntimeException("Archivo de catálogo no encontrado: {$spec['file']}.");
        }

        $raw = (string) file_get_contents($path);

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $level = (int) $spec['level'];
        $columns = (array) $spec['columns'];
        $delimiter = $spec['delimiter'];

        $rows = [];
        $seen = [];
        $isFirst = true;

        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($isFirst) {
                $isFirst = false;
                continue;
            }

            $cells = str_getcsv($line, $delimiter);
            $code = trim((string) ($cells[$columns['code']] ?? ''));
            $name = trim((string) ($cells[$columns['name']] ?? ''));

            if ($code === '' || $name === '') {
                throw ValidationException::withMessages([
                    'geo' => "Fila inválida en {$spec['file']} (código o nombre vacío).",
                ]);
            }

            if (isset($seen[$code])) {
                throw ValidationException::withMessages([
                    'geo' => "Código duplicado {$code} en {$spec['file']}.",
                ]);
            }

            $seen[$code] = true;

            $parentCode = null;
            if (array_key_exists('parent', $columns)) {
                $parentCode = trim((string) ($cells[$columns['parent']] ?? ''));
            }

            $rows[] = ['code' => $code, 'name' => $name, 'parent' => $parentCode];
        }

        $prepared = [];

        foreach ($rows as $row) {
            $parentId = null;

            if ($level > 1) {
                if ($row['parent'] === '') {
                    throw ValidationException::withMessages([
                        'geo' => "Falta padre para el código {$row['code']} en {$spec['file']}.",
                    ]);
                }

                $parent = GeoDivision::query()
                    ->where('country_id', $country->id)
                    ->where('code', $row['parent'])
                    ->where('level', $level - 1)
                    ->first();

                if (! $parent) {
                    throw ValidationException::withMessages([
                        'geo' => "Padre {$row['parent']} no existe para el código {$row['code']}.",
                    ]);
                }

                $parentId = $parent->id;
            }

            $prepared[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'level' => $level,
                'parent_id' => $parentId,
            ];
        }

        DB::transaction(function () use ($country, $prepared, $meta) {
            foreach ($prepared as $row) {
                GeoDivision::updateOrCreate(
                    ['country_id' => $country->id, 'code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'level' => $row['level'],
                        'parent_id' => $row['parent_id'],
                        'catalog_version' => $meta['version'],
                        'is_active' => true,
                    ]
                );
            }
        });

        return count($prepared);
    }

    /**
     * @return list<string> errores de integridad (vacío = íntegro)
     */
    public function verifyIntegrity(Country $country): array
    {
        $errors = [];
        $configured = GeoCatalogLevel::query()
            ->where('country_id', $country->id)->pluck('level');

        foreach (GeoDivision::query()->where('country_id', $country->id)->get() as $division) {
            if (! $configured->contains((int) $division->level)) {
                $errors[] = "{$division->code}: nivel {$division->level} no configurado.";
            }

            if ($division->parent_id) {
                $parent = $division->parent;

                if (! $parent) {
                    $errors[] = "{$division->code}: padre inexistente.";
                } elseif ((int) $parent->level !== (int) $division->level - 1
                    || (int) $parent->country_id !== (int) $division->country_id) {
                    $errors[] = "{$division->code}: padre de país o nivel incorrecto.";
                }
            } elseif ((int) $division->level !== 1) {
                $errors[] = "{$division->code}: sin padre pero no es nivel 1.";
            }
        }

        return $errors;
    }
}
