<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Cobros\CollectorAccessService;
use App\Services\Cobros\CollectorPortfolioService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Fase 2A — Mi Cartera.
 * La autoridad del cobrador vive SOLO en la sesión (empresa activa) y auth()->id().
 * Nunca se acepta collector_id desde el request (evita IDOR).
 */
class CollectorPortfolioController extends Controller
{
    public function index(Request $request, CollectorPortfolioService $service, CollectorAccessService $access): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $search = $request->string('q')->trim()->toString() ?: null;
        $portfolio = $service->portfolio($request->user(), $company, $search);

        return view('cobros.collector.portfolio', [
            'company' => $company,
            'customers' => $portfolio['customers'],
            'totalBalance' => $portfolio['total_balance'],
            'search' => $search ?? '',
            // Contrato conservado del shell: sucursales operativas del cobrador en la empresa activa.
            'branches' => $access->branchesForCollecting($request->user(), $company),
        ]);
    }

    public function show(Request $request, Customer $customer, CollectorPortfolioService $service): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $service->customerPortfolio($request->user(), $company, $customer);

        return view('cobros.collector.customer', [
            'company' => $company,
            'customer' => $data['customer'],
            'openAccounts' => $data['openAccounts'],
            'totalBalance' => $data['total_balance'],
            'payments' => $data['payments'],
        ]);
    }
}
