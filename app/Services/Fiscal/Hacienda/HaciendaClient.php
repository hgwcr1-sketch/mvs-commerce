<?php

namespace App\Services\Fiscal\Hacienda;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP de la API oficial de Hacienda (MF03).
 *
 * `api.hacienda.go.cr` NO es el canal de envío de comprobantes: es un servicio
 * de consulta de datos maestros. Este cliente solo habla con `/fe/*`.
 *
 * No registra cuerpos de respuesta: contienen nombre, régimen y actividades de
 * una persona física o jurídica, protegidos por la Ley 8968 y la Ley 10173.
 */
class HaciendaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $retryAttempts,
        private readonly int $backoffBaseMilliseconds,
        private readonly int $retryAfterMaxSeconds = 5,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('fiscal.hacienda.base_url', 'https://api.hacienda.go.cr'), '/'),
            timeoutSeconds: (int) config('fiscal.hacienda.timeout_seconds', 8),
            retryAttempts: (int) config('fiscal.hacienda.retry_attempts', 2),
            backoffBaseMilliseconds: (int) config('fiscal.hacienda.backoff_base_ms', 250),
            retryAfterMaxSeconds: (int) config('fiscal.hacienda.retry_after_max_seconds', 5),
        );
    }

    /**
     * @param  array<string, string|int>  $query
     *
     * @throws HaciendaUnavailableException si, tras reintentar, la API no responde.
     * @throws HaciendaRateLimitedException si la API pide esperar más de
     *                                      `retry_after_max_seconds`: se devuelve
     *                                      de inmediato para que el llamador decida
     *                                      reintentar después, nunca durmiendo
     *                                      hasta agotar `max_execution_time`.
     */
    public function get(string $path, array $query): Response
    {
        $attempt = 0;
        $lastThrowable = null;

        while ($attempt <= $this->retryAttempts) {
            $attempt++;

            try {
                $response = $this->request()->get($path, $query);

                /**
                 * Solo se reintenta lo que es claramente transitorio. Un 400 o
                 * un 404 no mejoran con más intentos: consumed, se devuelven
                 * para que el normalizador los traduzca.
                 */
                if ($response->status() === 429 || $response->serverError()) {
                    $this->log('retryable', $path, $response->status(), $attempt);

                    /**
                     * Un `Retry-After` mayor que el presupuesto configurado no
                     * se duerme: dormir minutos dentro de una petición web
                     * agota `max_execution_time` y el usuario ve un 500 en vez
                     * de un error entendible. Se lanza de inmediato con los
                     * segundos que la API pidió.
                     */
                    if ($response->status() === 429) {
                        $retryAfter = $this->retryAfter($response);

                        if ($retryAfter > $this->retryAfterMaxSeconds) {
                            throw new HaciendaRateLimitedException($retryAfter);
                        }
                    }

                    if ($attempt > $this->retryAttempts) {
                        return $response;
                    }

                    $this->sleep($response, $attempt);

                    continue;
                }

                $this->log('answered', $path, $response->status(), $attempt);

                return $response;
            } catch (HaciendaRateLimitedException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastThrowable = $e;

                $this->log('network_error', $path, null, $attempt);

                if ($attempt > $this->retryAttempts) {
                    break;
                }

                $this->sleep(null, $attempt);
            }
        }

        throw new HaciendaUnavailableException(
            'La API oficial de Hacienda no respondió',
            0,
            $lastThrowable
        );
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders(['User-Agent' => 'MVS-Commerce-Fiscal/1.0'])
            ->baseUrl($this->baseUrl);
    }

    /**
     * Segundos del `Retry-After`, o 0 si la API no lo envió o no es un entero.
     */
    private function retryAfter(Response $response): int
    {
        return (int) ($response->header('Retry-After') ?? 0);
    }

    /**
     * Backoff exponencial con jitter. El jitter importa: sin él, N consultas
     * simultáneas que reciben 429 reintentan a la vez y reproducen la ráfaga
     * que provocaron el bloqueo.
     *
     * Un `Retry-After` ya verificado contra el presupuesto
     * (`retry_after_max_seconds`) se respeta; uno mayor nunca llega aquí.
     */
    private function sleep(?Response $response, int $attempt): void
    {
        if ($response !== null && $response->status() === 429) {
            $retryAfter = $this->retryAfter($response);

            if ($retryAfter > 0) {
                usleep($retryAfter * 1_000_000);

                return;
            }
        }

        $milliseconds = $this->backoffBaseMilliseconds * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int) ($milliseconds / 2));

        usleep(($milliseconds + $jitter) * 1_000);
    }

    private function log(string $event, string $path, ?int $status, int $attempt): void
    {
        Log::info('fiscal.hacienda', [
            'event' => $event,
            'path' => $path,
            'status' => $status,
            'attempt' => $attempt,
        ]);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}
