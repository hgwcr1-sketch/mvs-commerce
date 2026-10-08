<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCobrosCustomerRequest;
use App\Models\Company;
use App\Models\CobrosAssignment;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosVisit;
use App\Models\Customer;
use App\Services\Cobros\CobrosPaymentGate;
use App\Services\Cobros\CollectorAccessService;
use App\Services\Cobros\CollectorPortfolioService;
use App\Services\Cobros\InitialPortfolioMigrationService;
use App\Services\Cobros\PortfolioAssignmentService;
use App\Support\Cobros\WhatsApp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Fase 2A — Mi Cartera.
 * La autoridad del cobrador vive SOLO en la sesión (empresa activa) y auth()->id().
 * Nunca se acepta collector_id desde el request (evita IDOR).
 */
class CollectorPortfolioController extends Controller
{
    public function index(Request $request, CollectorPortfolioService $service, CollectorAccessService $access, CobrosPaymentGate $gate, InitialPortfolioMigrationService $initialPortfolio): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $search = $request->string('q')->trim()->toString() ?: null;
        $portfolio = $service->portfolio($request->user(), $company, $search);
        $customerIds = $portfolio['customers']->getCollection()->modelKeys();
        $availableRoutes = app(\App\Services\Cobros\CobrosRouteContext::class)->available($request->user());

        // 12A: semáforo por lote (3 consultas fijas, sin N+1 por cliente).
        $riskLevels = app(\App\Services\Cobros\CreditRiskService::class)->classifyMany($company, $portfolio['customers']);

