<?php

namespace App\Services\Fiscal\Hacienda;

use RuntimeException;
use Throwable;

/**
 * La API oficial respondió 429 con un `Retry-After` mayor que el presupuesto
 * configurable (`fiscal.hacienda.retry_after_max_seconds`).
 *
 * Es un error TIPADO y devuelto de inmediato: nunca se duerme la petición
 * minutos dentro de un ciclo web hasta agotar `max_execution_time` (eso sería
 * un 500 opaco). Quien llama decide reintentar después, idealmente en cola.
 *
 * `retryAfterSeconds` viaja en el mensaje para que el normalizador pueda
 * exponer el `Retry-After` real de la API sin acoplar la firma de
 * `HaciendaClient::get()` a una excepción más.
 */
class HaciendaRateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(sprintf(
            'La API oficial de Hacienda pidió esperar %d s antes de reintentar',
            $retryAfterSeconds
        ));
    }
}
