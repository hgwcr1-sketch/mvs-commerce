<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta de contribuyente ante el Ministerio de Hacienda de Costa Rica.
 *
 * Fuente oficial: GET https://api.hacienda.go.cr/fe/ae?identificacion=...
 * (parámetro aceptado: 9 a 12 dígitos numéricos).
 *
 * La consulta nunca bloquea el formulario: ante cualquier fallo se devuelve
 * "unavailable" y la interfaz continúa sin autocompletar.
 */
class TaxpayerLookupService
{
    public const ENDPOINT = 'https://api.hacienda.go.cr/fe/ae';

    public const STATUS_FOUND = 'found';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_INVALID = 'invalid';

    public const CACHE_FOUND_MINUTES = 720;

    public const CACHE_NOT_FOUND_MINUTES = 60;

    /**
     * @return array{status: string, name: string|null, type?: string|null}
     */
    public function lookup(string $identification): array
    {
        $digits = preg_replace('/\D+/', '', $identification) ?? '';

        if (strlen($digits) < 9 || strlen($digits) > 12) {
            return ['status' => self::STATUS_INVALID, 'name' => null];
        }

        $cache = $this->cache();
        $key = 'hacienda.ae.'.$digits;
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(4)
                ->connectTimeout(2)
                ->get(self::ENDPOINT, ['identificacion' => $digits]);
        } catch (Throwable) {
            return ['status' => self::STATUS_UNAVAILABLE, 'name' => null];
        }

        if ($response->notFound()) {
            return $this->store($cache, $key, ['status' => self::STATUS_NOT_FOUND, 'name' => null], self::CACHE_NOT_FOUND_MINUTES);
        }

        if (! $response->successful()) {
            return ['status' => self::STATUS_UNAVAILABLE, 'name' => null];
        }

        $name = $response->json('nombre');
        $type = $response->json('tipoIdentificacion');

        if (! is_string($name) || trim($name) === '') {
            return $this->store($cache, $key, ['status' => self::STATUS_NOT_FOUND, 'name' => null], self::CACHE_NOT_FOUND_MINUTES);
        }

        return $this->store($cache, $key, [
            'status' => self::STATUS_FOUND,
            'name' => trim($name),
            'type' => is_string($type) ? $type : null,
        ], self::CACHE_FOUND_MINUTES);
    }

    private function cache(): Repository
    {
        return Cache::store();
    }

    /**
     * @param array{status: string, name: string|null} $result
     *
     * @return array{status: string, name: string|null}
     */
    private function store(Repository $cache, string $key, array $result, int $minutes): array
    {
        $cache->put($key, $result, now()->addMinutes($minutes));

        return $result;
    }
}
