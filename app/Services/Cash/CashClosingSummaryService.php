<?php

namespace App\Services\Cash;

use App\Models\CashSession;
use App\Models\PaymentMethod;

class CashClosingSummaryService
{
    /** El efectivo físico ya incluye fondo inicial y movimientos de caja. */
    public function summarize(CashSession $session): array
    {
        $expected = (string) ($session->expected_cash ?? '0');
        $reported = (string) ($session->counted_cash ?? '0');
        foreach ($session->paymentReconciliations as $row) {
            if ($row->affects_cash_snapshot ?? ($row->payment_method_type_snapshot === PaymentMethod::TYPE_CASH)) {
                continue;
            }
            $expected = bcadd($expected, $row->expected_amount, 4);
            $reported = bcadd($reported, $row->reported_amount, 4);
        }

        return array_merge(
            ['expected' => bcadd($expected, '0', 4), 'reported' => bcadd($reported, '0', 4), 'difference' => bcsub($reported, $expected, 4)],
            $session->documentsBreakdown()
        );
    }
}
