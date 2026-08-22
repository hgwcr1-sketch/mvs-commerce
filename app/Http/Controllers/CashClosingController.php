<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthorizeCashDifferenceRequest;
use App\Http\Requests\StartCashClosingRequest;
use App\Http\Requests\SubmitCashClosingRequest;
use App\Models\Branch;
use App\Models\CashDenomination;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Services\Cash\CashClosingService;
use App\Services\Cash\CashExpectedAmountService;
use App\Services\Cash\CashPaymentExpectedAmountService;
use App\Services\CashDenominationProvisioner;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashClosingController extends Controller
{
    public function start(StartCashClosingRequest $request, CashSession $cashSession, CashClosingService $service): RedirectResponse
    {
        [$companyId, $branchId] = $this->ids();
        $service->start($request->user(), $companyId, $branchId, $cashSession->id, $request->validated('request_token'));
        return redirect()->route('cash.closing.create', $cashSession);
    }

    public function create(Request $request, CashSession $cashSession, CashExpectedAmountService $cashExpected, CashPaymentExpectedAmountService $paymentExpected, CashDenominationProvisioner $denominationProvisioner): View
    {
        [$company, $settings] = $this->context($request, $cashSession); $this->authorizeContinuation($request, $cashSession, $settings);
        abort_unless($cashSession->status === CashSession::STATUS_CLOSING && $cashSession->closing_submitted_at === null, 409);
        $denominationProvisioner->provision($company);
        $denominations = CashDenomination::forCompany($company->id)->forCurrency('CRC')->active()->orderBy('sort_order')->get();
        $methods = $paymentExpected->methods($cashSession);
        $expectedBreakdown = $paymentExpected->breakdown($cashSession);
        $blind = (bool) $cashSession->blind_closing_snapshot;
        return view('cash.closing.create', [
            'cashSession' => $cashSession->loadMissing('cashRegister:id,name'), 'denominations' => $denominations, 'methods' => $methods,
            'blind' => $blind, 'expectedCash' => $blind ? null : $cashExpected->calculate($cashSession),
            'expectedMethods' => $paymentExpected->expectedAmounts($cashSession), 'expectedBreakdown' => $expectedBreakdown, 'requestToken' => (string) Str::uuid(),
        ]);
    }

    public function submit(SubmitCashClosingRequest $request, CashSession $cashSession, CashClosingService $service): RedirectResponse
    {
        [$companyId, $branchId] = $this->ids();
        $service->submit($request->user(), $companyId, $branchId, $cashSession->id, $request->validated());
        return redirect()->route('cash.closing.show', $cashSession)->with('success', 'Cierre enviado correctamente');
    }

    public function cancel(Request $request, CashSession $cashSession, CashClosingService $service): RedirectResponse
    {
        [$company] = $this->context($request, $cashSession); abort_unless($request->user()->hasPermission('caja.cerrar', $company), 403);
        [$companyId, $branchId] = $this->ids();
        $service->cancel($request->user(), $companyId, $branchId, $cashSession->id);
        return redirect()->route('cash.index')->with('info', 'El cierre fue cancelado y la sesión volvió a estar abierta.');
    }

    public function show(Request $request, CashSession $cashSession): View
    {
        [$company] = $this->context($request, $cashSession);
        $admin = $request->user()->hasPermission('caja.administrar', $company) || $request->user()->hasPermission('caja.ver_todas', $company);
        $related = $cashSession->opened_by === $request->user()->id || $cashSession->closing_started_by === $request->user()->id;
        abort_unless($admin || $related || $request->user()->hasPermission('caja.ver', $company), 403);
        if ($admin) $cashSession->load(['cashRegister:id,name', 'openedBy:id,name', 'closingStartedBy:id,name', 'closedBy:id,name', 'differenceAuthorizedBy:id,name', 'countDetails.cashDenomination', 'countDetails.countedBy:id,name', 'paymentReconciliations.reconciledBy:id,name']);
        return view('cash.closing.show', ['cashSession' => $cashSession, 'detailed' => $admin, 'companyTimezone' => $this->companyTimezone($company)]);
    }

    public function authorizeForm(Request $request, CashSession $cashSession): View
    {
        [$company] = $this->context($request, $cashSession); abort_unless($request->user()->hasPermission('caja.autorizar_diferencia', $company), 403);
        abort_unless($cashSession->status === CashSession::STATUS_CLOSING && $cashSession->closing_submitted_at !== null, 409);
        $cashSession->load(['cashRegister:id,name', 'countDetails.cashDenomination', 'paymentReconciliations']);
        return view('cash.closing.authorize', compact('cashSession'));
    }

    public function authorize(AuthorizeCashDifferenceRequest $request, CashSession $cashSession, CashClosingService $service): RedirectResponse
    {
        [$companyId, $branchId] = $this->ids();
        $service->authorize($request->user(), $companyId, $branchId, $cashSession->id);
        return redirect()->route('cash.closing.show', $cashSession)->with('success', 'Diferencia autorizada y cierre completado.');
    }

    private function ids(): array { return [(int) session('active_company_id'), (int) session('active_branch_id')]; }

    private function context(Request $request, CashSession $cashSession): array
    {
        [$companyId, $branchId] = $this->ids(); $company = Company::query()->where('is_active', true)->findOrFail($companyId);
        abort_unless(Branch::query()->whereKey($branchId)->where('company_id', $companyId)->where('is_active', true)->exists() && $request->user()->companies()->whereKey($companyId)->exists() && $request->user()->branches()->whereKey($branchId)->exists(), 403);
        abort_unless($cashSession->company_id === $companyId && $cashSession->branch_id === $branchId && $cashSession->cashRegister()->where('is_active', true)->exists(), 404);
        return [$company, CompanyCashSetting::query()->where('company_id', $companyId)->firstOrFail()];
    }

    private function authorizeContinuation(Request $request, CashSession $session, CompanyCashSetting $settings): void
    {
        abort_unless($request->user()->hasPermission('caja.cerrar', $session->company), 403);
        if ($settings->session_mode === CompanyCashSetting::SESSION_MODE_INDIVIDUAL) abort_unless($session->opened_by === $request->user()->id, 403);
        else abort_unless($session->closing_started_by === $request->user()->id || $request->user()->hasPermission('caja.administrar', $session->company), 403);
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = trim((string) $company->timezone);
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : config('app.timezone');
    }
}
