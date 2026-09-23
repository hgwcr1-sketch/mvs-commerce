<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosAssignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2A — Mi Cartera: cartera real del cobrador, estrictamente solo lectura.
 *
 * Autoridad SIEMPRE: empresa activa de la sesión + collector_user_id = auth()->id().
 * Nunca se acepta collector_id desde el request; AccountReceivable, pagos, Customer
 * y assignments solo se consultan. Los saldos se suman con BCMath (sin floats).
 */
class CollectorPortfolioService
{
    public function portfolio(User $collector, Company $company, ?string $search = null): array
    {
        $customers = $this->assignedCustomersQuery($collector, $company, $search)
            ->paginate(20)->withQueryString();

        $customerIds = $customers->getCollection()->modelKeys();

        $openAccounts = AccountReceivable::query()->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->with(['branch', 'sale'])
            ->orderBy('due_date')->orderBy('id')
            ->get();

        $grouped = $openAccounts->groupBy('customer_id');
        $lastPayments = $this->lastReliablePayments($company, $customerIds);

        $customers->getCollection()->each(function (Customer $customer) use ($grouped, $lastPayments, $company) {
            $accounts = $grouped->get($customer->id, collect());
            $balance = '0.0000';
            foreach ($accounts as $account) {
                $balance = bcadd($balance, (string) $account->balance_due, 4);
            }

            $customer->setAttribute('portfolio_balance', $balance);
            $customer->setAttribute('portfolio_open_count', $accounts->count());
            $customer->setAttribute('portfolio_branches', $accounts->pluck('branch.name')->filter()->unique()->values());
            $customer->setAttribute('portfolio_next_due', $accounts->min('due_date'));
            $customer->setAttribute('portfolio_last_payment', $lastPayments->get($customer->id));
        });

        return [
            'customers' => $customers,
            'total_balance' => $this->totalBalance($collector, $company, $search),
            'search' => $search,
        ];
    }

    public function customerPortfolio(User $collector, Company $company, Customer $customer): array
    {
        // 404 ante cliente ajeno, sin asignar o cross-company: nunca 403/405 (no revela existencia).
        abort_unless(
            (int) $customer->company_id === (int) $company->id
            && $this->assignmentExists($collector, $company, $customer),
            404
        );

        $openAccounts = AccountReceivable::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->with(['branch', 'sale'])
            ->orderBy('due_date')->orderBy('id')
            ->get();

        $balance = '0.0000';
        foreach ($openAccounts as $account) {
            $balance = bcadd($balance, (string) $account->balance_due, 4);
        }

        $receivableIds = $openAccounts->modelKeys();

        $payments = $receivableIds === []
            ? collect()
            : AccountReceivablePayment::query()->where('company_id', $company->id)
                ->whereIn('account_receivable_id', $receivableIds)
                ->with('paymentMethod')
                ->orderByDesc('paid_at')->orderByDesc('id')
                ->limit(10)
                ->get();

        return [
            'customer' => $customer,
            'openAccounts' => $openAccounts,
            'total_balance' => $balance,
            'payments' => $payments,
        ];
    }

    private function assignedCustomersQuery(User $collector, Company $company, ?string $search): Builder
    {
        return Customer::query()
            ->where('customers.company_id', $company->id)
            ->where('customers.is_active', true)
            ->whereHas('cobrosAssignments', fn (Builder $q) => $q
                ->forCompany($company->id)
                ->where('collector_user_id', $collector->id))
            ->when($search !== null && trim($search) !== '', function (Builder $q) use ($search) {
                $term = trim($search);
                $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(function (Builder $w) use ($operator, $term) {
                    foreach (['name', 'commercial_name', 'identification', 'phone', 'mobile'] as $field) {
                        $w->orWhere($field, $operator, "%{$term}%");
                    }
                });
            })
            ->orderBy('name')->orderBy('id');
    }

    private function totalBalance(User $collector, Company $company, ?string $search): string
    {
        $customerIds = $this->assignedCustomersQuery($collector, $company, $search)->pluck('customers.id');
        $balance = '0.0000';
        if ($customerIds->isEmpty()) {
            return $balance;
        }

        foreach (AccountReceivable::query()->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->select(['id', 'balance_due'])->lazyById(500) as $row) {
            $balance = bcadd($balance, (string) $row->balance_due, 4);
        }

        return $balance;
    }

    /**
     * Último pago real por cliente: solo si existe fuente fiable (pago registrado
     * con método y sesión de caja). CobrOS no crea pagos en 2A; solo consulta.
     */
    private function lastReliablePayments(Company $company, array $customerIds): Collection
    {
        if ($customerIds === []) {
            return collect();
        }

        return AccountReceivablePayment::query()->where('company_id', $company->id)
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('payment_method_id')
            ->whereNotNull('cash_session_id')
            ->with('paymentMethod')
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->get()
            ->unique('customer_id')
            ->keyBy('customer_id');
    }

    private function assignmentExists(User $collector, Company $company, Customer $customer): bool
    {
        return CobrosAssignment::query()->forCompany($company->id)
            ->where('collector_user_id', $collector->id)
            ->where('customer_id', $customer->id)
            ->exists();
    }
}
