<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexCashSessionHistoryRequest;
use App\Models\CashSession;
use App\Models\CashSessionMailNotification;
use App\Models\Company;
use App\Services\Cash\CashSessionHistoryService;
use App\Services\Cash\CashSessionMailRetryService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashSessionHistoryController extends Controller
{
    public function index(IndexCashSessionHistoryRequest $request, CashSessionHistoryService $service): View
    {
        [$company, $companyId, $branchId] = $this->context($request);
        $viewAll = $request->user()->hasPermission('caja.ver_todas', $company);
        $filters = $request->validated();
        $timezone = $this->companyTimezone($company);

        return view('cash.history.index', array_merge([
            'sessions' => $service->paginate($companyId, $branchId, $viewAll, $filters, $timezone),
            'filters' => $filters,
            'canViewAll' => $viewAll,
            'canAdminister' => $request->user()->hasPermission('caja.administrar', $company),
            'companyTimezone' => $timezone,
        ], $service->filterOptions($companyId, $branchId, $viewAll)));
    }

    public function show(Request $request, CashSession $cashSession, CashSessionHistoryService $service): View
    {
        [$company, $companyId, $branchId] = $this->context($request);
        $this->authorizeSessionScope($request, $cashSession, $company, $companyId, $branchId);
        $sensitive = $request->user()->hasPermission('caja.administrar', $company);

        return view('cash.history.show', [
            'cashSession' => $service->loadDetail($cashSession, $sensitive),
            'sensitive' => $sensitive,
            'companyTimezone' => $this->companyTimezone($company),
        ]);
    }

    public function retry(Request $request, CashSession $cashSession, CashSessionMailNotification $notification, CashSessionMailRetryService $service): RedirectResponse
    {
        [$company, $companyId, $branchId] = $this->context($request);
        abort_unless($request->user()->hasPermission('caja.administrar', $company), 403);
        $this->authorizeSessionScope($request, $cashSession, $company, $companyId, $branchId);
        $service->retry($companyId, $cashSession->id, $notification->id, $request->user());

        return back()->with('success', 'La notificación fue preparada para reintento.');
    }

    private function context(Request $request): array
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $company = Company::query()->where('is_active', true)->findOrFail($companyId);
        abort_unless($request->user()->companies()->whereKey($companyId)->exists() && $request->user()->branches()->whereKey($branchId)->exists(), 403);
        abort_unless($request->user()->hasPermission('caja.ver', $company), 403);
        return [$company, $companyId, $branchId];
    }

    private function authorizeSessionScope(Request $request, CashSession $session, Company $company, int $companyId, int $branchId): void
    {
        abort_unless($session->company_id === $companyId, 404);
        if (! $request->user()->hasPermission('caja.ver_todas', $company)) abort_unless($session->branch_id === $branchId, 404);
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = trim((string) $company->timezone);
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : config('app.timezone');
    }
}
