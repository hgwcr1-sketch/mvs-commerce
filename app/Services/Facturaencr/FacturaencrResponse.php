<?php

namespace App\Services\Facturaencr;

class FacturaencrResponse
{
    public function __construct(
        public readonly string $status,
        public readonly ?array $data = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $httpStatusCode = null,
        public readonly bool $retryable = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}