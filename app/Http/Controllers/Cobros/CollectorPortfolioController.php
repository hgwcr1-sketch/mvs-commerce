<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Cobros\CobrosPaymentGate;
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
    public function index(Request $request, CollectorPortfolioService $service, CollectorAccessService $access, CobrosPaymentGate $gate): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $search = $request->string('q')->trim()->toString() ?: null;
        $portfolio = $service->portfolio($request->user(), $company, $search);
        $customerIds = $portfolio['customers']->getCollection()->modelKeys();

        return view('cobros.collector.portfolio', [
            'company' => $company,
            'customers' => $portfolio['customers'],
            'totalBalance' => $portfolio['total_balance'],
            'search' => $search ?? '',
            // Contrato conservado del shell: sucursales operativas del cobrador en la empresa activa.
            'branches' => $access->branchesForCollecting($request->user(), $company),
            // Fase 3A: el botón COBRAR solo existe si el Gate lo permite.
            'chargeableAccounts' => $gate->chargeableAccountIds($request->user(), $company, $customerIds),
        ]);
    }

    public function show(Request $request, Customer $customer, CollectorPortfolioService $service, CobrosPaymentGate $gate): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $service->customerPortfolio($request->user(), $company, $customer);

        return view('cobros.collector.customer', [
            'company' => $company,
            'customer' => $data['customer'],
            'openAccounts' => $data['openAccounts'],
            'totalBalance' => $data['total_balance'],
            'payments' => $data['payments'],
            'chargeableAccounts' => $gate->chargeableAccountIds($request->user(), $company, [$customer->id]),
        ]);
    }

    /** Fase 2C — guardar ubicación actual del cliente en su cartera. */
    public function storeLocation(Request $request, Customer $customer, CollectorPortfolioService $service): \Illuminate\Http\RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_reference' => ['nullable', 'string', 'max:500'],
        ]);
        $service->saveLocation($request->user(), $company, $customer, $validated);

        return back()->with('success', 'Ubicación guardada correctamente.');
    }
}
