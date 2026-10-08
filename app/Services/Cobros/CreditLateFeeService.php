<?php

namespace App\Services\Cobros;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * BLOQUE 9B.3 — MOTOR DE CÁLCULO de mora + días de gracia (puro, sin persistencia).
 *
 * No cobra: no modifica CxC, cuotas, recibos, WhatsApp, PDF ni terms_snapshot.
 * La fecha contractual (due_date) NUNCA cambia; la mora es SEPARADA del
 * capital/interés contractual (sin capitalización ni interés sobre mora).
 *
 * Regla de vencimiento (ejemplo: vence 20/10, gracia 3):
 * 20,21,22,23 → sin mora; desde 24/10 → vencida con mora.
 * effective_late_date = due_date + días de gracia + 1.
 *
 * Semanticas guardadas en 9A (credit-define):
 * - fixed  → "Monto que cobrará por cada atraso" (una sola vez por atraso;
 *            sin periodicidad diaria adicional).
 * - percent→ "2% sobre el pago atrasado" = percent × contractual_amount de la
 *            cuota, una vez por atraso (mismo patrón "por atraso" que fixed).
 *
 * Fechas: fecha local (mismo patrón today()/now() del calendario contractual).
 * Precisión: BCMath/decimal exacto, sin floats; round4 reutilizado del motor 9A.
 */
final class CreditLateFeeService
{
    public function __construct(private InstallmentScheduleService $schedule)
    {
    }

    /**
     * Evalúa la cuota para la fecha de evaluación.
     *
     * @return array{is_overdue: bool, days_late: int, grace_days: int, late_fee_due: string, effective_late_date: string}
     */
    public function evaluate(CobrosCredit $credit, CobrosCreditInstallment $installment, CarbonInterface $on): array
    {
        $terms = $credit->terms_snapshot ?? [];
        $type = (string) ($terms['late_fee_type'] ?? $credit->late_fee_type);
        $value = $this->schedule->money((string) ($terms['late_fee_value'] ?? $credit->late_fee_value ?? '0'));
        $grace = (int) ($terms['late_fee_grace_days'] ?? $credit->late_fee_grace_days ?? 0);

        $due = CarbonImmutable::parse($installment->due_date->toDateString());
        $effective = $due->addDays($grace + 1);

        // Ventana objetiva: pagada → hasta paid_at (evidencia del atraso real);
        // pendiente → hasta la fecha de evaluación.
        $end = CarbonImmutable::parse(($installment->paid_at ?? $on)->toDateString());

        $isOverdue = $end->gte($effective);
        $daysLate = $isOverdue ? (int) $effective->diffInDays($end, true) + 1 : 0;

        return [
            'is_overdue' => $isOverdue,
            'days_late' => $daysLate,
            'grace_days' => $grace,
            'late_fee_due' => $isOverdue ? $this->feeFor($type, $value, $installment) : $this->schedule->money('0'),
            'effective_late_date' => $effective->toDateString(),
        ];
    }

    private function feeFor(string $type, string $value, CobrosCreditInstallment $installment): string
    {
        return match ($type) {
            CobrosCredit::LATE_FEE_FIXED => $value,
            CobrosCredit::LATE_FEE_PERCENT => $this->percentFee($value, $installment),
            default => $this->schedule->money('0'), // none o desconocido: sin mora.
        };
    }

    /** percent% sobre el pago atrasado (contractual_amount de la cuota). */
    private function percentFee(string $percent, CobrosCreditInstallment $installment): string
    {
        $base = $this->schedule->money((string) $installment->contractual_amount);
        $gross = bcdiv(bcmul($base, $percent, 10), '100', 10);

        return $this->schedule->money($this->schedule->round4($gross));
    }
}
