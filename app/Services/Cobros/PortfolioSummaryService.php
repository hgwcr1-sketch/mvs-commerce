<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class PortfolioSummaryService
{
    public function summarize(Company $company, ?int $branchId = null): array
    {
        if ($branchId !== null) {
            Branch::query()->where('company_id', $company->id)->findOrFail($branchId);
        }

        $today = CarbonImmutable::today($company->timezone ?: config('app.timezone'));
        $alertDays = (int) ($company->credit_alert_days ?? 5);
        $deadline = $today->addDays($alertDays)->toDateString();
        $date = $today->toDateString();
        $open = AccountReceivable::query()->forCompany($company->id)
            ->when($branchId !== null, fn (Builder $q) => $q->forBranch($branchId))
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED]);
        $overdue = (clone $open)->whereDate('due_date', '<', $date);
        $payments = AccountReceivablePayment::query()->where('company_id', $company->id)
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->where('paid_at', '>=', $today->setTimezone(config('app.timezone')))
            ->where('paid_at', '<', $today->addDay()->setTimezone(config('app.timezone')));

        $metrics = [
            'total_due' => $this->decimalSum(clone $open, 'balance_due'),
            'total_overdue' => $this->decimalSum(clone $overdue, 'balance_due'),
            'collected_today' => $this->decimalSum($payments, 'amount'),
            'customers_with_balance' => (clone $open)->distinct()->count('customer_id'),
            'upcoming_count' => (clone $open)->whereDate('due_date', '>=', $date)->whereDate('due_date', '<=', $deadline)->count(),
            'overdue_count' => $overdue->count(),
        ];

        $accounts = $open->with([
            'customer' => fn ($q) => $q->where('company_id', $company->id),
            'sale' => fn ($q) => $q->where('company_id', $company->id),
            'branch' => fn ($q) => $q->where('company_id', $company->id),
        ])->orderBy('due_date')->orderBy('id')->paginate(20)->withQueryString();

        // Estado de presentación derivado; nunca se persiste ni corrige el registro.
        $accounts->getCollection()->each(function (AccountReceivable $account) use ($date) {
            $account->setAttribute('portfolio_status', $account->due_date->toDateString() < $date
                ? 'Vencida'
                : (bccomp($account->balance_due, $account->original_amount, 4) < 0 ? 'Parcial' : 'Pendiente'));
        });

        return compact('metrics', 'accounts', 'today', 'alertDays');
    }

    /**
     * Suma decimal BCMath (nunca float). Público para reutilizarlo desde el
     * panel del supervisor 4A sin duplicar la regla de suma.
     */
    public function decimalSum(Builder $query, string $column): string
    {
        $sum = '0.0000';
        // Los casts decimal:4 existentes y BCMath evitan sumas float (incluido SQLite).
        foreach ($query->select(['id', $column])->lazyById(500) as $row) {
            $sum = bcadd($sum, $row->{$column}, 4);
        }

        return $sum;
    }
}
