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
 * UNA consulta = UNA petición a la misma ruta: la respuesta ya trae nombre,
 * régimen, situación y actividades económicas, así que se normalizan aquí en
 * una sola llamada. No existe un segundo lookup ni una segunda ruta.
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
     * Alias aceptados por concepto (mismo criterio que la fuente oficial):
     * la documentación pública no fija un único nombre por concepto.
     */
    private const ACTIVITY_KEYS = ['actividades', 'actividades_economicas', 'actividad_economica', 'activities'];

    /**
     * @return array{status: string, name: string|null, type?: string|null, regime: string|null, situation: string|null, activities: list<array{code: string, description: string|null}>}
     */
    public function lookup(string $identification): array
    {
        $digits = preg_replace('/\D+/', '', $identification) ?? '';

        if (strlen($digits) < 9 || strlen($digits) > 12) {
            return $this->empty(self::STATUS_INVALID);
        }

        $cache = $this->cache();
        $key = 'hacienda.ae.v2.'.$digits;
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
            return $this->empty(self::STATUS_UNAVAILABLE);
        }

        if ($response->notFound()) {
            return $this->store($cache, $key, $this->empty(self::STATUS_NOT_FOUND), self::CACHE_NOT_FOUND_MINUTES);
        }

        if (! $response->successful()) {
            return $this->empty(self::STATUS_UNAVAILABLE);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return $this->empty(self::STATUS_UNAVAILABLE);
        }

        $name = $this->string($payload, ['nombre', 'name', 'nombre_razon_social', 'razon_social']);
        $type = $this->string($payload, ['tipoIdentificacion', 'tipo_identificacion']);

        if ($name === null) {
            return $this->store($cache, $key, $this->empty(self::STATUS_NOT_FOUND), self::CACHE_NOT_FOUND_MINUTES);
        }

        return $this->store($cache, $key, [
            'status' => self::STATUS_FOUND,
            'name' => $name,
            'type' => $type,
            'regime' => $this->regime($payload['regimen'] ?? null),
            'situation' => $this->situation($payload['situacion'] ?? null),
            'activities' => $this->activities($payload),
        ], self::CACHE_FOUND_MINUTES);
    }

    /**
     * Estructura completa (con campos vacíos) para que la UI lea siempre las
     * mismas claves, haya o no respuesta oficial.
     *
     * @return array{status: string, name: null, type: null, regime: null, situation: null, activities: list<array{code: string, description: string|null}>}
     */
    private function empty(string $status): array
    {
        return [
            'status' => $status,
            'name' => null,
            'type' => null,
            'regime' => null,
            'situation' => null,
            'activities' => [],
        ];
    }

    /**
     * La API oficial entrega `regimen` como objeto `{codigo, descripcion}` o
     * como escalar. Se prefiere la descripción oficial; nada se traduce ni se
     * clasifica a mano.
     */
    private function regime(mixed $raw): ?string
    {
        if (is_string($raw) || is_numeric($raw)) {
            $value = trim((string) $raw);

            return $value === '' ? null : $value;
        }

        if (! is_array($raw)) {
            return null;
        }

        foreach (['descripcion', 'description', 'codigo', 'code'] as $key) {
            $value = $raw[$key] ?? null;

            if ((is_string($value) || is_numeric($value)) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * La API oficial entrega `situacion` como objeto con `estado` tributario
     * ("Inscrito", "Desinscrito de oficio", ...). Un escalar pasa tal cual.
     */
    private function situation(mixed $raw): ?string
    {
        if (is_string($raw)) {
            $value = trim($raw);

            return $value === '' ? null : $value;
        }

        if (! is_array($raw)) {
            return null;
        }

        $estado = $raw['estado'] ?? null;

        if (is_string($estado) && trim($estado) !== '') {
            return trim($estado);
        }

        return null;
    }

    /**
     * Actividades económicas del contribuyente. Solo se devuelven las que
     * traen un CÓDIGO declarado por la fuente: usar índices numéricos de un
     * JSON como si fueran códigos tributarios sería inventar datos.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, description: string|null}>
     */
    private function activities(array $payload): array
    {
        $raw = null;

        foreach (self::ACTIVITY_KEYS as $alias) {
            if (array_key_exists($alias, $payload)) {
                $raw = $payload[$alias];
                break;
            }
        }

        if (! is_array($raw)) {
            return [];
        }

        $associative = ! array_is_list($raw);
        $activities = [];

        foreach ($raw as $key => $entry) {
            $code = null;
            $description = null;

            if (is_string($entry) || is_numeric($entry)) {
                $code = trim((string) $entry);
                $description = $code;
            } elseif (is_array($entry)) {
                $code = $this->string($entry, ['codigo', 'code', 'categoria', 'id']);
                $description = $this->string($entry, ['descripcion', 'description', 'nombre', 'name']);

                if ($code === null && $associative && is_string($key)) {
                    $code = trim($key);
                }
            }

            if ($code === null || $code === '' || preg_match('/^\d{1,20}$/', $code) !== 1) {
                continue;
            }

            $activities[$code] = ['code' => $code, 'description' => $description];
        }

        return array_values($activities);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function string(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function cache(): Repository
    {
        return Cache::store();
    }

    /**
     * @param  array{status: string, name: string|null}  $result
     * @return array{status: string, name: string|null}
     */
    private function store(Repository $cache, string $key, array $result, int $minutes): array
    {
        $cache->put($key, $result, now()->addMinutes($minutes));

        return $result;
    }
}
