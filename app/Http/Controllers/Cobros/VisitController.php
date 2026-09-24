<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CobrosVisit;
use App\Models\Customer;
use App\Services\Cobros\VisitService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fase 2B — Agenda diaria y gestión de visitas.
 * La autoridad vive SOLO en la sesión (empresa activa) y auth()->id().
 * collector_id del request JAMÁS se lee (evita IDOR). Cliente/visita ajena,
 * sin asignar o cross-company → 404. Solo lectura para supervisores.
 */
class VisitController extends Controller
{
    public function today(Request $request, VisitService $service): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        return view('cobros.visits.today', [
            'company' => $company,
            'visits' => $service->today($request->user(), $company),
            'branches' => $request->user()->branches()->where('branches.company_id', $company->id)->get(),
        ]);
    }

    public function customerHistory(Request $request, Customer $customer, VisitService $service): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        return view('cobros.visits.history', [
            'company' => $company,
            'customer' => $customer,
            'visits' => $service->historyFor($request->user(), $company, $customer),
        ]);
    }

    public function store(Request $request, Customer $customer, VisitService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $validated = $this->validatePayload($request);
        $service->schedule($request->user(), $company, $customer, $validated);

        return back()->with('success', 'Visita programada correctamente.');
    }

    public function update(Request $request, CobrosVisit $visit, VisitService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $validated = $this->validatePayload($request, forUpdate: true);
        $service->record($request->user(), $company, $visit, $validated);

        return back()->with('success', 'Gestión registrada correctamente.');
    }

    private function validatePayload(Request $request, bool $forUpdate = false): array
    {
        // Programar (store) SOLO fecha + observación; estado/promesa solo existen en gestión (update).
        $rules = $forUpdate
            ? [
                'scheduled_for' => ['nullable', 'date'],
                'status' => ['nullable', 'string', 'in:pendiente,visitado,no_localizado,promesa_pago,reprogramada'],
                'promise_date' => ['nullable', 'date', 'required_if:status,promesa_pago'],
                'promise_amount' => ['nullable', 'numeric', 'min:0'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]
            : [
                'scheduled_for' => ['required', 'date'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ];

        // collector_id del request se IGNORA por diseño (autoridad = auth()->id()).
        return $request->validate($rules);
    }
}
