<?php

namespace App\DTOs\Fiscal;

/**
 * Resultado MVS de una emisión fiscal. "state" usa únicamente el ciclo
 * de vida propio (MVS), no los estados crudos del proveedor.
 */
final class FiscalEmissionResult
{
    public const STATE_QUEUED = 'queued';
    public const STATE_PENDING = 'pending';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_REJECTED = 'rejected';
    public const STATE_ERROR = 'error';

    public function __construct(
        public readonly string $state,
        public readonly ?int $electronicDocumentId = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $fiscalReference = null,
        public readonly ?FiscalError $error = null,
    ) {
    }

    public function isFinal(): bool
    {
        return in_array($this->state, [self::STATE_ACCEPTED, self::STATE_REJECTED], true);
    }

    public function isError(): bool
    {
        return $this->state === self::STATE_ERROR;
    }
}
