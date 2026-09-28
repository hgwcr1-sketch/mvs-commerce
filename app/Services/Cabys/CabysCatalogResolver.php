<?php

namespace App\Services\Cabys;

use App\Models\CabysCatalogEntry;
use App\Models\FiscalCatalogVersion;
use Illuminate\Support\Facades\Cache;

/**
 * Resolver de la versión vigente del catálogo CABYS (MF04 adaptado).
 *
 * Centraliza la única pregunta que el resto del módulo repite: ¿cuál es la
 * versión activa y contra qué se valida un código? Sin versión activa no hay
 * confirmación posible: un código fiscal sin fuente verificable no se admite.
 */
class CabysCatalogResolver
{
    /** Búsqueda versionada: la clave incluye la versión, así que activar un
     *  catálogo nuevo invalida por cambio de clave, no por flush global. */
    public const CACHE_TTL_SECONDS = 900;

    public function activeVersion(?string $date = null): ?FiscalCatalogVersion
    {
        $date ??= now()->toDateString();

        $versions = FiscalCatalogVersion::query()
            ->ofKind(FiscalCatalogVersion::KIND_CABYS)
            ->active()
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->get();

        return $versions->first(fn (FiscalCatalogVersion $version) => $version->isEffectiveOn($date));
    }

    /**
     * Versión con la que se validó una asignación antigua, aunque ya no sea la
     * vigente: sin esto, revalidar tras un cambio de versión destruiría la
     * trazabilidad de lo que ya se usó.
     */
    public function versionById(int $id): ?FiscalCatalogVersion
    {
        if ($id <= 0) {
            return null;
        }

        return FiscalCatalogVersion::query()
            ->ofKind(FiscalCatalogVersion::KIND_CABYS)
            ->find($id);
    }

    public function entry(string $code, int $catalogVersionId): ?CabysCatalogEntry
    {
        return CabysCatalogEntry::query()
            ->where('fiscal_catalog_version_id', $catalogVersionId)
            ->where('code', $code)
            ->first();
    }

    /**
     * Caché SOLO de arrays y escalares: los modelos se reconstruyen al leer.
     * Un store que serializa convertiría un modelo guardado en un objeto
     * incompleto en la siguiente consulta.
     *
     * @param  \Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function remember(int $catalogVersionId, string $key, \Closure $callback): array
    {
        if (self::CACHE_TTL_SECONDS <= 0) {
            return $callback();
        }

        $cacheKey = 'cabys:catalog:'.$catalogVersionId.':'.hash('sha256', $key);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $callback();

        if (is_array($result)) {
            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);
        }

        return $result;
    }
}
