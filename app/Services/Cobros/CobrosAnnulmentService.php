<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\User;
use App\Services\RouteosAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fase 3C — anulación segura de un cobro CobrOS.
 *
 * Reglas inviolables:
 * - el pago original NUNCA se borra ni se modifica: se crea un reverso
 *   auditable con número único por empresa (RVC-) y actor identificado;
 * - UNIQUE(payment_id) + revisión transaccional bloquean dobles anulaciones;
 * - idempotencia: mismo token + mismo payload devuelve el mismo reverso,
 *   payload distinto con el mismo token → 409;
 * - empresa/sucursal y bloqueo (lockForUpdate) protegen saldo y concurrencia;
 * - la auditoría se registra con RouteosAuditService (reutilizado) y nunca
 *   incluye tokens;
 * - solo toca cobros CobrOS: un pago con sesión de caja Commerce se rechaza.
 */
class CobrosAnnulmentService
{
    public function __construct(private readonly RouteosAuditService $audit) {}

    /**
     * @param  array{reason?: mixed, client_token?: mixed}  $payload
     * @return array{reversal: CobrosPaymentReversal, replayed: bool}
     */
    public function annul(User $actor, Company $company, CobrosReceipt $receipt, array $payload): array
    {
        $token = $this->token($payload['client_token'] ?? null);
        $reason = $this->reason($payload['reason'] ?? null);
        $fingerprint = $this->fingerprint($company, $actor, $receipt, $reason);

        // Reintento primero: mismo token + mismo payload => mismo reverso.
        if ($replay = $this->replayOrConflict($company, $actor, $token, $fingerprint)) {
            return $replay;
        }

        if ($receipt->payment_id === null) {
            abort(409, 'Este recibo todavía no tiene un pago asociado.');
        }

        try {
            return DB::transaction(function () use ($actor, $company, $receipt, $token, $fingerprint, $reason) {
                // 1. Ninguna segunda anulación del mismo pago (también protegida por UNIQUE).
                $already = CobrosPaymentReversal::query()->forCompany((int) $company->id)
                    ->where('payment_id', $receipt->payment_id)->lockForUpdate()->first();
                if ($already) {
                    abort(409, 'Este cobro ya fue anulado.');
                }

                // 2. Pago original bloqueado: solo lectura, jamás UPDATE/DELETE.
                $payment = AccountReceivablePayment::query()
                    ->where('company_id', $company->id)->whereKey($receipt->payment_id)
                    ->lockForUpdate()->first();
                abort_unless($payment, 404);
                if ($payment->cash_session_id !== null) {
                    abort(409, 'Este pago pertenece a una sesión de caja y no se anula desde CobrOS.');
                }

                // 3. Cuenta bloqueada dentro de empresa/sucursal: saldo con BCMath.
                $account = AccountReceivable::query()
                    ->forCompany((int) $company->id)->forBranch((int) $payment->branch_id)
                    ->whereKey($payment->account_receivable_id)->lockForUpdate()->firstOrFail();

                $before = bcadd((string) $account->balance_due, '0', 4);
                $amount = bcadd((string) $payment->amount, '0', 4);
                $after = bcadd($before, $amount, 4);
                if (bccomp($after, (string) $account->original_amount, 4) > 0) {
                    $after = bcadd((string) $account->original_amount, '0', 4);
                }
                if (bccomp($after, '0', 4) <= 0) {
                    abort(409, 'No se puede anular este cobro: saldo inconsistente.');
                }

                $overdue = $account->due_date !== null && $account->due_date->isBefore(today());
                $status = $overdue
                    ? AccountReceivable::STATUS_OVERDUE
                    : (bccomp($after, (string) $account->original_amount, 4) >= 0
                        ? AccountReceivable::STATUS_PENDING
                        : AccountReceivable::STATUS_PARTIAL);

                $account->update(['balance_due' => $after, 'status' => $status]);

                $reversal = CobrosPaymentReversal::create([
                    'company_id' => $company->id,
                    'branch_id' => $payment->branch_id,
                    'receipt_id' => $receipt->id,
                    'payment_id' => $payment->id,
                    'account_receivable_id' => $account->id,
                    'collector_user_id' => $receipt->collector_user_id,
                    'annulled_by_user_id' => $actor->id,
                    'reversal_number' => CompanySequence::nextCobrosReversalNumber((int) $company->id),
                    'amount' => $amount,
                    'currency_code' => $receipt->currency_code,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reason' => $reason,
                    'client_token' => $token,
                    'payload_hash' => $fingerprint,
                    'annulled_at' => now(),
                ]);

                // El recibo muestra el estado; el pago original queda intacto.
                $receipt->update([
                    'annulled_at' => now(),
                    'annulled_by_user_id' => $actor->id,
                    'annul_reason' => $reason,
                ]);

                // Auditoría inmutable (sin tokens) con actor, empresa y sucursal.
                $this->audit->log(
                    (int) $company->id,
                    (int) $payment->branch_id,
                    'cobros.payment.annulled',
                    $reversal,
                    ['balance_due' => $before, 'status' => $account->getRawOriginal('status')],
                    ['balance_due' => $after, 'status' => $status],
                    [
                        'receipt_number' => $receipt->receipt_number,
                        'reversal_number' => $reversal->reversal_number,
                        'payment_id' => $payment->id,
                        'account_receivable_id' => $account->id,
                        'customer_id' => $account->customer_id,
                        'amount' => $amount,
                        'reason' => $reason,
                    ],
                    $actor
                );

                return ['reversal' => $reversal, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Carrera: otro proceso anuló primero con el mismo token o pago.
            if ($replay = $this->replayOrConflict($company, $actor, $token, $fingerprint)) {
                return $replay;
            }
            abort(409, 'Este cobro ya fue anulado.');
        }
    }

    /**
     * @return array{reversal: CobrosPaymentReversal, replayed: bool}|null
     */
    private function replayOrConflict(Company $company, User $actor, string $token, string $fingerprint): ?array
    {
        $existing = CobrosPaymentReversal::query()->forCompany((int) $company->id)
            ->where('client_token', $token)->first();

        if (! $existing) {
            return null;
        }
        if ($existing->payload_hash !== $fingerprint || (int) $existing->annulled_by_user_id !== (int) $actor->id) {
            abort(409, 'Este intento de anulación ya fue registrado con otros datos.');
        }

        return ['reversal' => $existing, 'replayed' => true];
    }

    private function token(mixed $token): string
    {
        $token = is_string($token) ? trim($token) : '';
        if ($token === '' || ! preg_match('/^[A-Za-z0-9-]{16,64}$/', $token)) {
            throw ValidationException::withMessages(['client_token' => 'La clave de intento de anulación no es válida.']);
        }

        return $token;
    }

    private function reason(mixed $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($reason) < 8) {
            throw ValidationException::withMessages(['reason' => 'Indique el motivo de la anulación (mínimo 8 caracteres).']);
        }

        return mb_substr($reason, 0, 250);
    }

    private function fingerprint(Company $company, User $actor, CobrosReceipt $receipt, string $reason): string
    {
        return hash('sha256', json_encode([
            'company' => (int) $company->id,
            'actor' => (int) $actor->id,
            'receipt' => (int) $receipt->id,
            'payment' => (int) ($receipt->payment_id ?? 0),
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }
}
