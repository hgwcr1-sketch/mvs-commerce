<?php

namespace App\Services\Cabys;

use App\Models\CabysCatalogEntry;
use App\Models\FiscalCatalogVersion;
use Illuminate\Support\Facades\DB;

/**
 * Importación del catálogo CABYS desde un archivo LOCAL (MF04 adaptado).
 *
 * Reglas, en orden:
 *
 *  1. La VERSIÓN es explícita (o derivada del checksum del archivo, siempre
 *     auditable). Deducir la versión del contenido produce catálogos que
 *     nadie puede auditar.
 *  2. FAIL CLOSED: sin encabezado reconocible no se importa nada. Un
 *     catálogo fiscal que no se puede identificar no entra al sistema.
 *  3. Solo se importan códigos CABYS OFICIALES escritos como texto: el
 *     nivel MÁS PROFUNDO presente en la fila, de 8 a 13 dígitos. El archivo
 *     oficial usa nivel 8 (11 dígitos) para la mayoría de bienes y servicios
 *     —incluidos los de tecnología— y nivel 9 (13 dígitos) para un subconjunto.
 *     Fijar la columna "Categoría 9" descartaba 19.066 códigos válidos. Las
 *     celdas dañadas por Excel (notación científica) se cuentan como
 *     `skipped`: reconstruirlas sería inventar códigos fiscales.
 *  4. El CHECKSUM hace la operación idempotente: el mismo archivo no se
 *     reimporta.
 *  5. La activación es TRANSACCIONAL y conserva la versión anterior como
 *     `superseded`; un fallo deja el catálogo vigente intacto.
 *
 * No participa ninguna red: el archivo es local.
 */
class CabysCatalogImporter
{
    public const SOURCE = 'BCCR CABYS';

    /**
     * Niveles de categoría del archivo oficial, del más profundo al más
     * superficial. Se recorren en este orden para tomar el código válido más
     * específico de cada fila.
     */
    private const CODE_LEVELS = [9, 8];

    /** Longitud mínima y máxima de un código CABYS oficial. */
    private const CODE_MIN_LENGTH = 8;

    private const CODE_MAX_LENGTH = 13;

    private const CHUNK = 500;

