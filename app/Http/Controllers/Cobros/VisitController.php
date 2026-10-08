<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CobrosVisit;
use App\Models\Customer;
use App\Services\Cobros\CobrosPaymentGate;
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
    public function today(Request $request, VisitService $service, CobrosPaymentGate $gate): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $agendaService = app(\App\Services\Cobros\AgendaService::class);

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, \App\Services\Cobros\AgendaService::TABS, true)) {
            $tab = 'hoy';
        }
        $agenda = $agendaService->agenda($request->user(), $company, $tab);
        $items = $agenda['items'];
        $customerIds = collect($items)
            ->map(fn (array $item) => (int) $item['customer']->id)
            ->unique()->values()->all();

        return view('cobros.visits.today', [
            'company' => $company,
            'tab' => $agenda['tab'],
            'tabs' => \App\Services\Cobros\AgendaService::TAB_LABELS,
            'rangeStart' => $agenda['start'],
            'rangeEnd' => $agenda['end'],
            // Eventos reales de la agenda (visitas/promesas unidas a la recurrencia).
            'items' => $items,
            // Compatibilidad: visitas con fila propia entre los eventos mostrados.
            'visits' => collect($items)->map(fn (array $item) => $item['visit'])->filter()->values(),
            'branches' => $request->user()->branches()->where('branches.company_id', $company->id)->get(),
            // Fase 3A: entrada al Gate desde un evento autorizado del propio cobrador.
            'chargeableAccounts' => $gate->chargeableAccountIds($request->user(), $company, $customerIds),
        ]);
    }

    /**
     * Bloque 6 — REPROGRAMAR una ocurrencia desde la Agenda.
     * Crea la visita de origen SOLO si hace falta y reutiliza VisitService::reschedule.
     * Cambia únicamente la fecha operativa de ESA visita: la frecuencia base
     * (cobros_assignments) y la CxC (due_date/saldo) quedan intactas. Sin IDOR.
     */
    public function agendaReschedule(Request $request, Customer $customer, \App\Services\Cobros\AgendaService $agenda): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $request->validate([
            'origin_date' => ['required', 'date'],
            'scheduled_for' => ['required', 'date', 'after:origin_date'],
        ], [
            'scheduled_for.after' => 'La nueva fecha debe ser posterior a la visita original.',
        ]);

        $agenda->rescheduleOccurrence(
            $request->user(),
            $company,
            $customer,
            $data['origin_date'],
            $data['scheduled_for']
        );

        return back()->with('success', 'Visita reprogramada. La frecuencia base y el crédito no cambiaron.');
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

        // Procesar visita => avanza la próxima fecha operativa (nunca la financiera).
        // Reprogramar NO avanza: la excepción no desplaza la recurrencia base.
        if (($validated['status'] ?? null) !== CobrosVisit::STATUS_RESCHEDULED) {
            app(\App\Services\Cobros\AgendaService::class)
                ->advanceAfterProcessing($request->user(), $company, $visit->customer);
        }

        return back()->with('success', 'Gestión registrada correctamente.');
    }

    /**
     * Gestión rápida desde la ficha de Mi Cartera (UX del centro de trabajo).
     * Reutiliza 2B: mismos estados, mismas validaciones y misma bitácora.
     * Cliente sin asignar o de otra empresa => 404 (autoridad = auth + cartera).
     */
    public function manage(Request $request, Customer $customer, VisitService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:visitado,no_localizado,promesa_pago,reprogramada'],
            'promise_date' => ['nullable', 'date', 'required_if:status,promesa_pago'],
            'scheduled_for' => ['nullable', 'date', 'required_if:status,reprogramada'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->manage($request->user(), $company, $customer, $validated);

        // Procesar visita => avanza la próxima fecha operativa (nunca la financiera).
        // Reprogramar NO avanza: la excepción no desplaza la recurrencia base.
        if ($validated['status'] !== CobrosVisit::STATUS_RESCHEDULED) {
            app(\App\Services\Cobros\AgendaService::class)
                ->advanceAfterProcessing($request->user(), $company, $customer);
        }

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
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ]
            : [
                'scheduled_for' => ['required', 'date'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ];

        // collector_id del request se IGNORA por diseño (autoridad = auth()->id()).
        return $request->validate($rules);
    }
}
