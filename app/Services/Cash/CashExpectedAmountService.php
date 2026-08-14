<?php

namespace App\Services\Cash;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;

class CashExpectedAmountService
{
    public function calculate(CashSession $session): float
    {
        $cashSales = DB::table('sale_payments as payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('payments.cash_session_id', $session->id)
            ->where('payments.status', SalePayment::STATUS_COMPLETED)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('payments.affects_cash_snapshot', true)
            ->whereNotNull('payments.cash_effect_amount')
            ->sum('payments.cash_effect_amount');

        $entries = CashMovement::forSession($session->id)
            ->where('direction', CashMovement::DIRECTION_IN)
            ->sum('amount');

        $outputs = CashMovement::forSession($session->id)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->sum('amount');

        return round(
            (float) $session->opening_amount + (float) $cashSales + (float) $entries - (float) $outputs,
            4,
        );
    }
}
