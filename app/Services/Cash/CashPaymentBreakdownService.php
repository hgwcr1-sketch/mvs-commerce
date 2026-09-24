<?php

namespace App\Services\Cash;

use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use Illuminate\Support\Collection;

class CashPaymentBreakdownService
{
    private const NON_MONETARY_TYPES = [
        PaymentMethod::TYPE_CREDIT,
        PaymentMethod::TYPE_LOYALTY_POINTS,
    ];

    public function summarize(CashSession $session): array
    {
        $rows = $this->paymentsQuery($session)->get();

        $monetary = collect();
        $nonMonetary = collect();

        foreach ($rows->groupBy('payment_method_id') as $methodId => $group) {
            $method = $group->first()->paymentMethod;
            $type = (string) ($method?->type ?? PaymentMethod::TYPE_OTHER);
            $totalCrc = '0';
            $totalUsd = '0';
            foreach ($group as $payment) {
                $totalCrc = bcadd($totalCrc, (string) $payment->amount, 4);
                if ($payment->cash_effect_amount_usd !== null) {
                    $totalUsd = bcadd($totalUsd, (string) $payment->cash_effect_amount_usd, 4);
                }
            }

            $bucket = [
                'payment_method_id' => (int) $methodId,
                'code' => $method?->code ?? 'desconocido',
                'name' => $method?->name ?? 'Método desconocido',
                'type' => $type,
                'total_crc' => $totalCrc,
                'total_usd' => $totalUsd,
                'payments_count' => $group->count(),
            ];

            if (in_array($type, self::NON_MONETARY_TYPES, true)) {
                $nonMonetary->push($bucket);
            } else {
                $monetary->push($bucket);
            }
        }

        $monetary = $monetary->sortBy([['type', 'asc'], ['name', 'asc']])->values();
        $nonMonetary = $nonMonetary->sortBy('name')->values();

        $collectedCrc = '0';
        $collectedUsd = '0';
        foreach ($monetary as $bucket) {
            $collectedCrc = bcadd($collectedCrc, $bucket['total_crc'], 4);
            $collectedUsd = bcadd($collectedUsd, $bucket['total_usd'], 4);
        }

        return [
            'monetary' => $monetary,
            'non_monetary' => $nonMonetary,
            'collected_crc' => $collectedCrc,
            'collected_usd' => $collectedUsd,
            'details' => $this->details($session),
        ];
    }

    public function details(CashSession $session): Collection
    {
        return $this->paymentsQuery($session)
            ->with(['sale.customer', 'createdBy', 'paymentMethod'])
            ->orderBy('sale_payments.id')
            ->get()
            ->map(fn (SalePayment $payment) => [
                'payment_method_id' => (int) $payment->payment_method_id,
                'sale_id' => (int) $payment->sale_id,
                'sale_number' => $payment->sale?->sale_number,
                'sold_at' => $payment->sale?->created_at,
                'customer_name' => $payment->sale?->customer?->name ?? 'Consumidor final',
                'cashier_name' => $payment->createdBy?->name ?? '—',
                'sale_total' => (string) ($payment->sale?->total ?? '0'),
                'amount_crc' => (string) $payment->amount,
                'amount_usd' => $payment->cash_effect_amount_usd !== null ? (string) $payment->cash_effect_amount_usd : null,
                'reference' => $payment->reference,
                'status' => $payment->status,
            ]);
    }

    private function paymentsQuery(CashSession $session)
    {
        return SalePayment::query()
            ->where('sale_payments.cash_session_id', $session->id)
            ->where('sale_payments.status', SalePayment::STATUS_COMPLETED)
            ->whereHas('sale', fn ($query) => $query->where('sales.status', \App\Models\Sale::STATUS_COMPLETED));
    }
}
