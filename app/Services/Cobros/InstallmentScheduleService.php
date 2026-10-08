<?php

namespace App\Services\Cobros;

use App\Models\CobrosCredit;
use Carbon\Carbon;

/**
 * BLOQUE 9 — motor PURO de crédito: cálculo de total con interés simple y
 * calendario de cuotas. Sin I/O, sin Eloquent: vista previa y persistencia
 * usan EXACTAMENTE este mismo código.
 *
 * Reglas (decisiones cerradas):
 * - dinero DECIMAL(19,4) con BCMath, redondeo half-up a 4 decimales;
 * - percent: total = principal + principal*(tasa/100) (interés simple);
 * - fixed:  total = principal + monto;
 * - none:   total = principal;
 * - cuotas: base = total/n truncada a 4 dec; la ÚLTIMA cuota absorbe el
 *   residuo de modo que la suma sea EXACTAMENTE total_due.
 */
class InstallmentScheduleService
{
    public const FREQUENCIES = [
        CobrosCredit::FREQUENCY_DAILY,
        CobrosCredit::FREQUENCY_WEEKLY,
        CobrosCredit::FREQUENCY_BIWEEKLY,
        CobrosCredit::FREQUENCY_MONTHLY,
    ];

    /** Total a cobrar con interés simple (half-up 4 decimales). */
    public function totalWithInterest(string $principal, string $interestType, string $interestValue): string
    {
        $principal = $this->money($principal);

        return match ($interestType) {
            CobrosCredit::INTEREST_NONE => $principal,
            CobrosCredit::INTEREST_FIXED => $this->money(bcadd($principal, $this->money($interestValue), 4)),
            CobrosCredit::INTEREST_PERCENT => $this->money(bcadd(
                $principal,
                // interés = principal * tasa / 100 con precisión intermedia 10 y half-up a 4.
                $this->round4(bcdiv(bcmul($principal, $this->money($interestValue), 10), '100', 10)),
                4
            )),
            default => throw new \InvalidArgumentException('Tipo de interés no válido.'),
        };
    }

    /**
     * Calendario completo. $firstDueDate es la fecha de la PRIMERA cuota.
     *
     * @return array<int, array{number: int, due_date: string, contractual_amount: string}>
     */
    public function build(string $totalDue, int $installmentCount, string $frequency, string $firstDueDate): array
    {
        if ($installmentCount < 1) {
            throw new \InvalidArgumentException('La cantidad de cuotas debe ser al menos 1.');
        }
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException('Frecuencia no válida.');
        }

        $total = $this->money($totalDue);
        $base = bcdiv($total, (string) $installmentCount, 4); // truncado a 4 dec
        $rows = [];
        $sum = '0.0000';

        for ($number = 1; $number <= $installmentCount; $number++) {
            $amount = $number < $installmentCount
                ? $base
                : bcsub($total, $sum, 4); // la última absorbe el residuo exacto
            $rows[] = [
                'number' => $number,
                'due_date' => $this->dueDate($firstDueDate, $number - 1, $frequency),
                'contractual_amount' => $amount,
            ];
            $sum = bcadd($sum, $amount, 4);
        }

        // Invariante: la suma del calendario es EXACTAMENTE el total.
        if (bccomp($sum, $total, 4) !== 0) {
            throw new \LogicException('El calendario no suma exactamente el total debido.');
        }

        return $rows;
    }

    /** Total + calendario en una sola llamada (preview y desembolso). */
    public function preview(
        string $principal,
        string $interestType,
        string $interestValue,
        int $installmentCount,
        string $frequency,
        string $firstDueDate
    ): array {
        $total = $this->totalWithInterest($principal, $interestType, $interestValue);

        return [
            'total_due' => $total,
            'rows' => $this->build($total, $installmentCount, $frequency, $firstDueDate),
        ];
    }

    /** Fecha de la cuota con desplazamiento: mensual sin overflow (31 ene → 28 feb). */
    private function dueDate(string $firstDueDate, int $offset, string $frequency): string
    {
        $date = Carbon::parse($firstDueDate);

        return match ($frequency) {
            CobrosCredit::FREQUENCY_DAILY => $date->addDays($offset)->toDateString(),
            CobrosCredit::FREQUENCY_WEEKLY => $date->addWeeks($offset)->toDateString(),
            CobrosCredit::FREQUENCY_BIWEEKLY => $date->addWeeks($offset * 2)->toDateString(),
            CobrosCredit::FREQUENCY_MONTHLY => $date->addMonthsNoOverflow($offset)->toDateString(),
            default => throw new \InvalidArgumentException('Frecuencia no válida.'),
        };
    }

    /** Redondeo half-up a 4 decimales (positivos), sin floats. */
    public function round4(string $value): string
    {
        // Sumar medio ULP y truncar = half-up para valores >= 0.
        return bcadd($value, '0.00005', 4);
    }

    /** Normaliza a 4 decimales (valida formato; nunca float). */
    public function money(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^\d{1,13}(\.\d{1,4})?$/D', $value)) {
            throw new \InvalidArgumentException('Monto no válido.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return $whole.'.'.str_pad(substr($fraction.'00000', 0, 4), 4, '0');
    }
}
