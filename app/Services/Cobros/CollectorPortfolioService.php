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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fase 2A — Mi Cartera: cartera real del cobrador, estrictamente solo lectura.
 *
 * Autoridad SIEMPRE: empresa activa de la sesión + collector_user_id = auth()->id().
 * Nunca se acepta collector_id desde el request; AccountReceivable, pagos, Customer
 * y assignments solo se consultan. Los saldos se suman con BCMath (sin floats).
 */
class CollectorPortfolioService
{
    public function customerSuggestions(User $collector, Company $company, string $search): Collection
    {
        return $this->assignedCustomersQuery($collector, $company, trim($search), false)
            ->limit(10)->get(['customers.id', 'customers.name', 'customers.phone', 'customers.email']);
    }

    public function portfolio(User $collector, Company $company, ?string $search = null): array
    {
        // 1) Mi cartera = SOLO deuda activa: quien canceló todo (saldo real 0)
        //    desaparece de la lista, sin borrar Customer, assignment ni pagos.
        $customers = $this->assignedCustomersQuery($collector, $company, $search, true)
            ->paginate(20)->withQueryString();

        $customerIds = $customers->getCollection()->modelKeys();

        // Nombres de ruta por cliente (para mostrar "Ruta" en vez de sucursal).
        $routeNames = $customerIds === [] ? collect() : CobrosAssignment::query()->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)->where('collector_user_id', $collector->id)
            ->whereNotNull('cobros_route_id')->with('cobrosRoute:id,name')->get()
            ->filter()->groupBy('customer_id')
            ->map(fn ($rows) => $rows->pluck('cobrosRoute.name')->filter()->unique()->values());

        $openAccounts = AccountReceivable::query()->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->with(['branch', 'sale'])
            ->orderBy('due_date')->orderBy('id')
            ->get();

        $grouped = $openAccounts->groupBy('customer_id');
        $lastPayments = $this->lastReliablePayments($company, $customerIds);

