<?php

namespace App\Data\Cobros;

/**
 * PRE-19B-4A — resultado inmutable del cálculo de descuento por saldo a
 * favor de referidos. Cadenas decimales exactas (escala 4), nunca float.
 */
final readonly class ReferralCreditPlan
{
    public function __construct(
        public string $totalOriginal,
        public string $saldoDisponible,
        public string $creditoAplicable,
        public string $totalAPagar,
        public string $saldoRemanente,
        public string $currency,
        public bool $requiereLiquidacionInterna,
    ) {
    }
}
