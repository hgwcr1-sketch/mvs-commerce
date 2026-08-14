<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCashMovementRequest;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Services\Cash\CashExpectedAmountService;
use App\Services\Cash\CashMovementService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashMovementController extends Controller
{
    public function index(Request $request, CashSession $cashSession, CashExpectedAmountService $calculator): View
    {
        [$company, $settings] = $this->context($request, $cashSession);
        abort_unless($request->user()->hasPermission('caja.ver', $company), 403);
        abort_if(
            $cashSession->opened_by !== $request->user()->id
            && ! $request->user()->hasPermission('caja.ver_todas', $company),
            403,
        );
        $this->ensureOpenSession($cashSession);

        $movements = $cashSession->movements()
            ->with('createdBy:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return view('cash.movements.index', [
            'cashSession' => $cashSession->loadMissing('cashRegister:id,name'),
            'movements' => $movements,
            'expectedCash' => $calculator->calculate($cashSession),
            'companyTimezone' => $this->companyTimezone($company),
            'canCreate' => $request->user()->hasPermission('caja.movimientos', $company)
                && ($settings->session_mode === CompanyCashSetting::SESSION_MODE_SHARED
                    || $cashSession->opened_by === $request->user()->id),
        ]);
    }

    public function create(Request $request, CashSession $cashSession, CashExpectedAmountService $calculator): View
    {
        [$company] = $this->context($request, $cashSession);
        abort_unless($request->user()->hasPermission('caja.movimientos', $company), 403);
        $this->ensureOperableBy($request, $cashSession);

        $selectedType = in_array($request->query('type'), [
            CashMovement::TYPE_ENTRY,
            CashMovement::TYPE_EXIT,
            CashMovement::TYPE_WITHDRAWAL,
        ], true) ? $request->query('type') : CashMovement::TYPE_ENTRY;

        return view('cash.movements.create', [
            'cashSession' => $cashSession->loadMissing('cashRegister:id,name'),
            'expectedCash' => $calculator->calculate($cashSession),
            'selectedType' => $selectedType,
            'requestToken' => (string) Str::uuid(),
        ]);
    }

    public function store(
        StoreCashMovementRequest $request,
        CashSession $cashSession,
        CashMovementService $service,
    ): RedirectResponse {
        $result = $service->create(
            $request->validated(),
            $request->user(),
            (int) session('active_company_id'),
            (int) session('active_branch_id'),
            $cashSession->id,
        );

        $message = $result['duplicate']
            ? 'Este movimiento ya había sido registrado.'
            : 'Movimiento de caja registrado correctamente.';

        $company = Company::findOrFail((int) session('active_company_id'));
        if ($request->user()->hasPermission('caja.ver', $company)) {
            return redirect()->route('cash.movements.index', $cashSession)->with('success', $message);
        }

        return redirect()->route('cash.movements.create', $cashSession)->with('success', $message);
    }

    /** @return array{Company, CompanyCashSetting} */
    private function context(Request $request, CashSession $cashSession): array
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $company = Company::query()->where('is_active', true)->findOrFail($companyId);
        $branchExists = Branch::query()->whereKey($branchId)->where('company_id', $companyId)->where('is_active', true)->exists();

        abort_unless(
            $branchExists
            && $request->user()->companies()->whereKey($companyId)->exists()
            && $request->user()->branches()->whereKey($branchId)->exists(),
            403,
        );
        abort_unless(
            $cashSession->company_id === $companyId && $cashSession->branch_id === $branchId,
            404,
        );

        return [$company, CompanyCashSetting::query()->where('company_id', $companyId)->firstOrFail()];
    }

    private function ensureOperableBy(Request $request, CashSession $cashSession): void
    {
        [, $settings] = $this->context($request, $cashSession);
        $this->ensureOpenSession($cashSession);

        abort_if(
            $settings->session_mode === CompanyCashSetting::SESSION_MODE_INDIVIDUAL
            && $cashSession->opened_by !== $request->user()->id,
            403,
        );
    }

    private function ensureOpenSession(CashSession $cashSession): void
    {
        abort_unless(
            $cashSession->status === CashSession::STATUS_OPEN
            && $cashSession->open_guard === CashSession::OPEN_GUARD
            && $cashSession->cashRegister()
                ->where('company_id', $cashSession->company_id)
                ->where('branch_id', $cashSession->branch_id)
                ->where('is_active', true)
                ->exists(),
            409,
        );
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = trim((string) $company->timezone);

        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : config('app.timezone');
    }
}
