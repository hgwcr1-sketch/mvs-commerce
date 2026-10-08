<?php

namespace App\DTOs\Fiscal;

/**
 * Error fiscal neutral: categoría + reintento en lugar de códigos
 * propietarios del proveedor.
 */
final class FiscalError
{
    public const CATEGORY_VALIDATION = 'validation';
    public const CATEGORY_AUTHENTICATION = 'authentication';
    public const CATEGORY_AUTHORIZATION = 'authorization';
    public const CATEGORY_CONFLICT = 'conflict';
    public const CATEGORY_RATE_LIMIT = 'rate_limit';
    public const CATEGORY_SERVER = 'server';
    public const CATEGORY_NETWORK = 'network';
    public const CATEGORY_REJECTED = 'provider_rejection';
    public const CATEGORY_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $category,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?array $context = null,
    ) {
    }
}
