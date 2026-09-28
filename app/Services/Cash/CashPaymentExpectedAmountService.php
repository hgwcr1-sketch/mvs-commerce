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
                $query->where('is_active', true)->orWhereIn('id', $historicalIds);
            })
            ->ordered()
            ->get();
    }

    /** @return Collection<int, float> */
    public function expectedAmounts(CashSession $session): Collection
    {
        return $this->breakdown($session)
            ->map(fn (array $amounts) => $amounts['total']);
    }

    /** @return Collection<int, array{sales: float, receivables: float, layaways: float, payables: float, total: float}> */
    public function breakdown(CashSession $session): Collection
    {
        return $this->breakdownDecimal($session)->map(fn (array $amounts) => array_map(fn ($amount) => (float) $amount, $amounts));
    }

    public function breakdownDecimal(CashSession $session): Collection
    {
        $sales = DB::table('sale_payments as payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('payments.cash_session_id', $session->id)
            ->where('payments.status', SalePayment::STATUS_COMPLETED)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->groupBy('payments.payment_method_id')
            ->selectRaw('payments.payment_method_id, SUM(CASE WHEN payments.affects_cash_snapshot THEN COALESCE(payments.cash_effect_amount, payments.amount) ELSE payments.amount END) as expected_amount')
            ->pluck('expected_amount', 'payments.payment_method_id')
            ->map(fn ($amount) => bcadd((string) $amount, '0', 4));
        $receivables = DB::table('accounts_receivable_payments as payments')
            ->where('payments.cash_session_id', $session->id)
            ->groupBy('payments.payment_method_id')
            ->selectRaw('payments.payment_method_id, SUM(CASE WHEN payments.affects_cash_snapshot THEN payments.cash_effect_amount ELSE payments.amount END) as expected_amount')
            ->pluck('expected_amount', 'payments.payment_method_id')
            ->map(fn ($amount) => bcadd((string) $amount, '0', 4));
        $layaways = DB::table('layaway_payments')->where('cash_session_id', $session->id)->groupBy('payment_method_id')->selectRaw('payment_method_id, SUM(CASE WHEN affects_cash_snapshot THEN cash_effect_amount ELSE amount END) as expected_amount')->pluck('expected_amount', 'payment_method_id');
        $payables = DB::table('accounts_payable_payments')->where('cash_session_id', $session->id)->groupBy('payment_method_id')->selectRaw('payment_method_id, SUM(amount) as expected_amount')->pluck('expected_amount', 'payment_method_id');

        return $sales->keys()->merge($receivables->keys())->merge($layaways->keys())->merge($payables->keys())->unique()
            ->mapWithKeys(function ($methodId) use ($sales, $receivables, $layaways, $payables) {
                $saleAmount = bcadd((string) $sales->get($methodId, 0), '0', 4);
                $receivableAmount = bcadd((string) $receivables->get($methodId, 0), '0', 4);
                $layawayAmount = bcadd((string) $layaways->get($methodId, 0), '0', 4);
                $payableAmount = bcadd((string) $payables->get($methodId, 0), '0', 4);

                return [(int) $methodId => [
                    'sales' => $saleAmount,
                    'receivables' => $receivableAmount,
                    'layaways' => $layawayAmount,
                    'payables' => $payableAmount,
                    'total' => bcadd(bcadd($saleAmount, $receivableAmount, 4), bcadd($layawayAmount, $payableAmount, 4), 4),
                ]];
            });
    }
}
