<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Alert;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Services\Notifications\AlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Fase 5A — alertas y notificaciones CobrOS.
 *
 * Reutiliza el Centro de Notificaciones de Commerce (AlertDispatcher +
 * AlertTypeRegistry + preferencias) con los tipos del canal CobrOS. Solo
 * dueño/supervisor pueden recibirlas (permiso notificaciones.cobros + rol
 * CobrOS); la deduplicación la garantiza el dedupe_hash del dispatcher.
 *
 * Cobros y anulaciones se despachan desde sus servicios (3B/3C); las
 * condiciones diarias (promesas vencidas, no localizados, custodia y
 * licencia) se evalúan con evaluate(), llamado desde la pantalla de alertas
 * o desde el comando `cobros:evaluate-alerts`. Nunca escribe dinero.
 */
class CobrosAlertService
{
    public const LICENSE_WARNING_DAYS = 15;

    public const TYPES = [
        'cobros.payment_registered',
        'cobros.payment_annulled',
        'cobros.promise_overdue',
        'cobros.customer_not_found',
        'cobros.custody_pending',
        'cobros.license_expiring',
    ];

    public function __construct(
        private readonly AlertDispatcher $dispatcher,
        private readonly PortfolioSummaryService $portfolio,
    ) {}

    /** Cobro confirmado (3B). */
    public function paymentRegistered(Company $company, AccountReceivable $account, AccountReceivablePayment $payment, CobrosReceipt $receipt): ?Alert
    {
        return $this->safeDispatch('cobros.payment_registered', [
            'company_id' => $company->id,
            'branch_id' => $account->branch_id !== null ? (int) $account->branch_id : null,
            'severity' => Alert::SEVERITY_INFO,
            'entity_type' => AccountReceivablePayment::class,
            'entity_id' => (int) $payment->id,
            'link' => '/cobros/collector/receipts/'.$receipt->receipt_number,
            'dedupe_key' => (string) $receipt->receipt_number,
            'metadata' => [
                'receipt_number' => $receipt->receipt_number,
                'payment_id' => (int) $payment->id,
                'account_receivable_id' => (int) $account->id,
                'customer_id' => (int) $account->customer_id,
                'amount' => (string) $payment->amount,
            ],
        ]);
    }

    /** Anulación con reverso (3C). */
    public function paymentAnnulled(Company $company, CobrosPaymentReversal $reversal, CobrosReceipt $receipt): ?Alert
    {
        return $this->safeDispatch('cobros.payment_annulled', [
            'company_id' => $company->id,
            'branch_id' => (int) $reversal->branch_id,
            'severity' => Alert::SEVERITY_ATTENTION,
            'entity_type' => CobrosPaymentReversal::class,
            'entity_id' => (int) $reversal->id,
            'link' => '/cobros/collector/receipts/'.$receipt->receipt_number,
            'dedupe_key' => (string) $reversal->reversal_number,
            'metadata' => [
                'receipt_number' => $receipt->receipt_number,
                'reversal_number' => $reversal->reversal_number,
                'payment_id' => (int) $reversal->payment_id,
                'amount' => (string) $reversal->amount,
                'reason' => (string) $reversal->reason,
            ],
        ]);
    }

