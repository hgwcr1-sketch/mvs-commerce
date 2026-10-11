<?php

namespace App\Services\Cobros;

/**
 * INTEGRACIÓN-05 — control único de habilitación del DEMO CobrOS.
 *
 * Reglas:
 *  - Producción SIEMPRE bloqueada (corte duro, ignorando la configuración).
 *  - Fuera de producción solo se permite con habilitación explícita
 *    (config demo.enabled => DEMO_ENABLED=true) y un entorno permitido
 *    (local, staging o testing).
 *
 * Rutas, controladores, servicios y vistas usan este mismo punto de verdad.
 */
final class CobrosDemoAccess
{
    public static function enabled(): bool
    {
        $environment = (string) app()->environment();

        if ($environment === 'production') {
            return false;
        }

        if (! (bool) config('demo.enabled', false)) {
            return false;
        }

        return in_array($environment, (array) config('demo.allowed_environments', []), true);
    }
}
