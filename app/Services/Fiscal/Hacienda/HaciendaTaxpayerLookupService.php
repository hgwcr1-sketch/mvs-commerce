<?php

namespace App\Services\Fiscal\Hacienda;

use App\Contracts\Fiscal\FiscalTaxpayerLookupInterface;
use App\DTOs\Fiscal\FiscalError;
use App\DTOs\Fiscal\FiscalTaxpayerInfo;
use App\Services\Fiscal\FiscalIdentification;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Consulta oficial de contribuyentes (MF03).
 *
 * Aísla por EMPRESA (fail-closed, coherente con MF02-B3) pero cachea de forma
 * GLOBAL: el estado tributario de un contribuyente es el mismo para todas las
 * empresas y la propia DGT recomienda caché local porque no cambia en tiempo
 * real. Meter `company_id` en la clave sería duplicar datos oficiales por
 * tenant sin ningún beneficio y multiplicaría el tráfico hacia la API.
 *
 * El rate limit también es GLOBAL y compartido entre tenants, por una razón
 * concreta: `api.hacienda.go.cr` bloquea la IP de salida durante 10 minutos.
 * En nube, todos los tenants de MVS salen por la misma IP, así que un límite
 * por tenant dejaría que diez empresas en conjunto superen el umbral oficial
 * y bloquearían a todos a la vez.
 */
class HaciendaTaxpayerLookupService implements FiscalTaxpayerLookupInterface
{
    public function __construct(
        private readonly HaciendaClient $client,
        private readonly HaciendaTaxpayerNormalizer $normalizer,
        private readonly ?Cache $cache = null,
    ) {
    }

    public function lookup(string $identification): FiscalTaxpayerInfo
    {
        $clean = FiscalIdentification::normalize($identification);

        if ($clean === null) {
            return $this->normalizer->invalidRequest(
                'La identificación debe tener entre 9 y 12 dígitos numéricos'
            );
        }

        $cacheKey = $this->cacheKey($clean);
        $store = $this->store();

        if ($store !== null) {
            $cached = $store->get($cacheKey);

            if ($cached instanceof FiscalTaxpayerInfo) {
                $this->log('cache_hit', $clean, $cached);

                return $cached;
            }
        }

        $result = $this->query($clean);

        $this->log('queried', $clean, $result);

        if ($store !== null) {
            $ttl = $this->ttlFor($result);

            /**
             * TTL null significa NO cachear. Un 429, un 5xx o un timeout NUNCA
             * se cachean: son transitorios y cachearlos dejaría al
             * contribuyente marcado como "consultado" durante semanas con un
             * error que en realidad fue de infraestructura.
             */
            if ($ttl !== null) {
                $store->put($cacheKey, $result, $ttl);
            }
        }

        return $result;
    }

    private function query(string $identification): FiscalTaxpayerInfo
    {
        $limitKey = $this->rateLimitKey();

        if (RateLimiter::tooManyAttempts($limitKey, (int) config('fiscal.hacienda.rate_limit.max_attempts', 10))) {
            $seconds = RateLimiter::availableIn($limitKey);

            /**
             * La API oficial es la única fuente de esta información. Si el
             * límite local está agotado se dice claramente "no pude consultar",
             * nunca "no existe": propose-un-borrado sobre un cliente que sí
             * existe es el peor fallo posible aquí.
             */
            return $this->normalizer->rateLimited(max(1, $seconds));
        }

        RateLimiter::hit($limitKey, (int) config('fiscal.hacienda.rate_limit.decay_seconds', 60));

        try {
            $response = $this->client->get('/fe/ae', ['identificacion' => $identification]);
        } catch (HaciendaRateLimitedException $e) {
            /**
             * 429 con `Retry-After` fuera de presupuesto: el cliente NO durmió
             * (protege `max_execution_time`); aquí se traduce a error tipado
             * con los segundos reales que la API pidió, para reintentar
             * después sin bloquear la petición en curso.
             */
            return $this->normalizer->rateLimited($e->retryAfterSeconds);
        } catch (HaciendaUnavailableException $e) {
            return $this->normalizer->networkError($e->getMessage());
        } catch (Throwable $e) {
            return $this->normalizer->networkError('No se pudo consultar la API oficial de Hacienda');
        }

        return $this->normalizer->fromResponse($response);
    }

    /**
     * Clave GLOBAL: hash de la identificación, sin `company_id` y sin la
     * identificación en claro.
     */
    private function cacheKey(string $identification): string
    {
        return 'fiscal:taxpayer:'.hash('sha256', $identification);
    }

    /**
     * @return int|null segundos de cacheo, o null si el resultado NO se cachea.
     */
    private function ttlFor(FiscalTaxpayerInfo $result): ?int
    {
        if ($result->error === null) {
            return (int) config('fiscal.hacienda.cache_ttl_seconds', 2_592_000);
        }

        /**
         * Solo se cachea un resultado DEFINITIVO: "no existe" o "identificación
         * inválida". Todo lo demás (límite, servidor, red) es transitorio y se
         * vuelve a consultar en el siguiente intento.
         */
        $definitive = in_array($result->error->category, [
            FiscalError::CATEGORY_UNKNOWN,
            FiscalError::CATEGORY_VALIDATION,
        ], true);

        if (! $definitive) {
            return null;
        }

        return (int) config('fiscal.hacienda.negative_cache_seconds', 3600);
    }

    private function store(): ?Cache
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $store = config('fiscal.hacienda.cache_store');

        return $store !== null && $store !== ''
            ? CacheManager::store((string) $store)
            : CacheManager::store();
    }

    /**
     * Cubo ÚNICO para todas las empresas. Deliberadamente no incluye tenant ni
     * empresa: el límite que importa es el de la IP de salida.
     */
    private function rateLimitKey(): string
    {
        return 'hacienda-fe-ae-global';
    }

    /**
     * Nunca se registra la respuesta: contiene nombre, régimen y actividades de
     * una persona física o jurídica (Ley 8968, Ley 10173). Solo la
     * identificación enmascarada y el resultado técnico.
     */
    private function log(string $event, string $identification, FiscalTaxpayerInfo $result): void
    {
        Log::info('fiscal.taxpayer_lookup', [
            'event' => $event,
            'identification_masked' => FiscalIdentification::mask($identification),
            'found' => $result->found,
            'error_category' => $result->error?->category,
        ]);
    }
}