        $customers->getCollection()->each(function (Customer $customer) use ($grouped, $lastPayments, $company, $routeNames) {
            $accounts = $grouped->get($customer->id, collect());
            $balance = '0.0000';
            foreach ($accounts as $account) {
                $balance = bcadd($balance, (string) $account->balance_due, 4);
            }

            $customer->setAttribute('portfolio_balance', $balance);
            $customer->setAttribute('portfolio_open_count', $accounts->count());
            $customer->setAttribute('portfolio_branches', $accounts->pluck('branch.name')->filter()->unique()->values());
            $customer->setAttribute('portfolio_routes', $routeNames->get($customer->id, collect()));
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

        $expediente = \App\Models\CobrosCreditExpediente::query()
            ->where('company_id', $company->id)->where('customer_id', $customer->id)
            ->with(['references', 'documents'])->first();
        $requests = \App\Models\CobrosCreditRequest::query()
            ->where('company_id', $company->id)->where('customer_id', $customer->id)
            ->orderByDesc('id')->limit(20)->get();
        $credits = \App\Models\CobrosCredit::query()
            ->where('company_id', $company->id)->where('customer_id', $customer->id)
            ->orderByDesc('id')->limit(20)->get();
        $receipts = \App\Models\CobrosReceipt::query()
            ->where('company_id', $company->id)->where('customer_id', $customer->id)
            ->orderByDesc('paid_at')->orderByDesc('id')->limit(20)->get();
        $visits = \App\Models\CobrosVisit::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)->where('collector_user_id', $collector->id)
            ->orderByDesc('scheduled_for')->orderByDesc('id')->limit(10)->get();
        $nextDue = null;
        foreach ($credits as $cr) {
            $inst = \App\Models\CobrosCreditInstallment::query()->where('cobros_credit_id', $cr->id)
                ->where('status', '!=', 'paid')->orderBy('due_date')->first();
            if ($inst) {
                $cr->setAttribute('next_due_date', $inst->due_date);
                $cr->setAttribute('next_due_amount', $inst->total_due ?? $inst->amount ?? null);
                if ($nextDue === null || $inst->due_date < $nextDue) {
                    $nextDue = $inst->due_date;
                }
            }
        }

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
            'expediente' => $expediente,
            'creditRequests' => $requests,
            'credits' => $credits,
            'receipts' => $receipts,
            'visits' => $visits,
            'nextDueDate' => $nextDue,
        ];
    }

    /**
     * 2) MIS CLIENTES — todas las personas legítimamente relacionadas con este
     * cobrador/ruta (incluidas las que ya terminaron de pagar), con estado
     * humano de crédito. No borra nada: es la vista de expediente histórico.
     */
    public function misClientes(User $collector, Company $company, ?string $search = null): array
    {
        $customers = $this->assignedCustomersQuery($collector, $company, $search, false)
            ->paginate(20)->withQueryString();

        $customerIds = $customers->getCollection()->modelKeys();

        $activeIds = $customerIds === [] ? [] : AccountReceivable::query()->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->distinct()->pluck('customer_id')->all();

        $customers->getCollection()->each(function (Customer $customer) use ($activeIds) {
            $customer->setAttribute('has_active_debt', in_array($customer->id, $activeIds, true));
            $customer->setAttribute('credit_status_label', in_array($customer->id, $activeIds, true)
                ? 'Crédito activo'
                : 'Sin crédito activo');
        });

        return [
            'customers' => $customers,
            'search' => $search,
        ];
    }

    private function assignedCustomersQuery(User $collector, Company $company, ?string $search, bool $onlyWithDebt): Builder
    {
        $routeId = $this->activeRouteId();

        return Customer::query()
            ->where('customers.company_id', $company->id)
            ->where('customers.is_active', true)
            ->whereHas('cobrosAssignments', fn (Builder $q) => $q
                ->forCompany($company->id)
                ->where('collector_user_id', $collector->id)
                // Con ruta activa: sus assignments + legados sin ruta (sin backfill).
                // Nunca clientes de OTRA ruta.
                ->when($routeId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('cobros_route_id', $routeId)
                    ->orWhereNull('cobros_route_id'))))
            // Solo deuda activa (comparación decimal exacta en SQL, sin floats).
            ->when($onlyWithDebt, fn (Builder $q) => $q->whereHas('accountsReceivable', fn (Builder $a) => $a
                ->forCompany($company->id)
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])))
            ->when($search !== null && trim($search) !== '', fn (Builder $q) => \App\Support\Cobros\CustomerSearch::apply($q, $search))
            ->orderBy('name')->orderBy('id');
    }

    private function totalBalance(User $collector, Company $company, ?string $search): string
    {
        $customerIds = $this->assignedCustomersQuery($collector, $company, $search, true)->pluck('customers.id');
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
        $routeId = $this->activeRouteId();

        return CobrosAssignment::query()->forCompany($company->id)
            ->where('collector_user_id', $collector->id)
            ->where('customer_id', $customer->id)
            ->when($routeId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('cobros_route_id', $routeId)
                ->orWhereNull('cobros_route_id')))
            ->exists();
    }

    /** Ruta activa de la sesión (ya validada por el middleware/selector). */
    private function activeRouteId(): ?int
    {
        $routeId = (int) session('active_cobros_route_id', 0);

        return $routeId > 0 ? $routeId : null;
    }

    /** Fase 2C — guardar ubicación del cliente desde el cobrador. NO valida location_validated_*. */
    public function saveLocation(User $collector, Company $company, Customer $customer, array $data): void
    {
        abort_unless(
            (int) $customer->company_id === (int) $company->id
            && $this->assignmentExists($collector, $company, $customer),
            404
        );

        $customer->update([
            'latitude' => (string) $data['latitude'],
            'longitude' => (string) $data['longitude'],
            'location_reference' => ($data['location_reference'] ?? null) !== null && trim((string) $data['location_reference']) !== ''
                ? trim((string) $data['location_reference'])
                : $customer->location_reference,
        ]);
    }

    /**
     * Fase 6B — teléfono (cliente o contacto indicado) capturado por el cobrador
     * desde el recibo. Reutiliza el campo telefónico existente de Customer:
     * no crea modelos nuevos ni datos adicionales. Misma empresa + cartera
     * asignada, o 404 (nunca revela clientes ajenos).
     */
    public function savePhone(User $collector, Company $company, Customer $customer, string $phone): void
    {
        abort_unless(
            (int) $customer->company_id === (int) $company->id
            && $this->assignmentExists($collector, $company, $customer),
            404
        );

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '' || strlen($digits) > 15) {
            throw ValidationException::withMessages(['phone' => 'Ingrese un teléfono válido (hasta 15 dígitos).']);
        }

        $countryCode = $customer->phone_country_code ?: $company->default_phone_country_code;
        $countryDigits = $countryCode !== null ? (preg_replace('/\D/', '', $countryCode) ?? '') : '';
        // Número ya internacional con prefijo del país conocido: se guarda local.
        if ($countryDigits !== '' && strlen($digits) > strlen($countryDigits) && str_starts_with($digits, $countryDigits)) {
            $digits = substr($digits, strlen($countryDigits));
        }
        if ($countryDigits === '' && strlen($digits) < 10) {
            throw ValidationException::withMessages(['phone' => 'Ingrese el teléfono con prefijo de país (ej. +506 8888 8888).']);
        }

        $customer->update(['phone' => $digits]);
    }
}
