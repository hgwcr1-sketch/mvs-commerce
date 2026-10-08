<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCobrosCustomerRequest;
use App\Http\Requests\UpdateCobrosCustomerRequest;
use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6B — módulo Clientes del dueño/administrador dentro de /cobros/*.
 *
 * Alcance: listar y buscar clientes de la empresa activa con su saldo abierto
 * y cobrador, ver ficha con CxC abiertas, historial de pagos y recibos, y
 * crear/editar clientes.
 *
 * Reglas aplicadas:
 *  - todo consulta se acota por company_id de la sesión activa (404 si el
 *    cliente pertenece a otra empresa);
 *  - los saldos se acumulan con BCMath a 4 decimales (sin floats, sin round);
 *  - CobrOS no escribe crédito / nivel de precio (ver StoreCobrosCustomerRequest);
 *  - los cobros de asignación siguen en OwnerAssignmentController.
 */
class OwnerCustomerController extends Controller
{
    public function index(Request $request): View
    {
        $company = $this->activeCompany();
        $search = $request->string('q')->trim()->toString() ?: null;

        $query = Customer::query()
            ->forCompany($company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id');

        if ($search !== null) {
            $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($operator, $search) {
                foreach (['name', 'commercial_name', 'identification', 'phone', 'mobile', 'email'] as $field) {
                    $q->orWhere($field, $operator, "%{$search}%");
                }
            });
        }

        $customers = $query->paginate(20)->withQueryString();
        $customerIds = $customers->pluck('id');

        $assignments = CobrosAssignment::query()
            ->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)
            ->get()
            ->keyBy('customer_id');

        $balances = [];
        $openAccounts = AccountReceivable::query()
            ->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->get(['id', 'customer_id', 'balance_due']);

        foreach ($openAccounts as $account) {
            $id = (int) $account->customer_id;
            $balances[$id] = bcadd($balances[$id] ?? '0.0000', (string) $account->balance_due, 4);
        }

        $collectors = User::query()
            ->whereIn('id', $assignments->pluck('collector_user_id'))
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = $customers->getCollection()->map(function (Customer $customer) use ($assignments, $balances, $collectors) {
            $assignment = $assignments->get($customer->id);

            return [
                'customer' => $customer,
                'balance' => $balances[$customer->id] ?? '0.0000',
                'collector' => $assignment ? ($collectors->get($assignment->collector_user_id) ?? null) : null,
            ];
        });

        return view('cobros.owner.customers', [
            'company' => $company,
            'rows' => $rows,
            'customers' => $customers,
            'search' => $search ?? '',
            'creditAssignmentEnabled' => $company->allow_collector_credit_on_customer_create,
        ]);
    }

    public function updateCollectorCreditSetting(Request $request): RedirectResponse
    {
        $company = $this->activeCompany();
        $validated = $request->validate([
            'allow_collector_credit_on_customer_create' => ['required', 'boolean'],
            'collector_customer_credit_limit_max' => [
                'required_if:allow_collector_credit_on_customer_create,1',
                'string',
                'regex:/^\d{1,13}(?:\.\d{1,2})?$/D',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->boolean('allow_collector_credit_on_customer_create')
                        && (! is_string($value)
                            || ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)
                            || bccomp($value, '0', 2) <= 0)) {
                        $fail('Indique un límite máximo de crédito mayor que cero.');
                    }
                },
            ],
        ]);
        $enabled = (bool) $validated['allow_collector_credit_on_customer_create'];
        $company->update([
            'allow_collector_credit_on_customer_create' => $enabled,
            'collector_customer_credit_limit_max' => $enabled
                ? bcadd($validated['collector_customer_credit_limit_max'], '0', 2)
                : $company->collector_customer_credit_limit_max,
        ]);

        return back()->with('success', 'La configuración de crédito para nuevos clientes fue actualizada.');
    }

    public function create(): View
    {
        return view('cobros.owner.customer-form', [
            'company' => $this->activeCompany(),
            'customer' => null,
        ]);
    }

    public function store(StoreCobrosCustomerRequest $request): RedirectResponse
    {
        $company = $this->activeCompany();

        $data = $request->validated();
        $data['company_id'] = $company->id;

        $customer = Customer::create($data);

        return redirect()
            ->route('cobros.owner.customers.show', $customer)
            ->with('success', "Cliente {$customer->name} creado correctamente.");
    }

    public function show(Request $request, Customer $customer): View
    {
        $company = $this->activeCompany();
        $customer = $this->resolveCustomer($company, $customer);

        $openAccounts = AccountReceivable::query()
            ->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->with(['branch', 'sale'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $totalBalance = '0.0000';
        foreach ($openAccounts as $account) {
            $totalBalance = bcadd($totalBalance, (string) $account->balance_due, 4);
        }

        $payments = AccountReceivablePayment::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->with(['paymentMethod', 'user'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $receipts = CobrosReceipt::query()
            ->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->whereNull('annulled_at')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $assignment = CobrosAssignment::query()
            ->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->first();

        $collector = $assignment
            ? User::query()->whereKey($assignment->collector_user_id)->first(['id', 'name', 'email'])
            : null;

        return view('cobros.owner.customer', [
            'company' => $company,
            'customer' => $customer,
            'openAccounts' => $openAccounts,
            'totalBalance' => $totalBalance,
            'payments' => $payments,
            'receipts' => $receipts,
            'collector' => $collector,
            'expediente' => \App\Models\CobrosCreditExpediente::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->with(['references', 'documents'])->first(),
            'creditRequests' => \App\Models\CobrosCreditRequest::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->orderByDesc('id')->limit(20)->get(),
            'credits' => \App\Models\CobrosCredit::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        $company = $this->activeCompany();

        return view('cobros.owner.customer-form', [
            'company' => $company,
            'customer' => $this->resolveCustomer($company, $customer),
        ]);
    }

    public function update(UpdateCobrosCustomerRequest $request, Customer $cliente): RedirectResponse
    {
        $company = $this->activeCompany();
        $cliente = $this->resolveCustomer($company, $cliente);
        $cliente->update($request->validated());

        return redirect()
            ->route('cobros.owner.customers.show', $cliente)
            ->with('success', "Cliente {$cliente->name} actualizado correctamente.");
    }

    private function activeCompany(): Company
    {
        return Company::query()->findOrFail((int) session('active_company_id'));
    }

    /** 404 ante cliente de otra empresa: nunca revelar existencia ajena. */
    private function resolveCustomer(Company $company, Customer $customer): Customer
    {
        return Customer::query()
            ->forCompany($company->id)
            ->findOrFail($customer->id);
    }
}
