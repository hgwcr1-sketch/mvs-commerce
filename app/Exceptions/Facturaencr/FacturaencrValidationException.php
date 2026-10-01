<?php

namespace App\Exceptions\Facturaencr;

use RuntimeException;

class FacturaencrValidationException extends RuntimeException
{
    public function __construct(array $errors, int $code = 0, ?\Throwable $previous = null)
    {
        $this->errors = $errors;
        $message = 'Facturaencr validation failed: ' . json_encode($errors, JSON_UNESCAPED_UNICODE);
        parent::__construct($message, $code, $previous);
    }

    public function getErrors(): array
    {
        return $this->errors ?? [];
    }
}