        return view('cobros.collector.portfolio', [
            'company' => $company,
            // Ruta activa en sesión (nombre visible, nunca IDs internos).
            'activeRoute' => $availableRoutes->firstWhere('id', (int) session('active_cobros_route_id', 0)),
            'availableRoutes' => $availableRoutes,
            'customers' => $portfolio['customers'],
            'riskLevels' => $riskLevels,
            'totalBalance' => $portfolio['total_balance'],
            'search' => $search ?? '',
            // Contrato conservado del shell: sucursales operativas del cobrador en la empresa activa.
            'branches' => $access->branchesForCollecting($request->user(), $company),
            // Fase 3A: el botón COBRAR solo existe si el Gate lo permite.
            'chargeableAccounts' => $gate->chargeableAccountIds($request->user(), $company, $customerIds),
            // 5A: opción de cartera inicial visible hasta que se finalice.
            'initialPortfolioState' => $initialPortfolio->stateFor($company, $request->user()),
        ]);
    }

    public function show(Request $request, Customer $customer, CollectorPortfolioService $service, CobrosPaymentGate $gate): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $service->customerPortfolio($request->user(), $company, $customer);

        // Bloque 6: configuración operativa de visitas + backfill perezoso desde 5A/5B
        // SOLO si el assignment aún no tiene configuración (nunca sobrescribe).
        $assignment = CobrosAssignment::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->where('collector_user_id', $request->user()->id)
            ->first();
        if ($assignment !== null) {
            $this->backfillVisitSchedule($assignment, $company);
            $assignment = $assignment->fresh();
        }

        $accountGateDecisions = [];
        foreach ($data['openAccounts'] as $account) {
            $accountGateDecisions[$account->id] = $gate->evaluate($request->user(), $company, $account);
        }

        // Próxima promesa o reprogramación propia del cobrador (resumen, sin IDs).
        $nextVisit = CobrosVisit::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->where('collector_user_id', $request->user()->id)
            ->whereIn('status', [CobrosVisit::STATUS_PENDING, CobrosVisit::STATUS_PROMISE])
            ->orderBy('scheduled_for')->orderBy('id')->first();

        // 10A: expediente PERMANENTE del cliente (estado para la ficha).
        $expediente = \App\Models\CobrosCreditExpediente::query()->forCompany((int) $company->id)
            ->where('customer_id', $customer->id)->with('references')->first();

        return view('cobros.collector.customer', [
            'company' => $company,
            'customer' => $data['customer'],
            'expediente' => $expediente,
            'creditRequests' => $data['creditRequests'],
            'credits' => $data['credits'],
            'receipts' => $data['receipts'],
            'visits' => $data['visits'],
            'nextDueDate' => $data['nextDueDate'],
            'openAccounts' => $data['openAccounts'],
            'totalBalance' => $data['total_balance'],
            'payments' => $data['payments'],
            'chargeableAccounts' => $gate->chargeableAccountIds($request->user(), $company, [$customer->id]),
            'accountGateDecisions' => $accountGateDecisions,
            'whatsappUrl' => WhatsApp::url($company, $data['customer']),
            'nextVisit' => $nextVisit,
            // Bloque 6: día/frecuencia de visita (operativo, no financiero).
            'visitSchedule' => $assignment,
        ]);
    }

    /**
     * Bloque 6 — guardar "Día de cobro / visita" del cliente en SU assignment.
     * Autoridad: cliente de MISMA empresa asignado a este cobrador dentro de la
     * cartera/ruta activa (customerPortfolio => 404, sin IDOR). Solo lectura de CxC:
     * JAMÁS modifica due_date, saldo, mora, cuotas, pagos ni condiciones del crédito.
     */
    public function storeVisitSchedule(Request $request, Customer $customer, CollectorPortfolioService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        // Mismo criterio de autoridad de la ficha (assignment + ruta activa => 404 si no).
        $service->customerPortfolio($request->user(), $company, $customer);

        $data = $request->validate([
            'visit_frequency' => ['required', 'string', 'in:diario,semanal,quincenal,mensual'],
            'visit_weekday' => ['nullable', 'integer', 'between:0,6'],
            'visit_month_day' => ['nullable', 'integer', 'between:1,31'],
            'next_visit_date' => ['nullable', 'date'],
        ], [
            'visit_frequency.required' => 'Elija la frecuencia de visita.',
            'visit_weekday.between' => 'El día de la semana debe estar entre 0 (domingo) y 6 (sábado).',
            'visit_month_day.between' => 'El día del mes debe estar entre 1 y 31.',
        ]);

        // Campos condicionales estrictos según frecuencia.
        $errors = [];
        if ($data['visit_frequency'] === 'semanal' && ! isset($data['visit_weekday'])) {
            $errors['visit_weekday'] = 'Elija el día de la semana de la visita.';
        } elseif ($data['visit_frequency'] === 'mensual' && ! isset($data['visit_month_day'])) {
            $errors['visit_month_day'] = 'Elija el día del mes de la visita.';
        } elseif ($data['visit_frequency'] === 'quincenal' && empty($data['next_visit_date'])) {
            $errors['next_visit_date'] = 'Indique la fecha ancla de la quincena (no se asume día 1/15).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $assignment = CobrosAssignment::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->where('collector_user_id', $request->user()->id)
            ->firstOrFail();

        $assignment->update([
            'visit_frequency' => $data['visit_frequency'],
            // Limpia campos que no aplican para evitar datos obsoletos.
            'visit_weekday' => $data['visit_frequency'] === 'semanal' ? (int) $data['visit_weekday'] : null,
            'visit_month_day' => $data['visit_frequency'] === 'mensual' ? (int) $data['visit_month_day'] : null,
            'next_visit_date' => $data['next_visit_date'] ?? null,
        ]);

        return redirect()
            ->route('cobros.collector.portfolio.show', $customer)
            ->with('success', 'Día de cobro/visita actualizado. No se modificó ninguna fecha financiera.');
    }

    /**
     * Backfill SOLO desde lo declarado en 5A/5B (frequency + next_charge_date)
     * cuando el assignment aún NO tiene configuración operativa. Nunca sobrescribe,
     * nunca modifica CobrosInitialPortfolioRecord y nunca toca la CxC.
     * Semanal/mensual derivan día de la fecha ancla declarada (sin inventar datos).
     */
    private function backfillVisitSchedule(CobrosAssignment $assignment, Company $company): void
    {
        if ($assignment->visit_frequency !== null || $assignment->next_visit_date !== null) {
            return;
        }

        $record = CobrosInitialPortfolioRecord::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $assignment->customer_id)
            ->orderByDesc('id')->first();

        if ($record === null || $record->frequency === null || $record->next_charge_date === null) {
            return;
        }

        $anchor = $record->next_charge_date;

        $assignment->update([
            'visit_frequency' => $record->frequency,
            'visit_weekday' => $record->frequency === 'semanal' ? (int) $anchor->format('w') : null,
            'visit_month_day' => $record->frequency === 'mensual' ? (int) $anchor->day : null,
            'next_visit_date' => $anchor->toDateString(),
        ]);
    }

    /**
     * Fase 6B — "Nuevo cliente" del cobrador.
     * Alta mínima dentro de CobrOS (nombre/teléfono/dirección): sin crédito ni
     * nivel de precio, que siguen en Commerce. El cliente se asigna automáticamente
     * a la cartera del cobrador autenticado con el mismo servicio de asignación
     * que usa el dueño (sin reglas duplicadas).
     */
    /** 2) MIS CLIENTES — personas de mi cartera/ruta, con o sin crédito activo. */
    public function mine(Request $request, CollectorPortfolioService $service): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $search = $request->string('q')->trim()->toString() ?: null;
        $data = $service->misClientes($request->user(), $company, $search);
        $availableRoutes = app(\App\Services\Cobros\CobrosRouteContext::class)->available($request->user());

        // 12A: semáforo por lote (3 consultas fijas, sin N+1 por cliente).
        $riskLevels = app(\App\Services\Cobros\CreditRiskService::class)->classifyMany($company, $data['customers']);

        return view('cobros.collector.my-customers', [
            'company' => $company,
            'activeRoute' => $availableRoutes->firstWhere('id', (int) session('active_cobros_route_id', 0)),
            'customers' => $data['customers'],
            'riskLevels' => $riskLevels,
            'search' => $data['search'] ?? '',
        ]);
    }

    public function createCustomer(Request $request): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        return view('cobros.collector.customer-create', [
            'company' => $company,
            'creditAssignmentEnabled' => $company->allow_collector_credit_on_customer_create
                && $request->user()->hasPermission('cobros.cobrar', $company),
            'creditLimitMax' => (string) $company->collector_customer_credit_limit_max,
        ]);
    }

    public function storeCustomer(StoreCobrosCustomerRequest $request, PortfolioAssignmentService $assignments): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        $data = $request->validated();
        $data['company_id'] = $company->id;

        $customer = Customer::create($data);

        $assignments->assign(
            $company,
            $customer,
            $request->user(),
            $request->user(),
            'Alta desde Mi cartera'
        );

        return redirect()
            ->route('cobros.collector.portfolio.show', $customer)
            ->with('success', "Cliente {$customer->name} creado y agregado a su cartera.");
    }

    /** Fase 2C — guardar ubicación actual del cliente en su cartera. */
    public function storeLocation(Request $request, Customer $customer, CollectorPortfolioService $service): RedirectResponse
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

    /**
     * Teléfono del cliente (o contacto indicado) capturado en el recibo.
     * Autoridad: cliente de MISMA empresa asignado a este cobrador (o 404).
     * Guarda en el campo telefónico existente y devuelve al recibo habilitado.
     */
    public function storePhone(Request $request, Customer $customer, CollectorPortfolioService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'receipt' => ['nullable', 'string', 'max:40'],
        ]);

        $service->savePhone($request->user(), $company, $customer, $validated['phone']);

        $receipt = trim((string) ($validated['receipt'] ?? ''));

        return $receipt !== ''
            ? redirect()->route('cobros.collector.payments.receipt', $receipt)
            : back()->with('success', 'Teléfono guardado correctamente.');
    }
}
