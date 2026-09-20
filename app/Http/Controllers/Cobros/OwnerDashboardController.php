<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Services\Cobros\PortfolioSummaryService;
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
}
