<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenCashSessionRequest;
use App\Models\{CashRegister,CashSession,Company,CompanyCashSetting};
use App\Services\Cash\CashSessionService;
use DateTimeZone;
use Illuminate\Http\{RedirectResponse,Request};
use Illuminate\View\View;

class CashSessionController extends Controller
{
    public function required(Request $request): View|RedirectResponse
    {
        [$company, $companyId, $branchId] = $this->context();
        if (! $request->user()->hasPermission('pos.acceder', $company)) {
            abort(403);
        }

        if (app(\App\Services\Cash\CashSessionResolver::class)->applicable($request->user(), $companyId, $branchId)->isNotEmpty()) {
            return redirect()->route('pos.index');
        }

        return view('cash.required', [
            'company' => $company,
            'branch' => \App\Models\Branch::findOrFail($branchId),
            'canOpenCash' => $request->user()->hasPermission('caja.abrir', $company),
        ]);
    }
    public function index(Request $request): View
    {
        [$company,$companyId,$branchId,$settings]=$this->context();
        abort_unless($request->user()->hasPermission('caja.ver',$company) || $request->user()->hasPermission('caja.abrir',$company),403);
        $canView=$request->user()->hasPermission('caja.ver',$company); $canViewAll=$request->user()->hasPermission('caja.ver_todas',$company);
        $base=CashSession::forCompany($companyId)->forBranch($branchId);
        $openSessions=(clone $base)->whereIn('status',[CashSession::STATUS_OPEN,CashSession::STATUS_CLOSING])
            ->when($settings->session_mode===CompanyCashSetting::SESSION_MODE_INDIVIDUAL || !$canView,fn($q)=>$q->where('opened_by',$request->user()->id))
            ->with(['cashRegister:id,name','branch:id,name','openedBy:id,name','exchangeRateEnteredBy:id,name'])->get();
        $history=(clone $base)->when(!$canViewAll,fn($q)=>$q->where('opened_by',$request->user()->id))->with(['cashRegister:id,name','openedBy:id,name'])->latest('opened_at')->limit(20)->get();
        $defaultRegister=CashRegister::forCompany($companyId)->forBranch($branchId)->active()->orderByDesc('is_default')->orderBy('name')->first();
        $companyTimezone = $this->companyTimezone($company);
        return view('cash.index',compact('openSessions','history','defaultRegister','settings','companyTimezone'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        [$company,$companyId,$branchId,$settings]=$this->context();
        if($settings->session_mode===CompanyCashSetting::SESSION_MODE_INDIVIDUAL && CashSession::forCompany($companyId)->forBranch($branchId)->where('opened_by',$request->user()->id)->whereIn('status',[CashSession::STATUS_OPEN,CashSession::STATUS_CLOSING])->exists()) return redirect()->route('cash.index')->with('info','Ya tiene una sesión de caja abierta en esta sucursal.');
        $registers=CashRegister::forCompany($companyId)->forBranch($branchId)->active()->orderByDesc('is_default')->orderBy('name')->get();
        $firstRegister = $registers->first();
        $selectedRegisterId = ($registers->count() === 1 || $firstRegister?->is_default)
            ? $firstRegister?->id
            : null;
        return view('cash.open',['registers'=>$registers,'selectedRegisterId'=>$selectedRegisterId,'settings'=>$settings,'cashier'=>$request->user(),'canManageRegisters'=>$request->user()->hasPermission('caja.administrar',$company)]);
    }

    public function store(OpenCashSessionRequest $request,CashSessionService $service): RedirectResponse
    {
        $session=$service->open($request->validated(),$request->user(),(int)session('active_company_id'),(int)session('active_branch_id'));
        if ($request->session()->pull('cash_open_return_to_pos', false)) {
            return redirect()->route('pos.index')->with('success', "Caja {$session->session_number} abierta. Ya puede operar el POS.");
        }        return redirect()->route('cash.index')->with('success',"Caja {$session->session_number} abierta correctamente.");
    }

    private function context(): array
    {
        $companyId=(int)session('active_company_id'); $branchId=(int)session('active_branch_id');
        $company=Company::findOrFail($companyId); $settings=CompanyCashSetting::where('company_id',$companyId)->firstOrFail();
        return [$company,$companyId,$branchId,$settings];
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = trim((string) $company->timezone);

        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : config('app.timezone');
    }
}
