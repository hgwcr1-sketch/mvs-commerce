<?php

namespace App\Services\Billing;

use App\Services\Billing\Contracts\PaymentConfirmedHandlerInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use Illuminate\Contracts\Container\Container;

/**
 * BLOQUE 17B-1 — despachante de eventos de pago a los módulos consumidores.
 *
 * Resuelve los handlers registrados en el contenedor que implementan
 * PaymentConfirmedHandlerInterface y entrega cada evento al módulo que
 * corresponda según el producto. CobrOS reutiliza su
 * CobrosPostPaymentActivationService a través de su handler, sin replicar
 * lógica en este motor.
 */
class PaymentDispatcher
{
    /** @var list<PaymentConfirmedHandlerInterface> */
    private array $handlers = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** Registra un handler en memoria (tests o wiring manual). */
    public function register(PaymentConfirmedHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    /** Entrega un evento a todos los handlers que soportan su producto. */
    public function dispatch(PaymentEvent $event): void
    {
        foreach ($this->resolveHandlers() as $handler) {
            if ($handler->supports($event->product)) {
                $handler->handle($event);
            }
        }
    }

    /** @return list<PaymentConfirmedHandlerInterface> */
    private function resolveHandlers(): array
    {
        $resolved = $this->handlers;

        foreach (config('billing.confirmed_handlers', []) as $class) {
            try {
                $handler = $this->container->make($class);
                if ($handler instanceof PaymentConfirmedHandlerInterface) {
                    $resolved[] = $handler;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $resolved;
    }
}
