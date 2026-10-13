<?php

namespace App\Services\Billing\Contracts;

/**
 * BLOQUE 17B-1 — eventos normalizados del motor de pagos MVS.
 *
 * Cada evento representa un cambio de estado de un pago, independiente del
 * proveedor y del producto consumidor. Los adaptadores traducen la respuesta
 * del proveedor a estos tipos; el dispatcher los entrega a los módulos.
 */
final class PaymentEventTypes
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    public const RENEWAL = 'renewal';

    public const ALL = [
        self::PENDING,
        self::CONFIRMED,
        self::FAILED,
        self::REFUNDED,
        self::RENEWAL,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    private function __construct()
    {
    }
}
