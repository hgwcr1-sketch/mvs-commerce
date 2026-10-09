<?php

namespace App\Data\Cobros;

use App\Support\Cobros\Money;

/**
 * BLOQUE 17A-1 — cotización mensual CobrOS. El total es decimal string
 * (precisión monetaria); la presentación usa Money, nunca float.
 */
final class CobrosPriceQuote
{
    /**
     * @param  list<CobrosPriceLine>  $lines
     */
    public function __construct(
        public readonly string $countryIso,
        public readonly string $currency,
        public readonly string $billingMode,
        public readonly ?string $modality,
        public readonly array $lines,
        public readonly string $monthlyTotal,
        public readonly int $seats,
    ) {}

    public function formattedTotal(): string
    {
        return Money::human($this->monthlyTotal, $this->currency);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'country_iso' => $this->countryIso,
            'currency' => $this->currency,
            'billing_mode' => $this->billingMode,
            'modality' => $this->modality,
            'lines' => array_map(static fn (CobrosPriceLine $line): array => $line->toArray(), $this->lines),
            'monthly_total' => $this->monthlyTotal,
            'seats' => $this->seats,
        ];
    }
}
