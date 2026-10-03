<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivablePayment;
use App\Models\CobrosDailyClosing;
use App\Models\CobrosPaymentReversal;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Cierre diario del cobrador.
 *
 * El cobrador declara los totales del día por medio de pago; el servicio
 * calcula los totales REALES del mismo día (pagos del cobrador menos sus
 * reversos, agrupados por tipo de medio) y guarda el snapshot junto al
 * cierre. El dueño compara declarado vs snapshot sin recalcular.
 *
 * Dinero siempre en escala 4 con BCMath: sin floats ni round().
 */
class DailyClosingService
{
    /** Bucket canónico = tipo de PaymentMethod agrupado para el cierre. */
    public const BUCKETS = ['cash', 'sinpe', 'card', 'bank_transfer', 'other'];

    /**
     * Totales reales de un día para un cobrador.
     *
     * @return array{buckets: array<string, string>, total: string, payments_count: int, reversals_count: int}
     */
    public function computeDayTotals(Company $company, User $collector, CarbonImmutable $day): array
    {
        $timezone = $company->timezone ?: config('app.timezone');
        $start = $day->copy()->startOfDay()->setTimezone($timezone);
        $end = $start->copy()->addDay();

        $payments = AccountReceivablePayment::query()
            ->where('company_id', $company->id)
            ->where('user_id', $collector->id)
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->with('paymentMethod:id,type')
            ->get();

        $buckets = array_fill_keys(self::BUCKETS, '0.0000');
        $bucketByPayment = [];

        foreach ($payments as $payment) {
            $bucket = $this->bucketFor($payment->paymentMethod?->type);
            $bucketByPayment[$payment->id] = $bucket;
            $buckets[$bucket] = bcadd($buckets[$bucket], (string) $payment->amount, 4);
        }

        // Neto: los reversos restan al medio del pago anulado (el pago original
        // queda intacto por diseño de 3C; nunca se muta el histórico).
        $reversals = CobrosPaymentReversal::query()
            ->forCompany($company->id)
            ->whereIn('payment_id', array_keys($bucketByPayment))
            ->get(['payment_id', 'amount']);

        foreach ($reversals as $reversal) {
            $bucket = $bucketByPayment[$reversal->payment_id];
            $buckets[$bucket] = bcsub($buckets[$bucket], (string) $reversal->amount, 4);
        }

        $total = '0.0000';
        foreach ($buckets as $value) {
            $total = bcadd($total, $value, 4);
        }

        return [
            'buckets' => $buckets,
            'total' => $total,
            'payments_count' => $payments->count(),
            'reversals_count' => $reversals->count(),
        ];
    }

    /**
     * Envío idempotente: un cierre por cobrador/empresa/día. Reenviar el mismo
     * día actualiza la declaración (y conserva evidencias no reemplazadas).
     *
     * @param  array{cash?: string, sinpe?: string, card?: string, bank_transfer?: string}  $declared
     */
    public function submit(
        Company $company,
        User $collector,
        CarbonImmutable $day,
        array $declared,
        ?string $notes,
        ?string $depositPath,
        ?string $dataphonePath,
    ): CobrosDailyClosing {
        $timezone = $company->timezone ?: config('app.timezone');
        $businessDate = $day->copy()->setTimezone($timezone)->toDateString();

        $totals = $this->computeDayTotals($company, $collector, $day);

        $existing = CobrosDailyClosing::query()
            ->where('company_id', $company->id)
            ->where('collector_user_id', $collector->id)
            ->whereDate('business_date', $businessDate)
            ->first();

        $closing = $existing ?? new CobrosDailyClosing([
            'company_id' => $company->id,
            'collector_user_id' => $collector->id,
            'business_date' => $businessDate,
        ]);

        $closing->fill([
            'declared_cash' => $declared['cash'] ?? '0.0000',
            'declared_sinpe' => $declared['sinpe'] ?? '0.0000',
            'declared_card' => $declared['card'] ?? '0.0000',
            'declared_bank_transfer' => $declared['bank_transfer'] ?? '0.0000',
            'computed_totals' => $totals,
            'payments_count' => $totals['payments_count'],
            'notes' => $notes,
            'evidence_deposit_path' => $depositPath ?? $existing?->evidence_deposit_path,
            'evidence_dataphone_path' => $dataphonePath ?? $existing?->evidence_dataphone_path,
            'submitted_at' => now(),
        ])->save();

        return $closing;
    }

    /**
     * Declarado − snapshot por bucket y total, con bandera de diferencias.
     *
     * @return array{buckets: array<string, array{declared: string, computed: string, difference: string}>, total: array{declared: string, computed: string, difference: string}, has_differences: bool}
     */
    public function differences(CobrosDailyClosing $closing): array
    {
        $computed = $closing->computed_totals ?? [];

        $declaredByBucket = [
            'cash' => (string) $closing->declared_cash,
            'sinpe' => (string) $closing->declared_sinpe,
            'card' => (string) $closing->declared_card,
            'bank_transfer' => (string) $closing->declared_bank_transfer,
            'other' => '0.0000',
        ];

        $rows = [];
        $hasDifferences = false;

        foreach (self::BUCKETS as $bucket) {
            $declared = $declaredByBucket[$bucket] ?? '0.0000';
            $computedValue = (string) ($computed['buckets'][$bucket] ?? '0.0000');
            $difference = bcsub($declared, $computedValue, 4);

            if (bccomp($difference, '0.0000', 4) !== 0) {
                $hasDifferences = true;
            }

            $rows[$bucket] = [
                'declared' => $declared,
                'computed' => $computedValue,
                'difference' => $difference,
            ];
        }

        $declaredTotal = '0.0000';
        foreach ($declaredByBucket as $value) {
            $declaredTotal = bcadd($declaredTotal, $value, 4);
        }
        $computedTotal = (string) ($computed['total'] ?? '0.0000');
        $totalDifference = bcsub($declaredTotal, $computedTotal, 4);

        if (bccomp($totalDifference, '0.0000', 4) !== 0) {
            $hasDifferences = true;
        }

        return [
            'buckets' => $rows,
            'total' => [
                'declared' => $declaredTotal,
                'computed' => $computedTotal,
                'difference' => $totalDifference,
            ],
            'has_differences' => $hasDifferences,
        ];
    }

    public function bucketFor(?string $type): string
    {
        return match ($type) {
            PaymentMethod::TYPE_CASH => 'cash',
            PaymentMethod::TYPE_SINPE => 'sinpe',
            PaymentMethod::TYPE_CARD => 'card',
            PaymentMethod::TYPE_BANK_TRANSFER => 'bank_transfer',
            default => 'other',
        };
    }
}
