<?php

declare(strict_types=1);

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditRenewalRequest;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosCreditRequestLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REN-03D2 — Activación transaccional de una renovación CONSOLIDADA.
 *
 * Precondiciones server-side: solicitud CONSENTED (aprobada + firma del
 * cliente), términos firmados íntegros (SHA-256) y saldo pendiente del
 * crédito anterior aún disponible. En UNA transacción con lockForUpdate:
 *  - crea EXACTAMENTE un crédito consolidado + su CxC + su calendario;
 *  - registra el desembolso SOLO por el capital nuevo;
 *  - cierra el crédito anterior por renovación y cancela su CxC
 *    (impide el doble cobro); NO genera pagos ficticios.
 * Reintentar es idempotente: devuelve el crédito ya activado.
 */
class CreditRenewalActivationService
{
    public function __construct(
        private InstallmentScheduleService $schedule,
        private CreditInstallmentService $installments,
        private PortfolioAssignmentService $assignments,
    ) {}

    public function activate(CobrosCreditRenewalRequest $renewal, User $actor): CobrosCredit
    {
        $company = Company::findOrFail((int) $renewal->company_id);

        return DB::transaction(function () use ($renewal, $actor, $company): CobrosCredit {
            $locked = CobrosCreditRenewalRequest::query()
                ->where('company_id', $renewal->company_id)
                ->whereKey($renewal->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                abort(404);
            }

            // Idempotencia: ya activada → devuelve el crédito consolidado.
            if ($locked->status === CobrosCreditRenewalRequest::STATUS_ACTIVATED) {
                $existing = CobrosCredit::forCompany($company->id)
                    ->whereKey($locked->activated_credit_id)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($locked->status !== CobrosCreditRenewalRequest::STATUS_CONSENTED) {
                throw ValidationException::withMessages([
                    'renewal' => 'La renovación debe estar aprobada y firmada por el cliente antes de activarse.',
                ]);
            }

            // Términos firmados: el hash guardado debe seguir coincidiendo
            // con los valores congelados (bloquea manipulación posterior).
            $service = app(CreditRenewalConsentService::class);
            $hash = $service->hashSnapshot($service->consentSnapshot($locked));
            if ($locked->terms_hash === null || ! hash_equals((string) $locked->terms_hash, $hash)) {
                throw ValidationException::withMessages([
                    'terms_hash' => 'Los términos firmados no coinciden con los registrados.',
                ]);
            }

            $previous = CobrosCredit::forCompany($company->id)
                ->whereKey($locked->credit_id)
                ->lockForUpdate()
                ->first();
            if ($previous === null || $previous->status !== CobrosCredit::STATUS_DISBURSED) {
                throw ValidationException::withMessages([
                    'renewal' => 'El crédito anterior ya no está disponible para activar la renovación.',
                ]);
            }

            // Revalidación del saldo pendiente: si cambió desde la firma,
            // la consolidación deja de ser válida (sin doble cobro ni pérdida).
            $pendingNow = $this->installments->totalPending($previous);
            if (bccomp($pendingNow, (string) $locked->previous_pending, 4) !== 0) {
                throw ValidationException::withMessages([
                    'pending' => 'El saldo pendiente del crédito anterior cambió desde la firma; solicite una nueva renovación.',
                ]);
            }

            $customer = $previous->customer;
            $collector = User::whereKey($locked->collector_user_id)->firstOrFail();
            $branch = $company->branches()->where('is_active', true)->orderBy('id')->first();
            if ($branch === null) {
                throw ValidationException::withMessages([
                    'branch' => 'La empresa no tiene sucursal para registrar la CxC.',
                ]);
            }

            $totalDue = $this->schedule->money((string) $locked->consolidated_total);
            $newCapital = $this->schedule->money((string) $locked->disbursement);
            $rows = $this->schedule->build(
                $totalDue,
                (int) $locked->installment_count,
                (string) $locked->frequency,
                (string) $locked->first_due_date->toDateString(),
            );

            // Intereses nuevos SOBRE el capital nuevo: coherencia con REN-03B.
            $expectedInterest = $this->schedule->round4(
                bcsub(
                    $this->schedule->totalWithInterest($newCapital, (string) $previous->interest_type, (string) $previous->interest_value),
                    $newCapital,
                    4,
                ),
            );
            if (bccomp($expectedInterest, (string) $locked->new_interest, 4) !== 0) {
                throw ValidationException::withMessages([
                    'new_interest' => 'Los intereses nuevos no corresponden al capital adicional.',
                ]);
            }

            // Solicitud interna de la renovación (UN crédito por solicitud):
            // conserva el vínculo de auditoría sin reutilizar la solicitud vieja.
            $request = CobrosCreditRequest::create([
                'company_id' => $company->id,
                'cobros_route_id' => $previous->cobros_route_id,
                'collector_user_id' => $locked->collector_user_id,
                'customer_id' => $customer->id,
                'status' => CobrosCreditRequest::STATUS_APPROVED,
                'identification_type' => 'cedula',
                'identification' => (string) $customer->identification,
                'name' => (string) $customer->name,
                'phone' => (string) ($customer->phone ?? $customer->mobile ?? ''),
                'address' => (string) ($customer->address ?? ''),
                'amount_requested' => $totalDue,
                'notes' => 'Renovación consolidada de crédito #'.$previous->id.' (solicitud #'.$locked->id.').',
                'submitted_at' => now(),
            ]);

            $account = AccountReceivable::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'cobros_route_id' => $previous->cobros_route_id,
                'customer_id' => $customer->id,
                'sale_id' => null,
                'issued_at' => today(),
                'due_date' => (string) $locked->first_due_date->toDateString(),
                'original_amount' => $totalDue,
                'balance_due' => $totalDue,
                'status' => AccountReceivable::STATUS_PENDING,
                'currency_code' => $company->currency,
            ]);

            $now = now();
            $credit = CobrosCredit::create([
                'company_id' => $company->id,
                'credit_request_id' => $request->id,
                'customer_id' => $customer->id,
                'collector_user_id' => $locked->collector_user_id,
                'cobros_route_id' => $previous->cobros_route_id,
                'account_receivable_id' => $account->id,
                // Capital consolidado = deuda anterior + capital nuevo.
                'principal' => $this->schedule->round4(bcadd($pendingNow, $newCapital, 4)),
                'interest_type' => CobrosCredit::INTEREST_FIXED,
                'interest_value' => $this->schedule->money((string) $locked->new_interest),
                'total_due' => $totalDue,
                'installment_count' => (int) $locked->installment_count,
                'frequency' => (string) $locked->frequency,
                'disbursement_date' => today()->toDateString(),
                'first_due_date' => (string) $locked->first_due_date->toDateString(),
                'late_fee_type' => CobrosCredit::LATE_FEE_NONE,
                'late_fee_value' => '0.0000',
                'late_fee_grace_days' => 0,
                'status' => CobrosCredit::STATUS_DISBURSED,
                'terms_snapshot' => [
                    'source' => 'renewal',
                    'renewal_request_id' => $locked->id,
                    'previous_credit_id' => $previous->id,
                    'previous_pending' => (string) $locked->previous_pending,
                    'previous_principal' => (string) $locked->previous_principal,
                    'previous_interest' => (string) $locked->previous_interest,
                    'new_capital' => (string) $newCapital,
                    'new_interest' => (string) $locked->new_interest,
                    'principal' => (string) $this->schedule->round4(bcadd($pendingNow, $newCapital, 4)),
                    'interest_type' => CobrosCredit::INTEREST_FIXED,
                    'interest_value' => (string) $this->schedule->money((string) $locked->new_interest),
                    'total_due' => (string) $totalDue,
                    'installment_count' => (int) $locked->installment_count,
                    'frequency' => (string) $locked->frequency,
                    'first_due_date' => (string) $locked->first_due_date->toDateString(),
                    'late_fee_type' => CobrosCredit::LATE_FEE_NONE,
                    'late_fee_value' => '0.0000',
                    'late_fee_grace_days' => 0,
                    'terms_hash' => (string) $locked->terms_hash,
                    'authorized_by' => (int) $actor->id,
                    'authorized_at' => $now->toDateTimeString(),
                ],
                'approved_at' => $now,
                'approved_by' => $actor->id,
                'disbursed_at' => $now,
                'disbursed_by' => $actor->id,
            ]);

            foreach ($rows as $row) {
                CobrosCreditInstallment::create([
                    'credit_id' => $credit->id,
                    'number' => $row['number'],
                    'due_date' => $row['due_date'],
                    'contractual_amount' => $row['contractual_amount'],
                    'applied_amount' => '0.0000',
                    'status' => CobrosCreditInstallment::STATUS_PENDING,
                ]);
            }

            $this->assignments->assign(
                $company,
                $customer,
                $collector,
                $actor,
                'Activación de renovación de crédito #'.$previous->id,
                (int) $previous->cobros_route_id,
            );

            // Crédito anterior: cerrado por renovación. Su CxC se cancela
            // (el gate deja de permitir cobros sobre ella). Sin pagos ficticios.
            $previousAccount = $previous->account()->lockForUpdate()->first();
            if ($previousAccount !== null) {
                $previousAccount->update(['status' => AccountReceivable::STATUS_CANCELLED]);
            }
            $previous->update(['status' => CobrosCredit::STATUS_CLOSED_RENEWAL]);

            $locked->update([
                'status' => CobrosCreditRenewalRequest::STATUS_ACTIVATED,
                'activated_credit_id' => $credit->id,
            ]);

            $this->log($locked, $request, $actor, 'Renovación activada. Crédito consolidado #'.$credit->id.' (desembolso de capital nuevo '.(string) $newCapital.').');
            $this->log($locked, $request, $actor, 'Crédito anterior #'.$previous->id.' cerrado por renovación.');

            return $credit->fresh();
        });
    }

    private function log(CobrosCreditRenewalRequest $renewal, CobrosCreditRequest $request, User $actor, string $notes): void
    {
        CobrosCreditRequestLog::create([
            'company_id' => $renewal->company_id,
            'credit_request_id' => $request->id,
            'actor_user_id' => $actor->id,
            'from_status' => CobrosCreditRequest::STATUS_APPROVED,
            'to_status' => CobrosCreditRequest::STATUS_APPROVED,
            'notes' => substr('[renovación #'.$renewal->id.'] '.$notes, 0, 255),
        ]);
    }
}
