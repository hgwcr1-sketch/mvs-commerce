<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Sales\AccountsReceivableService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fase 3B — capa segura de cobro de CobrOS.
 *
 * Envuelve AccountsReceivableService (CxC) y el Gate de 3A:
 * - mismo token + mismo payload => SIEMPRE el mismo pago y el mismo recibo;
 * - mismo token + payload distinto => 409, nunca un segundo pago;
 * - sin sesión de caja: el dinero queda en custodia del cobrador
 *   (affects_cash_snapshot=false, cash_effect_amount=0) hasta liquidación.
 *
 * Autoridad SIEMPRE: sesión (empresa activa) + auth()->id(). El request solo
 * aporta monto, método, referencia y notas.
 */
class SecurePaymentService
{
    public function __construct(
        private readonly CobrosPaymentGate $gate,
        private readonly AccountsReceivableService $accounts,
    ) {}

    /**
     * Registra un cobro ya confirmado por el usuario.
     *
     * @param  array{amount?: mixed, payment_method_id?: mixed, reference?: mixed, notes?: mixed, client_token?: mixed, visit_id?: mixed}  $payload
     * @return array{payment: AccountReceivablePayment, receipt: CobrosReceipt, replayed: bool}
     */
    public function register(User $collector, Company $company, AccountReceivable $account, array $payload): array
    {
        $token = $this->token($payload['client_token'] ?? null);
        $fingerprint = $this->fingerprint($company, $collector, $account, $payload);

        // Reintento ANTES de cualquier otra validación: mismo token + mismo
        // payload devuelve el mismo pago aunque la cuenta ya haya cambiado.
        if ($replay = $this->replayOrConflict($company, $collector, $token, $fingerprint)) {
            return $replay;
        }

        // Gate 3A re-evaluado en servidor: sin él no se escribe dinero.
        $this->gate->assertCanStart($collector, $company, $account->loadMissing(['customer', 'branch']));

        $amount = $this->amount($payload['amount'] ?? null);
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => 'El abono debe ser positivo.']);
        }
        if (bccomp($amount, (string) $account->balance_due, 4) > 0) {
            throw ValidationException::withMessages(['amount' => 'El abono no puede superar el saldo pendiente.']);
        }

        $method = $this->method($company, $payload['payment_method_id'] ?? null);
        $reference = $this->text($payload['reference'] ?? null, 150);
        if ($method->requires_reference && $reference === null) {
            throw ValidationException::withMessages(['reference' => "La referencia es obligatoria para {$method->name}."]);
        }
        $notes = $this->text($payload['notes'] ?? null, 500);
        $visit = $this->visit($company, $collector, $account, $payload['visit_id'] ?? null);

        try {
            return DB::transaction(function () use ($collector, $company, $account, $token, $fingerprint, $amount, $method, $reference, $notes, $visit) {
                // Reserva del token primero: la restricción única por empresa
                // es la garantía real contra doble cobro concurrente.
                $receipt = CobrosReceipt::create([
                    'company_id' => $company->id,
                    'branch_id' => $account->branch_id,
                    'customer_id' => $account->customer_id,
                    'account_receivable_id' => $account->id,
                    'collector_user_id' => $collector->id,
                    'payment_method_id' => $method->id,
                    'cobros_visit_id' => $visit?->id,
                    'amount' => $amount,
                    'currency_code' => $account->currency_code,
                    'reference' => $reference,
                    'client_token' => $token,
                    'payload_hash' => $fingerprint,
                    'paid_at' => now(),
                ]);

                $payment = $this->accounts->payInCustody($account, [
                    'amount' => $amount,
                    'payment_method_id' => $method->id,
                    'reference' => $reference,
                    'notes' => $notes,
                ], $collector, (int) $company->id, (int) $account->branch_id);

                $receipt->update([
                    'payment_id' => $payment->id,
                    'receipt_number' => CompanySequence::nextCobrosReceiptNumber((int) $company->id),
                ]);

                return ['payment' => $payment, 'receipt' => $receipt->fresh(), 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Otro intento con el mismo token ganó la carrera.
            if ($replay = $this->replayOrConflict($company, $collector, $token, $fingerprint)) {
                return $replay;
            }
            abort(409, 'Este intento de cobro ya fue registrado con otros datos.');
        }
    }

    /**
     * Mismo token + mismo payload => mismo pago (idéntico). Distinto => 409.
     *
     * @return array{payment: AccountReceivablePayment, receipt: CobrosReceipt, replayed: bool}|null
     */
    private function replayOrConflict(Company $company, User $collector, string $token, string $fingerprint): ?array
    {
        $receipt = CobrosReceipt::query()->forCompany((int) $company->id)
            ->where('client_token', $token)->first();

        if (! $receipt) {
            return null;
        }

        if ($receipt->payload_hash !== $fingerprint || (int) $receipt->collector_user_id !== (int) $collector->id) {
            abort(409, 'Este intento de cobro ya fue registrado con otros datos.');
        }

        return [
            'payment' => $receipt->payment()->firstOrFail(),
            'receipt' => $receipt,
            'replayed' => true,
        ];
    }

    private function token(mixed $token): string
    {
        $token = is_string($token) ? trim($token) : '';
        if ($token === '' || ! preg_match('/^[A-Za-z0-9-]{16,64}$/', $token)) {
            throw ValidationException::withMessages(['client_token' => 'La clave de intento de cobro no es válida.']);
        }

        return $token;
    }

    /** Monto exacto con BCMath: nunca float y nunca más de 4 decimales. */
    private function amount(mixed $amount): string
    {
        $amount = is_string($amount) || is_int($amount) ? trim((string) $amount) : '';
        if (! preg_match('/^\d{1,12}(\.\d{1,4})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Ingrese un monto válido con hasta cuatro decimales.']);
        }

        return bcadd($amount, '0', 4);
    }

    private function method(Company $company, mixed $methodId): PaymentMethod
    {
        $method = is_numeric($methodId)
            ? PaymentMethod::query()->forCompany((int) $company->id)->active()->find((int) $methodId)
            : null;

        if (! $method || in_array($method->type, [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS], true)) {
            throw ValidationException::withMessages(['payment_method_id' => 'La forma de pago no está disponible para abonos.']);
        }

        return $method;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    private function visit(Company $company, User $collector, AccountReceivable $account, mixed $visitId): ?CobrosVisit
    {
        if ($visitId === null || $visitId === '' || $visitId === '0') {
            return null;
        }
        if (! is_numeric($visitId)) {
            abort(404);
        }

        $visit = CobrosVisit::query()->forCompany((int) $company->id)
            ->forCollector($collector->id)
            ->where('customer_id', $account->customer_id)
            ->find((int) $visitId);
        if (! $visit) {
            abort(404);
        }
        if ($visit->account_receivable_id !== null && (int) $visit->account_receivable_id !== (int) $account->id) {
            abort(404);
        }

        return $visit;
    }

    /** Huella estable del intento: cuenta, cobrador, monto, método, referencia y notas. */
    private function fingerprint(Company $company, User $collector, AccountReceivable $account, array $payload): string
    {
        $amount = is_scalar($payload['amount'] ?? null) ? trim((string) $payload['amount']) : '';
        if (preg_match('/^\d{1,12}(\.\d{1,4})?$/', $amount)) {
            $amount = bcadd($amount, '0', 4);
        }
        $normalize = static fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== ''
            ? mb_substr(trim((string) $value), 0, 500)
            : null;

        return hash('sha256', json_encode([
            'company' => (int) $company->id,
            'collector' => (int) $collector->id,
            'account' => (int) $account->id,
            'amount' => $amount,
            'method' => is_numeric($payload['payment_method_id'] ?? null) ? (int) $payload['payment_method_id'] : null,
            'reference' => $normalize($payload['reference'] ?? null),
            'notes' => $normalize($payload['notes'] ?? null),
        ], JSON_THROW_ON_ERROR));
    }
}
