<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosAssignment;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4B — panel avanzado del dueño (SOLO LECTURA).
 *
 * Responde "¿cuánto recuperó cada cobrador este período?" con métricas por
 * empresa/sucursal, ranking de cobradores, antigüedad de vencimientos,
 * custodia pendiente, recibos, anulaciones y auditoría.
 *
 * Reutiliza PortfolioSummaryService (consistencia con el dashboard 1C y su
 * suma BCMath) y CollectorManagementService::listCollectors. No escribe nada
 * financiero, no duplica saldos: todas las sumas leen CxC/ recibos/ reversos
 * ya existentes y están acotadas por company_id (y sucursal cuando aplica).
 */
class OwnerReportService
{
    public function __construct(
        private readonly CollectorManagementService $collectors,
        private readonly PortfolioSummaryService $portfolio,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Company $company, ?int $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // summarize() además valida que la sucursal pertenezca a la empresa.
        $summary = $this->portfolio->summarize($company, $branchId);

        $timezone = $company->timezone ?: config('app.timezone');
        $today = CarbonImmutable::today($timezone);
        $start = $from->startOfDay()->setTimezone(config('app.timezone'));
        $end = $to->addDay()->startOfDay()->setTimezone(config('app.timezone'));

        $payments = AccountReceivablePayment::query()
            ->where('company_id', $company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end);
        $receipts = CobrosReceipt::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end);
        $reversals = CobrosPaymentReversal::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where('annulled_at', '>=', $start)->where('annulled_at', '<', $end);

        $totals = [
            'collected' => $this->portfolio->decimalSum(clone $payments, 'amount'),
            'payments_count' => (clone $payments)->count(),
            'customers_served' => (clone $payments)->distinct()->count('customer_id'),
            'receipts_count' => (clone $receipts)->count(),
            'receipts_total' => $this->portfolio->decimalSum(clone $receipts, 'amount'),
            'reversals_count' => (clone $reversals)->count(),
            'reversals_total' => $this->portfolio->decimalSum(clone $reversals, 'amount'),
            'audit_events' => DB::table('routeos_audit_logs')
                ->where('company_id', $company->id)
                ->whereIn('action', ['cobros.payment.registered', 'cobros.payment.annulled'])
                ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
                ->count(),
        ];

        // Custodia pendiente: cobros sin sesión de caja menos sus reversos.
        $custodyPayments = AccountReceivablePayment::query()
            ->where('company_id', $company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('cash_session_id');
        $custodyReversals = CobrosPaymentReversal::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));
        $custodyGross = $this->portfolio->decimalSum(clone $custodyPayments, 'amount');
        $custodyReversed = $this->portfolio->decimalSum(clone $custodyReversals, 'amount');
        $custody = [
            'payments_count' => (clone $custodyPayments)->count(),
            'gross' => $custodyGross,
            'reversed' => $custodyReversed,
            'net' => bccomp($custodyGross, $custodyReversed, 4) >= 0
                ? bcsub($custodyGross, $custodyReversed, 4)
                : '0.0000',
        ];

        // Ranking por cobrador dentro del período.
        $rows = [];
        foreach ($this->collectors->listCollectors($company) as $collector) {
            $customerIds = CobrosAssignment::query()->forCompany($company->id)
                ->where('collector_user_id', $collector['id'])->pluck('customer_id');

            $open = AccountReceivable::query()->forCompany($company->id)
                ->when($branchId !== null, fn ($q) => $q->forBranch($branchId))
                ->whereIn('customer_id', $customerIds)
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED]);

            $collected = $this->portfolio->decimalSum(
                (clone $payments)->where('user_id', $collector['id']),
                'amount'
            );

            $rows[] = [
                'id' => $collector['id'],
                'name' => $collector['name'],
                'is_active' => $collector['is_active'],
                'assigned_customers' => $branchId !== null
                    ? (clone $open)->distinct()->count('customer_id')
                    : $customerIds->count(),
                'balance' => $this->portfolio->decimalSum(clone $open, 'balance_due'),
                'collected' => $collected,
                'annulled' => $this->portfolio->decimalSum(
                    (clone $reversals)->where('collector_user_id', $collector['id']),
                    'amount'
                ),
                'receipts' => (clone $receipts)->where('collector_user_id', $collector['id'])->count(),
            ];
        }
        usort($rows, fn (array $a, array $b) => bccomp($b['collected'], $a['collected'], 4)
            ?: strcmp($a['name'], $b['name']));

        // Antigüedad de vencimientos (solo presentación, nunca persiste).
        $aging = [
            'Vigente' => ['count' => 0, 'amount' => '0.0000'],
            'De 1 a 30 días' => ['count' => 0, 'amount' => '0.0000'],
            'De 31 a 60 días' => ['count' => 0, 'amount' => '0.0000'],
            'De 61 a 90 días' => ['count' => 0, 'amount' => '0.0000'],
            'Más de 90 días' => ['count' => 0, 'amount' => '0.0000'],
        ];
        $openAccounts = AccountReceivable::query()->forCompany($company->id)
            ->when($branchId !== null, fn ($q) => $q->forBranch($branchId))
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED]);
        foreach ($openAccounts->select(['id', 'due_date', 'balance_due'])->lazyById(500) as $account) {
            $bucket = $this->agingBucket($account->due_date, $today);
            $aging[$bucket]['count']++;
            $aging[$bucket]['amount'] = bcadd($aging[$bucket]['amount'], (string) $account->balance_due, 4);
        }

        return [
            'summary' => $summary,
            'today' => $today,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $totals,
            'custody' => $custody,
            'collectors' => $rows,
            'aging' => $aging,
        ];
    }

    private function agingBucket(mixed $dueDate, CarbonImmutable $today): string
    {
        if ($dueDate === null) {
            return 'Vigente';
        }
        $due = $dueDate instanceof CarbonInterface
            ? $dueDate
            : CarbonImmutable::parse((string) $dueDate);

        if ($due->toDateString() >= $today->toDateString()) {
            return 'Vigente';
        }

        $days = (int) abs($today->diffInDays($due));

        return match (true) {
            $days <= 30 => 'De 1 a 30 días',
            $days <= 60 => 'De 31 a 60 días',
            $days <= 90 => 'De 61 a 90 días',
            default => 'Más de 90 días',
        };
    }
}
