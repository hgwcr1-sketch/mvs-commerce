<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosDailyClosing;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Cobros\CollectorManagementService;
use App\Services\Cobros\CollectorPortfolioService;
use App\Services\Cobros\DailyClosingService;
use App\Services\Cobros\PortfolioSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwnerCollectorController extends Controller
{
    public function index(Request $request, CollectorManagementService $service): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $contract = $service->getContract($company);
        $collectors = $service->listCollectors($company);
        $branches = Branch::where('company_id', $company->id)->orderBy('name')->get();

        return view('cobros.owner.collectors', [
            'company' => $company,
            'contract' => $contract,
            'collectors' => $collectors,
            'branches' => $branches,
            'collectorCount' => $service->getCollectorCount($company),
            'canCreate' => $service->canCreateCollector($company),
            'remainingSlots' => $service->getRemainingSlots($company),
            'isReadOnly' => $service->isOwnerReadOnly($company),
        ]);
    }

    /**
     * Fase 6B — drill-down del dueño por cobrador.
     *
     * Muestra la cartera real de un cobrador (clientes, saldos, próximos
     * vencimientos) más sus métricas del mes. Reutiliza
     * CollectorPortfolioService::portfolio() y PortfolioSummaryService:
     * solo lectura, sin duplicar sumas y siempre acotado a la empresa activa.
     */
    public function show(
        Request $request,
        User $user,
        CollectorPortfolioService $portfolio,
        PortfolioSummaryService $summary,
        DailyClosingService $closingService,
    ): View {
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        // 404 salvo que el usuario sea miembro de la empresa activa con un rol
        // que incluye cobros.cobrar (mismo criterio del listado de cobradores).
        $collector = User::query()
            ->whereKey($user->id)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->whereHas('roles', function ($q) use ($company) {
                $q->where('roles.company_id', $company->id)
                    ->where('roles.is_active', true)
                    ->whereHas('permissions', fn ($p) => $p->where('permissions.name', 'cobros.cobrar'));
            })
            ->firstOrFail();

        $search = $request->string('q')->trim()->toString() ?: null;
        $data = $portfolio->portfolio($collector, $company, $search);

        $timezone = $company->timezone ?: config('app.timezone');
        $monthStart = CarbonImmutable::today($timezone)
            ->startOfMonth()
            ->startOfDay()
            ->setTimezone(config('app.timezone'));

        $collected = $summary->decimalSum(
            AccountReceivablePayment::query()
                ->where('company_id', $company->id)
                ->where('user_id', $collector->id)
                ->where('paid_at', '>=', $monthStart),
            'amount'
        );

        $receiptsCount = CobrosReceipt::query()
            ->forCompany($company->id)
            ->where('collector_user_id', $collector->id)
            ->where('paid_at', '>=', $monthStart)
            ->count();

        // Medios de pago del mes (mismo día/mes que "cobrado"; neto de reversos).
        $monthPaymentIds = AccountReceivablePayment::query()
            ->where('company_id', $company->id)
            ->where('user_id', $collector->id)
            ->where('paid_at', '>=', $monthStart)
            ->pluck('id');
        $reversedMonth = CobrosPaymentReversal::query()
            ->forCompany($company->id)
            ->whereIn('payment_id', $monthPaymentIds)
            ->get(['payment_id', 'amount']);
        $methodTotals = array_fill_keys(DailyClosingService::BUCKETS, '0.0000');
        $monthPayments = AccountReceivablePayment::query()
            ->where('company_id', $company->id)
            ->where('user_id', $collector->id)
            ->where('paid_at', '>=', $monthStart)
            ->with('paymentMethod:id,type')
            ->get();
        $reversedBucket = [];
        foreach ($monthPayments as $monthPayment) {
            $bucket = $closingService->bucketFor($monthPayment->paymentMethod?->type);
            $reversedBucket[$monthPayment->id] = $bucket;
            $methodTotals[$bucket] = bcadd($methodTotals[$bucket], (string) $monthPayment->amount, 4);
        }
        foreach ($reversedMonth as $reversal) {
            $bucket = $reversedBucket[$reversal->payment_id] ?? null;
            if ($bucket !== null) {
                $methodTotals[$bucket] = bcsub($methodTotals[$bucket], (string) $reversal->amount, 4);
            }
        }

        // Agenda del cobrador: visitas de hoy y promesas de pago del mes.
        $todayString = CarbonImmutable::today($timezone)->toDateString();
        $monthDateString = $monthStart->toDateString();
        $visitsToday = CobrosVisit::query()
            ->where('company_id', $company->id)
            ->where('collector_user_id', $collector->id)
            ->where('scheduled_for', $todayString);
        $visitsTodayTotal = (clone $visitsToday)->count();
        $visitsTodayPending = (clone $visitsToday)->where('status', CobrosVisit::STATUS_PENDING)->count();
        $visitsMonth = CobrosVisit::query()
            ->where('company_id', $company->id)
            ->where('collector_user_id', $collector->id)
            ->where('scheduled_for', '>=', $monthDateString);
        $promisesMonth = (clone $visitsMonth)
            ->where('status', CobrosVisit::STATUS_PROMISE)
            ->count();

        // Clientes nuevos del mes ya asignados a este cobrador.
        $newCustomersMonth = Customer::query()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $monthStart)
            ->whereHas('cobrosAssignments', fn ($q) => $q
                ->forCompany($company->id)
                ->where('collector_user_id', $collector->id))
            ->count();

        // Cierres diarios recientes con sus diferencias declarado vs sistema.
        $closings = CobrosDailyClosing::query()
            ->where('company_id', $company->id)
            ->where('collector_user_id', $collector->id)
            ->orderByDesc('business_date')
            ->limit(14)
            ->get()
            ->map(fn (CobrosDailyClosing $closing) => [
                'closing' => $closing,
                'differences' => $closingService->differences($closing),
            ]);

        return view('cobros.owner.collector-show', [
            'company' => $company,
            'collector' => $collector,
            'customers' => $data['customers'],
            'totalBalance' => $data['total_balance'],
            'search' => $search ?? '',
            'collected' => $collected,
            'receiptsCount' => $receiptsCount,
            'monthLabel' => CarbonImmutable::today($timezone)->locale('es')->monthName,
            'methodTotals' => $methodTotals,
            'visitsTodayTotal' => $visitsTodayTotal,
            'visitsTodayPending' => $visitsTodayPending,
            'visitsMonth' => (clone $visitsMonth)->count(),
            'promisesMonth' => $promisesMonth,
            'newCustomersMonth' => $newCustomersMonth,
            'closings' => $closings,
        ]);
    }

    public function store(Request $request, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede crear cobradores en modalidad de licencias individuales.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $result = $service->createCollector($company, $data, $request->user());

        return back()
            ->with('success', "Cobrador {$result['user']->name} registrado exitosamente.")
            ->with('collector_plain_password', $result['plain_password'])
            ->with('collector_email', $result['user']->email)
            ->with('collector_name', $result['user']->name)
            ->with('collector_phone', $result['user']->phone);
    }

    public function regenerateAccess(User $user, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede modificar cobradores en modalidad de licencias individuales.');
        }

        $plainPassword = $service->regenerateAccess($user, $company);

        return back()
            ->with('success', "Nuevo acceso generado para {$user->name}.")
            ->with('collector_plain_password', $plainPassword)
            ->with('collector_email', $user->email)
            ->with('collector_name', $user->name)
            ->with('collector_phone', $user->phone);
    }

    public function toggle(User $user, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede modificar cobradores en modalidad de licencias individuales.');
        }

        $service->toggleCollector($user, $company, ! $user->is_active);

        $status = $user->fresh()->is_active ? 'activado' : 'desactivado';

        return back()->with('success', "Cobrador {$user->name} {$status}.");
    }

    public function updateBranches(Request $request, User $user, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede modificar cobradores en modalidad de licencias individuales.');
        }

        $data = $request->validate([
            'branch_ids' => ['required', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $service->assignBranches($user, $company, $data['branch_ids']);

        return back()->with('success', "Sucursales de {$user->name} actualizadas.");
    }

    /**
     * El dueño revisa un cierre diario: deja constancia de conformidad o de
     * las diferencias observadas (hora, usuario y nota en la misma fila).
     */
    public function reviewClosing(Request $request, CobrosDailyClosing $closing): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($closing->company_id !== $company->id) {
            abort(404);
        }

        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:500'],
        ]);

        $closing->update([
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $request->user()->id,
            'review_notes' => $data['review_notes'],
        ]);

        return back()->with('success', 'Revisión del cierre registrada.');
    }
}
