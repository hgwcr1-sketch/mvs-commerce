<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\AccountReceivable;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Services\Cobros\CobrosPaymentGate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Fase 3A — Pantalla previa de cobro.
 *
 * La autoridad vive SOLO en la sesión (empresa activa) y auth()->id().
 * El Gate (CobrosPaymentGate) revalida TODO en servidor: URL, query string,
 * campos ocultos y frontend NO conceden nada. Esta pantalla NO registra pagos,
 * NO cambia saldos, NO genera recibos y NO toca caja (eso llega en 3B).
 */
class CollectorPaymentController extends Controller
{
    public function create(Request $request, AccountReceivable $account, CobrosPaymentGate $gate): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        // 404 seguro ante recursos ajenos/cross-company; 403 ante el resto de denegaciones.
        $gate->assertCanStart($request->user(), $company, $account->load(['customer', 'branch', 'sale']));

        if ($request->has('visit')) {
            abort_unless(is_scalar($request->query('visit')) && ctype_digit((string) $request->query('visit')), 404);
            $visit = CobrosVisit::query()->forCompany($company->id)
                ->forCollector($request->user()->id)
                ->where('customer_id', $account->customer_id)
                ->findOrFail($request->query('visit'));
            abort_if($visit->account_receivable_id !== null && (int) $visit->account_receivable_id !== (int) $account->id, 404);
        }

        return view('cobros.collector.payment-create', [
            'company' => $company,
            'account' => $account,
            'customer' => $account->customer,
        ]);
    }
}
