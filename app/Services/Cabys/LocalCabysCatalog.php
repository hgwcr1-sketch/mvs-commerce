<?php

namespace App\Services\Cabys;

use App\Models\CabysCatalogEntry;

/**
 * Catálogo CABYS LOCAL (MF04 adaptado a producción).
 *
 * El POS y el formulario de productos necesitan validar el código en cada
 * operación, y esa validación no puede depender de una API externa: aquí no
 * hay red, solo la base de datos y la caché local.
 *
 * Cuando no hay ninguna versión activa la búsqueda devuelve un estado
 * explícito (`no_catalog`) en vez de una excepción: la ausencia de catálogo es
 * un estado operativo conocido, no un fallo de la aplicación.
 */
class LocalCabysCatalog
{
    /** Mínimo de caracteres para buscar, igual que la fuente oficial. */
    public const MIN_QUERY_LENGTH = 3;

    public const MAX_LIMIT = 50;

    public const CODE_LENGTH = 13;

    public function __construct(private readonly CabysCatalogResolver $resolver) {}

    /**
     * @return array{found: bool, entries: list<array<string, mixed>>, version: string|null, error: string|null}
     */
    public function search(string $query, int $limit = 30): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $version = $this->resolver->activeVersion();

        if ($version === null) {
            return ['found' => false, 'entries' => [], 'version' => null, 'error' => 'no_catalog'];
        }

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return ['found' => false, 'entries' => [], 'version' => $version->source_version, 'error' => null];
        }

        $entries = $this->resolver->remember(
            (int) $version->id,
            'search:'.$query.':'.$limit,
            fn (): array => $this->runSearch($query, $limit, (int) $version->id),
        );

        return [
            'found' => $entries !== [],
            'entries' => $entries,
            'version' => $version->source_version,
            'error' => null,
        ];
    }

    /**
     * Validación local de un código contra la versión vigente.
     *
     * @return array<string, mixed>|null
     */
    public function validate(string $code): ?array
    {
        $digits = preg_replace('/\D/', '', trim($code)) ?? '';

        if (strlen($digits) !== self::CODE_LENGTH) {
            return null;
        }

        $version = $this->resolver->activeVersion();

        if ($version === null) {
            return null;
        }

        $entry = $this->resolver->entry($digits, (int) $version->id);

        return $entry === null ? null : $this->toArray($entry, $version->source_version);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runSearch(string $query, int $limit, int $catalogVersionId): array
    {
        $isNumeric = preg_match('/^[0-9]+$/', $query) === 1;

        $builder = CabysCatalogEntry::query()
            ->where('fiscal_catalog_version_id', $catalogVersionId)
            ->where('is_active', true);

        if ($isNumeric) {
            $builder->where(function ($inner) use ($query) {
                $inner->where('code', $query)
                    ->orWhere('code', 'like', $query.'%');
            });
        } else {
            $like = '%'.$this->escapeLike($query).'%';
            $builder->where('description', 'like', $like);
        }

        // Orden estable: código exacto primero, después código ascendente.
        $rows = $builder
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$query])
            ->orderBy('code')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($entry) => $this->toArray($entry))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(CabysCatalogEntry $entry, ?string $sourceVersion = null): array
    {
        return [
            'code' => $entry->code,
            'description' => $entry->description,
            'tax_rate_raw' => $entry->tax_rate_raw,
            'tax_rate_pct' => $entry->taxRatePercent(),
            'note_include' => $entry->note_include,
            'note_exclude' => $entry->note_exclude,
            'source_version' => $sourceVersion,
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