    /**
     * Evalúa las condiciones diarias de una empresa y despacha las alertas
     * (deduplicadas por el dispatcher: repetir la evaluación no duplica).
     *
     * @return array<string, int> cantidad de alertas activas por condición
     */
    public function evaluate(Company $company): array
    {
        $today = CarbonImmutable::today($company->timezone ?: config('app.timezone'));
        $summary = [
            'promise_overdue' => 0,
            'customer_not_found' => 0,
            'custody_pending' => 0,
            'license_expiring' => 0,
        ];

        // Promesas de pago vencidas (acotado por sucursal de la visita).
        $overdue = CobrosVisit::query()->forCompany($company->id)
            ->where('status', CobrosVisit::STATUS_PROMISE)
            ->whereDate('promise_date', '<', $today->toDateString())
            ->orderBy('id')->limit(500)->get();
        foreach ($overdue as $visit) {
            $alert = $this->safeDispatch('cobros.promise_overdue', [
                'company_id' => $company->id,
                'branch_id' => $visit->branch_id !== null ? (int) $visit->branch_id : null,
                'severity' => Alert::SEVERITY_ATTENTION,
                'entity_type' => CobrosVisit::class,
                'entity_id' => (int) $visit->id,
                'link' => '/cobros/visits/customer/'.$visit->customer_id,
                'dedupe_key' => 'visit:'.$visit->id,
                'metadata' => [
                    'customer_id' => (int) $visit->customer_id,
                    'customer_name' => (string) ($visit->customer?->name ?? ''),
                    'collector_id' => (int) $visit->collector_user_id,
                    'promise_date' => $visit->promise_date?->toDateString(),
                    'promise_amount' => (string) $visit->promise_amount,
                ],
            ]);
            if ($alert?->wasRecentlyCreated) {
                $summary['promise_overdue']++;
            }
        }

        // Clientes no localizados hoy.
        $notFound = CobrosVisit::query()->forCompany($company->id)
            ->where('status', CobrosVisit::STATUS_NOT_FOUND)
            ->whereDate('scheduled_for', $today->toDateString())
            ->orderBy('id')->limit(500)->get();
        foreach ($notFound as $visit) {
            $alert = $this->safeDispatch('cobros.customer_not_found', [
                'company_id' => $company->id,
                'branch_id' => $visit->branch_id !== null ? (int) $visit->branch_id : null,
                'severity' => Alert::SEVERITY_ATTENTION,
                'entity_type' => CobrosVisit::class,
                'entity_id' => (int) $visit->id,
                'link' => '/cobros/visits/customer/'.$visit->customer_id,
                'dedupe_key' => 'visit:'.$visit->id,
                'metadata' => [
                    'customer_id' => (int) $visit->customer_id,
                    'customer_name' => (string) ($visit->customer?->name ?? ''),
                    'collector_id' => (int) $visit->collector_user_id,
                    'scheduled_for' => $visit->scheduled_for?->toDateString(),
                ],
            ]);
            if ($alert?->wasRecentlyCreated) {
                $summary['customer_not_found']++;
            }
        }

        // Custodia pendiente por sucursal: pagos sin caja menos sus reversos.
        $branchIds = Company::query()->whereKey($company->id)
            ->with(['branches' => fn ($q) => $q->where('is_active', true)])->first()
            ->branches->pluck('id');
        foreach ($branchIds as $branchId) {
            $gross = $this->portfolio->decimalSum(
                AccountReceivablePayment::query()
                    ->where('company_id', $company->id)->where('branch_id', $branchId)
                    ->whereNull('cash_session_id'),
                'amount'
            );
            $reversed = $this->portfolio->decimalSum(
                CobrosPaymentReversal::query()->forCompany($company->id)->where('branch_id', $branchId),
                'amount'
            );
            $net = bccomp($gross, $reversed, 4) >= 0 ? bcsub($gross, $reversed, 4) : '0.0000';
            if (bccomp($net, '0', 4) <= 0) {
                continue;
            }

            $alert = $this->safeDispatch('cobros.custody_pending', [
                'company_id' => $company->id,
                'branch_id' => (int) $branchId,
                'severity' => Alert::SEVERITY_INFO,
                'entity_type' => Company::class,
                'entity_id' => (int) $company->id,
                'link' => '/cobros/owner/reports',
                'dedupe_key' => 'custody:'.$branchId.':'.$today->toDateString(),
                'metadata' => ['net' => $net, 'gross' => $gross, 'reversed' => $reversed],
            ]);
            if ($alert?->wasRecentlyCreated) {
                $summary['custody_pending']++;
            }
        }

        // Licencia próxima a vencer (≤ 15 días).
        $license = $company->license()->first();
        if ($license !== null && $license->expires_at !== null && $license->status === 'active'
            && $license->expires_at->lte($today->addDays(self::LICENSE_WARNING_DAYS))) {
            $alert = $this->safeDispatch('cobros.license_expiring', [
                'company_id' => $company->id,
                'severity' => Alert::SEVERITY_ATTENTION,
                'entity_type' => null,
                'entity_id' => null,
                'link' => '/cobros/owner/reports',
                'dedupe_key' => 'license:'.$license->expires_at->toDateString(),
                'metadata' => [
                    'expires_at' => $license->expires_at->toDateString(),
                    'days_left' => (int) CarbonImmutable::today($company->timezone ?: config('app.timezone'))
                        ->diffInDays($license->expires_at->startOfDay()),
                ],
            ]);
            if ($alert?->wasRecentlyCreated) {
                $summary['license_expiring']++;
            }
        }

        return $summary;
    }

    /**
     * Una falla de notificación nunca debe romper el flujo operativo.
     *
     * @param  array<string, mixed>  $data
     */
    private function safeDispatch(string $type, array $data): ?Alert
    {
        try {
            return $this->dispatcher->dispatch($type, $data);
        } catch (\Throwable $e) {
            Log::error('Error despachando alerta CobrOS', ['type' => $type, 'exception' => $e->getMessage()]);

            return null;
        }
    }
}
