<?php

namespace App\Support\Cobros;

/**
 * Fase 3A — Decisión del Gate de cobro.
 *
 * Objeto de valor inmutable: motivo + mensaje cotidiano para el cobrador.
 * Los motivos son parte del contrato entre el Gate, la UI y las pruebas.
 * No contiene datos de dinero: solo decisiones de autorización.
 */
final class PaymentGateDecision
{
    public const ALLOWED = 'allowed';

    public const PERMISSION_DENIED = 'permission_denied';

    public const OTHER_COMPANY = 'other_company';

    public const CUSTOMER_NOT_ASSIGNED = 'customer_not_assigned';

    public const BRANCH_NOT_AUTHORIZED = 'branch_not_authorized';

    public const NO_BALANCE = 'no_balance';

    public const ACCOUNT_CLOSED = 'account_closed';

    /** Motivos que no deben revelar existencia del recurso: 404 (respuesta segura del proyecto). */
    private const HIDDEN_REASONS = [self::OTHER_COMPANY, self::CUSTOMER_NOT_ASSIGNED];

    private function __construct(
        public readonly string $reason,
        public readonly string $message,
    ) {}

    public static function allowed(): self
    {
        return new self(self::ALLOWED, 'Puede iniciar el cobro.');
    }

    public static function denied(string $reason, string $message): self
    {
        return new self($reason, $message);
    }

    public function isAllowed(): bool
    {
        return $this->reason === self::ALLOWED;
    }

    public function isDeniedBecause(string $reason): bool
    {
        return $this->reason === $reason;
    }

    public function httpStatus(): int
    {
        return in_array($this->reason, self::HIDDEN_REASONS, true) ? 404 : 403;
    }
}
