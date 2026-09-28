<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountPayablePaymentRequest;
use App\Models\AccountPayable;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Services\Cash\CashSessionResolver;
use App\Services\Purchases\AccountsPayableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountsPayableController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status'=>['nullable','in:pending,partial,paid,overdue,cancelled'], 'supplier_id'=>['nullable','integer'], 'search'=>['nullable','string','max:150'],
            'issue_from'=>['nullable','date'], 'issue_to'=>['nullable','date','after_or_equal:issue_from'], 'due_from'=>['nullable','date'], 'due_to'=>['nullable','date','after_or_equal:due_from'],
        ]);
        $companyId=(int)session('active_company_id'); $branchId=(int)session('active_branch_id');
        $accounts=AccountPayable::query()->forCompany($companyId)->forBranch($branchId)->with(['supplier:id,name','purchase:id,number,supplier_invoice_number'])
            ->when($filters['supplier_id']??null,fn($q,$v)=>$q->where('supplier_id',$v))
            ->when($filters['status']??null,fn($q,$v)=>$v==='overdue'?$q->whereNotIn('status',['paid','cancelled'])->where('balance_due','>',0)->whereDate('due_date','<',today()):$q->where('status',$v))
            ->when($filters['issue_from']??null,fn($q,$v)=>$q->whereDate('issue_date','>=',$v))->when($filters['issue_to']??null,fn($q,$v)=>$q->whereDate('issue_date','<=',$v))
            ->when($filters['due_from']??null,fn($q,$v)=>$q->whereDate('due_date','>=',$v))->when($filters['due_to']??null,fn($q,$v)=>$q->whereDate('due_date','<=',$v))
            ->when($filters['search']??null,function($q,$v){$q->where(function($search)use($v){$search->whereHas('supplier',fn($s)=>$s->where('name','like',"%{$v}%"))->orWhereHas('purchase',fn($p)=>$p->where('number','like',"%{$v}%")->orWhere('supplier_invoice_number','like',"%{$v}%"));});})
            ->latest('issue_date')->paginate(20)->withQueryString();
        $suppliers=Supplier::query()->where('company_id',$companyId)->whereHas('accountsPayable',fn($q)=>$q->forBranch($branchId))->orderBy('name')->get(['id','name']);
        return view('accounts-payable.index',compact('accounts','suppliers','filters'));
    }

    public function show(Request $request, AccountPayable $accountPayable, CashSessionResolver $resolver): View
    {
        $account=$this->scoped($accountPayable)->load(['supplier','purchase','payments.paymentMethod','payments.cashSession.cashRegister','payments.user']);
        $methods=PaymentMethod::forCompany($account->company_id)->active()->whereNotIn('type',[PaymentMethod::TYPE_CREDIT,PaymentMethod::TYPE_LOYALTY_POINTS])->ordered()->get();
        $sessions=$resolver->applicable($request->user(),$account->company_id,$account->branch_id);
        return view('accounts-payable.show',compact('account','methods','sessions'));
    }

    public function payment(StoreAccountPayablePaymentRequest $request, AccountPayable $accountPayable, AccountsPayableService $service): RedirectResponse
    {
        $service->pay($this->scoped($accountPayable),$request->validated(),$request->user(),(int)session('active_company_id'),(int)session('active_branch_id'));
        return back()->with('success','Abono registrado correctamente.');
    }

    public function updateAlertDays(Request $request): RedirectResponse
    {
        $company=$request->user()->companies()->findOrFail((int)session('active_company_id'));
        abort_unless($request->user()->hasPermission('cuentas_pagar.editar',$company),403);
        $data=$request->validate(['payable_alert_days'=>['required','integer','in:1,3,5,7,15']]);
        $company->update($data);
        return back()->with('success','Configuración de alertas CxP actualizada.');
    }

    private function scoped(AccountPayable $account): AccountPayable
    {
        abort_unless($account->company_id===(int)session('active_company_id')&&$account->branch_id===(int)session('active_branch_id'),404);
        return $account;
    }
}
