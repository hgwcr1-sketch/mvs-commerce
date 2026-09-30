<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Services\Cobros\OwnerReportService;
use App\Services\Cobros\PortfolioSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OwnerDashboardController extends Controller
{
    public function index(Request $request, PortfolioSummaryService $portfolio): View
    {
        $filters = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $summary = $portfolio->summarize($company, $branchId);
        $branches = Branch::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']);

        return view('cobros.owner.dashboard', [...$summary, ...compact('company', 'branches', 'branchId')]);
    }

    /**
     * Fase 4B — panel avanzado del dueño: período + sucursal, ranking de
     * cobradores, antigüedad, custodia pendiente, recibos, anulaciones y
     * auditoría. Solo lectura; reutiliza PortfolioSummaryService y
     * CollectorManagementService sin escribir operaciones financieras.
     */
    public function reports(Request $request, OwnerReportService $reports): View
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $timezone = $company->timezone ?: config('app.timezone');
        $today = CarbonImmutable::today($timezone);

        $from = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'], $timezone)
            : $today->startOfMonth();
        $to = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'], $timezone)
            : $today;

        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $data = $reports->report($company, $branchId, $from, $to);
        $branches = Branch::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']);

        return view('cobros.owner.reports', [
            'company' => $company,
            'branches' => $branches,
            'branchId' => $branchId,
            ...$data,
        ]);
    }
}
