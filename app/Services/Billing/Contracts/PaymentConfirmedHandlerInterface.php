<?php

namespace App\Services\Billing\Contracts;

use App\Services\Billing\DTOs\PaymentEvent;

/**
 * BLOQUE 17B-1 — contrato de entrega de pagos confirmados al módulo
 * consumidor.
 *
 * Cada producto (CobrOS, POS, etc.) registra un handler que implementa esta
 * interfaz. El dispatcher entrega los eventos confirmados a los handlers
 * cuyo `supports()` coincida con el producto del evento. CobrOS reutiliza
 * su CobrosPostPaymentActivationService sin replicar lógica.
 */
interface PaymentConfirmedHandlerInterface
{
    /** true si este handler consume eventos del producto indicado. */
    public function supports(string $product): bool;

    /** Procesa un pago confirmado (idempotente). */
    public function handle(PaymentEvent $event): void;
}
