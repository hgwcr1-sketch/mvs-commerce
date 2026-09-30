<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4A — panel operativo del supervisor (SOLO LECTURA).
 *
 * Consolida cartera por cobrador, agenda del día, cobrado, recibos,
 * anulaciones y auditoría reutilizando servicios existentes
 * (CollectorManagementService, PortfolioSummaryService) sin crear reglas
 * financieras nuevas ni duplicar saldos: todo suma con BCMath sobre CxC.
 *
 * Autoridad: empresa activa de la sesión + permiso cobros.supervisar
 * (la ruta lo exige). Aislamiento por company_id en cada consulta y filtro
 * de sucursal validado contra la empresa y el alcance del supervisor.
 */
class SupervisorPanelService
{
    public function __construct(
        private readonly CollectorManagementService $collectors,
        private readonly PortfolioSummaryService $portfolio,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panel(Company $company, int $supervisorId, ?int $branchId): array
    {
        $summary = $this->portfolio->summarize($company, $branchId);

        $today = CarbonImmutable::today($company->timezone ?: config('app.timezone'));
        $dayStart = $today->setTimezone(config('app.timezone'));
        $dayEnd = $today->addDay()->setTimezone(config('app.timezone'));

        // Cartera por cobrador: asignaciones vigentes + saldo real de CxC.
        $rows = [];
        foreach ($this->collectors->listCollectors($company) as $collector) {
            $customerIds = CobrosAssignment::query()->forCompany($company->id)
                ->where('collector_user_id', $collector['id'])->pluck('customer_id');

            $open = AccountReceivable::query()->forCompany($company->id)
                ->when($branchId !== null, fn ($q) => $q->forBranch($branchId))
                ->whereIn('customer_id', $customerIds)
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED]);

            $collected = AccountReceivablePayment::query()
                ->where('company_id', $company->id)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->where('user_id', $collector['id'])
                ->where('paid_at', '>=', $dayStart)
                ->where('paid_at', '<', $dayEnd);

            $rows[] = [
                'id' => $collector['id'],
                'name' => $collector['name'],
                'is_active' => $collector['is_active'],
                'branches' => $collector['branches'],
                'assigned_customers' => $branchId !== null
                    ? (clone $open)->distinct()->count('customer_id')
                    : $customerIds->count(),
                'balance' => $this->portfolio->decimalSum(clone $open, 'balance_due'),
                'collected_today' => $this->portfolio->decimalSum($collected, 'amount'),
            ];
        }

        // Agenda del día: solo lectura de visitas ya registradas.
        $visitsToday = CobrosVisit::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('scheduled_for', $today->toDateString())
            ->with(['customer', 'collector'])->get();
        $visitCounts = $visitsToday->countBy('status');
        $visitsByCollector = $visitsToday->countBy('collector_user_id');

        $notFoundToday = $visitsToday->filter(
            fn (CobrosVisit $visit) => $visit->status === CobrosVisit::STATUS_NOT_FOUND
        )->take(10)->values();
        $overduePromises = CobrosVisit::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', CobrosVisit::STATUS_PROMISE)
            ->whereDate('promise_date', '<', $today->toDateString())
            ->with(['customer', 'collector'])->orderBy('promise_date')->limit(10)->get();

        // Recibos y anulaciones del día (mismo día operativo de la empresa).
        $receipts = CobrosReceipt::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('paid_at', '>=', $dayStart)->where('paid_at', '<', $dayEnd);
        $reversals = CobrosPaymentReversal::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('annulled_at', '>=', $dayStart)->where('annulled_at', '<', $dayEnd);

        // Auditoría reciente (mismo alcance que la pantalla de auditoría 3C).
        $logs = DB::table('routeos_audit_logs')
            ->where('company_id', $company->id)
            ->whereIn('action', ['cobros.payment.registered', 'cobros.payment.annulled'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(5)->get();
        $actorNames = DB::table('users')
            ->whereIn('id', collect($logs)->pluck('actor_id')->filter()->unique())
            ->pluck('name', 'id');

        return [
            'summary' => $summary,
            'today' => $today,
            'collectors' => $rows,
            'visitCounts' => $visitCounts,
            'visitsByCollector' => $visitsByCollector,
            'visitTotal' => $visitsToday->count(),
            'notFoundToday' => $notFoundToday,
            'overduePromises' => $overduePromises,
            'receiptsToday' => [
                'count' => (clone $receipts)->count(),
                'total' => $this->portfolio->decimalSum(clone $receipts, 'amount'),
            ],
            'reversalsToday' => [
                'count' => (clone $reversals)->count(),
                'total' => $this->portfolio->decimalSum(clone $reversals, 'amount'),
            ],
            'recentLogs' => $logs,
            'actorNames' => $actorNames,
        ];
    }

    /**
     * Sucursales que el supervisor puede filtrar: de su empresa, activas y,
     * cuando tiene sucursales asignadas, solo esas. Sin asignación explícita
     * supervisa toda la empresa activa.
     *
     * @return Collection<int, Branch>
     */
    public function filterableBranches(User $supervisor, Company $company)
    {
        $companyBranches = Branch::query()
            ->where('company_id', $company->id)->where('is_active', true)
            ->orderBy('name')->get();

        $assigned = $supervisor->branches()
            ->where('branches.company_id', $company->id)
            ->where('branches.is_active', true)->pluck('branches.id');

        if ($assigned->isEmpty()) {
            return $companyBranches;
        }

        return $companyBranches->filter(fn (Branch $branch) => $assigned->contains($branch->id))->values();
    }
}