    /**
     * @return array{ok: bool, message: string, version: string|null, imported: int, skipped: int, already: bool}
     */
    public function import(string $path, ?string $version = null, bool $activate = true): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return $this->fail('No se encontró el archivo: '.$path);
        }

        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            return $this->fail('No se pudo calcular el checksum del archivo');
        }

        // Un archivo solo se considera "ya importado" si su versión guardada
        // tiene el MISMO conteo de filas que produciría hoy el criterio
        // vigente. Con un criterio de importación distinto (por ejemplo, al
        // incluir el nivel más profundo) el checksum no basta: la misma fuente
        // debe poder generar una versión nueva y completa.
        $existing = FiscalCatalogVersion::query()
            ->ofKind(FiscalCatalogVersion::KIND_CABYS)
            ->where('checksum', $checksum)
            ->get()
            ->first(fn (FiscalCatalogVersion $candidate) => $this->matchesCurrentCriteria($candidate, $path));

        if ($existing !== null) {
            if ($activate && $existing->status !== FiscalCatalogVersion::STATUS_ACTIVE) {
                $this->activate((int) $existing->id);
            }

            return [
                'ok' => true,
                'message' => 'El archivo ya estaba importado (checksum idéntico).',
                'version' => $existing->source_version,
                'imported' => (int) ($existing->row_count ?? 0),
                'skipped' => 0,
                'already' => true,
            ];
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return $this->fail('No fue posible abrir el archivo');
        }

        $firstLine = fgets($handle);

        if ($firstLine === false) {
            fclose($handle);

            return $this->fail('El archivo está vacío');
        }

        $delimiter = $this->delimiter($firstLine);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        $header = is_array($header) ? $this->normalizeHeader($header) : [];
        $columns = $this->columns($header);

        // El archivo oficial NO tiene una columna fija de código: cada fila
        // declara su nivel más profundo (8 para bienes y servicios, 9 para
        // otros). Se exige al menos una columna de categoría.
        if ($columns['description'] === null || $columns['tax'] === null || $columns['levels'] === []) {
            fclose($handle);

            return $this->fail(
                'El encabezado no corresponde al catálogo CABYS oficial del BCCR '
                .'(se esperan Categoría N, Descripción (categoría N) e Impuesto). No se importa nada.'
            );
        }

        $entries = [];
        $vistos = [];
        $duplicados = 0;
        $skipped = 0;
        $position = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null]) {
                continue;
            }

            $code = $this->code($row, $columns);

            if ($code === null) {
                $skipped++;

                continue;
            }

            // El archivo oficial repite códigos en distintas ramas de
            // categoría. Se conserva la PRIMERA aparición y se cuenta el
            // resto: el índice único (versión, código) no admite repetidos y
            // no se inventa una variante para "cuadrar" el catálogo.
            if (isset($vistos[$code])) {
                $duplicados++;

                continue;
            }

            $vistos[$code] = true;

            // La descripción y el impuesto son SIEMPRE los de la fila, pero la
            // descripción corresponde al nivel del código detectado: en las
            // filas de nivel 8 la columna de nivel 9 viene vacía.
            $nivel = $this->levelFor($row, $columns, $code);

            $entry = [
                'code' => $code,
                'description' => $this->text($row, $columns["category{$nivel}_description"] ?? null)
                    ?? $this->text($row, $columns['description'])
                    ?? $code,
                'tax_rate_raw' => $this->text($row, $columns['tax']),
                'tax_rate_pct' => null,
                'note_include' => $this->text($row, $columns['note_include']),
                'note_exclude' => $this->text($row, $columns['note_exclude']),
                'is_active' => true,
                'position' => ++$position,
            ];

            for ($level = 1; $level <= 9; $level++) {
                $entry["category{$level}_code"] = $this->text($row, $columns["category{$level}_code"]);
                $entry["category{$level}_description"] = $this->text($row, $columns["category{$level}_description"]);
            }

            $entries[] = $entry;
        }

        fclose($handle);

        $imported = count($entries);

        if ($imported === 0) {
            return $this->fail(
                'El archivo no contiene códigos CABYS oficiales válidos de '
                .self::CODE_MIN_LENGTH.' a '.self::CODE_MAX_LENGTH.' dígitos. No se importa nada.'
            );
        }

        return $this->persist($path, $checksum, $version, $entries, $imported, $skipped, $duplicados, $activate);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{ok: bool, message: string, version: string|null, imported: int, skipped: int, already: bool}
     */
    private function persist(
        string $path,
        string $checksum,
        ?string $version,
        array $entries,
        int $imported,
        int $skipped,
        int $duplicados,
        bool $activate,
    ): array {
        $sourceVersion = trim((string) ($version ?? ''));

        if ($sourceVersion === '') {
            $sourceVersion = 'local-'.substr($checksum, 0, 8);
        }

        if (FiscalCatalogVersion::query()
            ->ofKind(FiscalCatalogVersion::KIND_CABYS)
            ->where('source', self::SOURCE)
            ->where('source_version', $sourceVersion)
            ->exists()) {
            $sourceVersion .= '-'.substr($checksum, 0, 8);
        }

        try {
            $versionId = DB::transaction(function () use ($entries, $checksum, $sourceVersion, $imported) {
                $record = FiscalCatalogVersion::create([
                    'kind' => FiscalCatalogVersion::KIND_CABYS,
                    'source' => self::SOURCE,
                    'source_version' => $sourceVersion,
                    'checksum' => $checksum,
                    'row_count' => $imported,
                    'imported_at' => now(),
                    'status' => FiscalCatalogVersion::STATUS_DRAFT,
                ]);

                foreach (array_chunk($entries, self::CHUNK) as $chunk) {
                    CabysCatalogEntry::insert(array_map(
                        fn (array $entry) => $entry + [
                            'fiscal_catalog_version_id' => $record->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        $chunk
                    ));
                }

                return (int) $record->id;
            });
        } catch (\Throwable $exception) {
            return $this->fail('No se pudo importar el catálogo: '.$exception->getMessage());
        }

        if ($activate) {
            $this->activate($versionId);
        }

        $message = "Catálogo {$sourceVersion}: {$imported} códigos importados";
        $message .= $skipped > 0 ? " ({$skipped} filas descartadas por no traer un código oficial)." : '.';
        $message .= $duplicados > 0 ? " {$duplicados} filas repetidas se omitieron (se conserva la primera)." : '';
        $message .= $activate ? ' Versión activada.' : ' Versión en estado borrador.';

        return [
            'ok' => true,
            'message' => $message,
            'version' => $sourceVersion,
            'imported' => $imported,
            'skipped' => $skipped,
            'duplicates' => $duplicados,
            'already' => false,
        ];
    }

    /**
     * Activación transaccional: la versión anterior queda `superseded` y una
     * falla deja el catálogo vigente exactamente como estaba.
     */
    private function activate(int $versionId): void
    {
        DB::transaction(function () use ($versionId) {
            FiscalCatalogVersion::query()
                ->ofKind(FiscalCatalogVersion::KIND_CABYS)
                ->where('status', FiscalCatalogVersion::STATUS_ACTIVE)
                ->where('id', '!=', $versionId)
                ->update(['status' => FiscalCatalogVersion::STATUS_SUPERSEDED]);

            FiscalCatalogVersion::query()
                ->whereKey($versionId)
                ->update([
                    'status' => FiscalCatalogVersion::STATUS_ACTIVE,
                    'activated_at' => now(),
                ]);
        });
    }

    private function delimiter(string $header): string
    {
        $candidates = ["\t", ';', ','];
        $best = "\t";
        $count = -1;

        foreach ($candidates as $candidate) {
            $found = substr_count($header, $candidate);

            if ($found > $count) {
                $count = $found;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string|null>
     */
    private function normalizeHeader(array $header): array
    {
        return array_values(array_map(function ($cell) {
            $cell = trim((string) $cell);
            $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;

            return preg_replace('/\s+/u', ' ', $cell) ?? $cell;
        }, $header));
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int|null>
     */
    private function columns(array $header): array
    {
        $find = function (string $label) use ($header): ?int {
            $index = array_search($label, $header, true);

            return $index === false ? null : (int) $index;
        };

        $columns = [
            'description' => $find('Descripción (categoría 9)'),
            'tax' => $find('Impuesto'),
            'note_include' => $find('Nota explicativa 1. Incluye'),
            'note_exclude' => $find('Nota explicativa 2. Excluye'),
            'levels' => [],
        ];

        for ($level = 1; $level <= 9; $level++) {
            $columns["category{$level}_code"] = $find('Categoría '.$level);
            $columns["category{$level}_description"] = $find('Descripción (categoría '.$level.')');

            if ($columns["category{$level}_code"] !== null) {
                $columns['levels'][] = $level;
            }
        }

        return $columns;
    }

    /**
     * Nivel del que proviene el código detectado en la fila.
     *
     * @param  list<string|null>  $row
     * @param  array<string, mixed>  $columns
     */
    private function levelFor(array $row, array $columns, string $code): int
    {
        foreach (self::CODE_LEVELS as $level) {
            $raw = $this->text($row, $columns["category{$level}_code"] ?? null);

            if ($raw === $code) {
                return $level;
            }
        }

        return self::CODE_LEVELS[count(self::CODE_LEVELS) - 1];
    }

    /**
     * ¿La versión guardada fue producida por el criterio vigente?
     *
     * Se recalcula el conteo con el criterio de esta importación y se compara
     * con las entradas realmente guardadas. Si el archivo cambió de criterio
     * (más niveles válidos, deduplicación) la versión vieja queda incompleta y
     * se debe permitir una versión nueva.
     */
    private function matchesCurrentCriteria(FiscalCatalogVersion $candidate, string $path): bool
    {
        $esperadas = $this->countEntries($path);

        if ($esperadas === 0) {
            return false;
        }

        $guardadas = CabysCatalogEntry::query()
            ->where('fiscal_catalog_version_id', $candidate->id)
            ->count();

        return $guardadas === $esperadas;
    }

    /**
     * Cuenta cuántos códigos únicos admite hoy el archivo, sin escribir nada.
     */
    private function countEntries(string $path): int
    {
        $handle = fopen($path, 'r');
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = $this->delimiter((string) $firstLine);
        $header = fgetcsv($handle, 0, $delimiter);
        $header = is_array($header) ? $this->normalizeHeader($header) : [];
        $columns = $this->columns($header);

        if ($columns['description'] === null || $columns['tax'] === null || $columns['levels'] === []) {
            fclose($handle);

            return 0;
        }

        $vistos = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $code = $this->code($row, $columns);
            if ($code !== null) {
                $vistos[$code] = true;
            }
        }
        fclose($handle);

        return count($vistos);
    }

    /**
     * Código CABYS de la fila: el nivel MÁS PROFUNDO presente.
     *
     * El archivo oficial usa 8 categorías (11 dígitos) para la mayoría de
     * bienes y servicios —incluidos los de tecnología— y 9 (13 dígitos) para
     * un subconjunto. Fijar la columna "Categoría 9" descartaba 19.066
     * códigos válidos. Ahora se baja desde el nivel 9 hasta el 8 y se toma el
     * primero que sea un código numérico oficial. Nada se reconstruye.
     *
     * @param  list<string|null>  $row
     * @param  array<string, mixed>  $columns
     */
    private function code(array $row, array $columns): ?string
    {
        foreach (self::CODE_LEVELS as $level) {
            $raw = $this->text($row, $columns["category{$level}_code"] ?? null);

            if ($raw === null) {
                continue;
            }

            // Solo dígitos y longitud oficial: nada se transforma ni se completa.
            if (preg_match('/^\d{'.self::CODE_MIN_LENGTH.','.self::CODE_MAX_LENGTH.'}$/', $raw) === 1) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $row
     */
    private function text(array $row, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return null;
        }

        $value = $row[$index];

        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @return array{ok: bool, message: string, version: string|null, imported: int, skipped: int, already: bool}
     */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'version' => null,
            'imported' => 0,
            'skipped' => 0,
            'already' => false,
        ];
    }
}
