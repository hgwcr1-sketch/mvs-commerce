<?php

namespace App\DTOs\Fiscal;

use RuntimeException;

class FiscalReferenceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid_reference',
    ) {
        parent::__construct($message);
    }
}
