<?php

namespace App\Services\Cobros;

use App\Models\Company;
use App\Models\User;

/**
 * 9B.3 — Reparto INTERNO de la mora entre prestamista y cobrador.
 *
 * No cambia cuánto debe o paga el cliente: solo configura quién gana qué
 * de la mora ya existente. Sin movimiento financiero en esta fase.
 *
 * Herencia: override del cobrador → porcentaje general → 50%.
 * Rango 0.00–100.00%. 0% = todo al prestamista; 100% = todo al cobrador.
 *
 * Al cobrar mora (fase siguiente) snapshotFor() aporta la estructura
 * inmutable: mora cobrada, porcentaje efectivo, monto cobrador,
 * monto prestamista. Precisión BCMath escala 4, sin floats.
 */
final class LateFeeSplitService
{
    public const DEFAULT_COLLECTOR_PCT = '50.00';

    public function __construct(private InstallmentScheduleService $schedule)
    {
    }

    /** Override → general → default. Siempre formato NN.NN. */
    public function effectivePercentage(Company $company, User $collector): string
    {
        $override = $collector->getAttribute('collector_late_fee_pct');
        if ($override !== null && $override !== '') {
            return $this->normalize((string) $override);
        }

        $general = $company->getAttribute('collector_late_fee_pct');
        if ($general !== null && $general !== '') {
            return $this->normalize((string) $general);
        }

        return self::DEFAULT_COLLECTOR_PCT;
    }

    /**
     * Reparto exacto de una mora: collector + lender = amount (escala 4).
     *
     * @return array{collector: string, lender: string}
     */
    public function split(string $amount, string $collectorPct): array
    {
        $amount = $this->schedule->money($amount);
        $collector = $this->schedule->round4(bcdiv(bcmul($amount, $this->normalize($collectorPct), 10), '100', 10));

        return [
            'collector' => $collector,
            'lender' => bcsub($amount, $collector, 4),
        ];
    }

    /** Estructura de snapshot inmutable para la fase de cobro (sin persistir). */
    public function snapshotFor(string $amount, string $collectorPct): array
    {
        $split = $this->split($amount, $collectorPct);

        return [
            'late_fee_charged' => $this->schedule->money($amount),
            'effective_pct' => $this->normalize($collectorPct),
            'collector_amount' => $split['collector'],
            'lender_amount' => $split['lender'],
        ];
    }

    private function normalize(string $pct): string
    {
        $pct = trim($pct);
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/D', $pct)) {
            throw new \InvalidArgumentException('Porcentaje no válido.');
        }

        [$whole, $fraction] = array_pad(explode('.', $pct, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
