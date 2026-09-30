<?php

namespace App\Contracts\Fiscal;

use App\DTOs\Fiscal\FiscalWebhookEvent;

/**
 * Receptor neutral de callbacks del proveedor. Cada adapter implementa la
 * verificación de autenticidad propia (firma/secreto) y la idempotencia
 * (replay seguro); el procesamiento posterior es común y provider-neutral:
 * resuelve tenant/documento, aplica accepted/rejected finales y jamás
 * duplica consumo (el ledger es por documento).
 *
 * BLOQUEADOR FacturaEnCR: la documentación oficial no detalla cabeceras de
 * firma, secreto de webhook ni esquema exacto del evento; sin esa
 * especificación NO se implementa el receptor (no se inventa el contrato).
 * El polling sigue como mecanismo principal y probado.
 */
interface FiscalWebhookHandler
{
    public function handle(FiscalWebhookEvent $event): void;
}
