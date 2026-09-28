<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountReceivablePaymentRequest;
use App\Models\AccountReceivable;
use App\Models\PaymentMethod;
use App\Services\Cash\CashSessionResolver;
use App\Services\Sales\AccountsReceivableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountsReceivableController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['customer' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'in:pending,partial,paid,overdue,cancelled'], 'due' => ['nullable', 'in:overdue,upcoming'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $companyId = (int) session('active_company_id'); $branchId = (int) session('active_branch_id');
        $alertDays = (int) (auth()->user()->companies()->findOrFail($companyId)->credit_alert_days ?? 5);
        $accounts = AccountReceivable::query()->forCompany($companyId)->forBranch($branchId)->with(['customer:id,name', 'sale:id,sale_number'])
            ->when($filters['customer'] ?? null, fn ($q, $v) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'overdue' ? $q->whereNotIn('status', ['paid','cancelled'])->where('balance_due','>',0)->whereDate('due_date','<',today()) : $q->where('status',$v))
            ->when(($filters['due'] ?? null) === 'overdue', fn ($q) => $q->whereNotIn('status',['paid','cancelled'])->where('balance_due','>',0)->whereDate('due_date','<',today()))
            ->when(($filters['due'] ?? null) === 'upcoming', fn ($q) => $q->whereNotIn('status',['paid','cancelled'])->where('balance_due','>',0)->whereBetween('due_date',[today(),today()->addDays($alertDays)]))
            ->when($filters['from'] ?? null, fn ($q,$v) => $q->whereDate('issued_at','>=',$v))->when($filters['to'] ?? null, fn ($q,$v) => $q->whereDate('issued_at','<=',$v))
            ->latest('issued_at')->paginate(20)->withQueryString();
        return view('accounts-receivable.index', compact('accounts','filters','alertDays'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function show(Request $request, AccountReceivable $accountReceivable, CashSessionResolver $resolver): View
    {
        $account = $this->scoped($accountReceivable)->load(['customer','sale','payments.paymentMethod','payments.user']);
        $methods = PaymentMethod::forCompany($account->company_id)->active()->whereNotIn('type',[PaymentMethod::TYPE_CREDIT,PaymentMethod::TYPE_LOYALTY_POINTS])->ordered()->get();
        $sessions = $resolver->applicable($request->user(), $account->company_id, $account->branch_id);
        return view('accounts-receivable.show', compact('account','methods','sessions'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function payment(StoreAccountReceivablePaymentRequest $request, AccountReceivable $accountReceivable, AccountsReceivableService $service): RedirectResponse
    {
        $account = $this->scoped($accountReceivable);
        $service->pay($account, $request->validated(), $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));
        return back()->with('success', 'Abono registrado correctamente.');
    }

    public function updateAlertDays(Request $request): RedirectResponse
    {
        $company = $request->user()->companies()->findOrFail((int) session('active_company_id'));
        abort_unless($request->user()->hasPermission('cuentas_cobrar.editar', $company), 403);
        $data = $request->validate(['credit_alert_days' => ['required', 'integer', 'in:1,3,5,7,15']]);
        $company->update($data);
        return back()->with('success', 'Alerta de créditos actualizada.');
    }

    /**
     * Display the specified resource.
     */
    private function scoped(AccountReceivable $account): AccountReceivable
    {
        abort_unless($account->company_id === (int) session('active_company_id') && $account->branch_id === (int) session('active_branch_id'), 404);
        return $account;
    }
}
