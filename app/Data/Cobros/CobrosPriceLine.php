<?php

namespace App\Data\Cobros;

/**
 * BLOQUE 17A-1 — línea de precio mensual CobrOS (decimal string, sin float).
 * `source` documenta el origen del monto para auditoría comercial:
 * standard | included | negotiated | override.
 */
final class CobrosPriceLine
{
    public function __construct(
        public readonly string $concept,
        public readonly string $label,
        public readonly string $amount,
        public readonly string $source,
    ) {}

    /** @return array{concept: string, label: string, amount: string, source: string} */
    public function toArray(): array
    {
        return [
            'concept' => $this->concept,
            'label' => $this->label,
            'amount' => $this->amount,
            'source' => $this->source,
        ];
    }
}
