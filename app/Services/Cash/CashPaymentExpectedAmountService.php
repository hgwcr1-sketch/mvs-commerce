<?php

namespace App\Services\Cash;

use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CashPaymentExpectedAmountService
{
    /** @return Collection<int, PaymentMethod> */
    public function methods(CashSession $session): Collection
    {
        $historicalIds = $this->expectedAmounts($session)->keys();

        return PaymentMethod::query()
            ->forCompany($session->company_id)
            ->where(function ($query) use ($historicalIds) {
                $query->where(function ($active) {
                    $active->where('is_active', true)->where('affects_cash', false);
                })->orWhereIn('id', $historicalIds);
            })
            ->ordered()
            ->get();
    }

    /** @return Collection<int, float> */
    public function expectedAmounts(CashSession $session): Collection
    {
        return DB::table('sale_payments as payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('payments.cash_session_id', $session->id)
            ->where('payments.status', SalePayment::STATUS_COMPLETED)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('payments.affects_cash_snapshot', false)
            ->groupBy('payments.payment_method_id')
            ->selectRaw('payments.payment_method_id, SUM(payments.amount) as expected_amount')
            ->pluck('expected_amount', 'payments.payment_method_id')
            ->map(fn ($amount) => (float) $amount);
    }
}
