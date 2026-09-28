<?php

namespace App\Rules;

use App\Support\IdentificationRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida la identificación según el tipo seleccionado (POS + Clientes),
 * usando las reglas centralizadas de IdentificationRules.
 */
class ValidIdentification implements ValidationRule
{
    public function __construct(private readonly ?string $type = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('El formato de la identificación no es válido.');

            return;
        }

        if (! IdentificationRules::isValid($this->type, $value)) {
            $fail(IdentificationRules::message($this->type, $value));
        }
    }
}
