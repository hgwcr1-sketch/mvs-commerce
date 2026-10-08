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
use App\Services\RouteosAuditService;
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
        private readonly RouteosAuditService $audit,
        private readonly CobrosAlertService $alerts,
        private readonly CreditInstallmentService $installments,
    ) {}

    /**
     * Registra un cobro ya confirmado por el usuario.
     *
     * @param  array{amount?: mixed, payment_method_id?: mixed, reference?: mixed, notes?: mixed, client_token?: mixed, visit_id?: mixed, tendered?: mixed}  $payload
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
        // 9B.3: el tope es saldo del préstamo (capital) + mora exigible pendiente.
        $chargeable = bcadd((string) $account->balance_due, $this->installments->pendingLateFee($company, $account), 4);
        if (bccomp($amount, $chargeable, 4) > 0) {
            throw ValidationException::withMessages(['amount' => 'El abono no puede superar el saldo pendiente.']);
        }
        // 9B.1: si la CxC es de un préstamo, el pago no supera la cuota más antigua.
        $this->installments->assertChargeable($company, $account, $amount);

        $method = $this->method($company, $payload['payment_method_id'] ?? null);
        $reference = $this->text($payload['reference'] ?? null, 150);
        if ($method->requires_reference && $reference === null) {
            throw ValidationException::withMessages(['reference' => "La referencia es obligatoria para {$method->name}."]);
        }
        $notes = $this->text($payload['notes'] ?? null, 500);
        // Informativo (recibo formal): efectivo recibido/vuelto. No modifica pagos ni CxC.
        $tendered = $this->tendered($payload['tendered'] ?? null);
        if ($tendered !== null && bccomp($tendered, $amount, 4) < 0) {
            throw ValidationException::withMessages(['tendered' => 'El efectivo recibido no puede ser menor al monto cobrado.']);
        }
        $visit = $this->visit($company, $collector, $account, $payload['visit_id'] ?? null);

        try {
            $result = DB::transaction(function () use ($collector, $company, $account, $token, $fingerprint, $amount, $method, $reference, $notes, $visit, $tendered) {
                // Releer autoridad y saldo después de adquirir los locks. Los UPDATE/DELETE
                // de revocación esperan al commit; si ganaron antes, el Gate los observa.
                [$collector, $company, $account] = $this->lockPaymentContext($collector, $company, $account);
                // Otro intento pudo confirmar mientras esperábamos el lock de CxC.
                if ($replay = $this->replayOrConflict($company, $collector, $token, $fingerprint)) {
                    return $replay;
                }
                $this->gate->assertCanStart($collector, $company, $account);

                // Saldo del recibo formal: instantánea del momento del cobro.
                // 9B.3: la mora se cobra PRIMERO y nunca toca la CxC; el saldo
                // del préstamo solo baja por la porción aplicada a cuotas.
                $balanceBefore = bcadd((string) $account->balance_due, '0', 4);
                $moraPending = $this->installments->pendingLateFee($company, $account);
                $moraPortion = bccomp($amount, $moraPending, 4) < 0 ? $amount : $moraPending;
                $loanPortion = bcsub($amount, $moraPortion, 4);
                $balanceAfter = bccomp($balanceBefore, $loanPortion, 4) >= 0
                    ? bcsub($balanceBefore, $loanPortion, 4)
                    : '0.0000';

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
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'tendered_amount' => $tendered,
                    'change_amount' => $tendered !== null ? bcsub($tendered, $amount, 4) : null,
                    'currency_code' => $account->currency_code,
                    'reference' => $reference,
                    'client_token' => $token,
                    'payload_hash' => $fingerprint,
                    'paid_at' => now(),
                ]);

                $payment = bccomp($loanPortion, '0', 4) > 0
                    ? $this->accounts->payInCustody($account, [
                        'amount' => $loanPortion,
                        'payment_method_id' => $method->id,
                        'reference' => $reference,
                        'notes' => $notes,
                    ], $collector, (int) $company->id, (int) $account->branch_id)
                    : $this->zeroLoanPayment($account, $method, $reference, $notes, $collector, $company);

                $receipt->update([
                    'payment_id' => $payment->id,
                    'receipt_number' => CompanySequence::nextCobrosReceiptNumber((int) $company->id),
                ]);

                // 9B.3: la mora exigible se cobra PRIMERO (snapshot inmutable),
                // y SOLO después se aplican las cuotas con la porción restante.
                if (bccomp($moraPortion, '0', 4) > 0) {
                    $this->installments->applyLateFee($company, $account, $receipt, $collector, $moraPortion);
                }
                // 9B.1/9B.2A: aplica a la cuota más antigua dentro de la MISMA
                // transacción (pago + mora + cuota + CxC + recibo consistentes).
                $this->installments->apply($company, $account, $loanPortion, $receipt);

                $this->audit->log(
                    (int) $company->id,
                    (int) $account->branch_id,
                    'cobros.payment.registered',
                    $payment,
                    null,
                    ['balance_due' => (string) $account->fresh()->balance_due, 'amount' => $amount, 'late_fee' => $moraPortion, 'applied_to_loan' => $loanPortion],
                    [
                        'receipt_number' => $receipt->receipt_number,
                        'payment_id' => $payment->id,
                        'account_receivable_id' => $account->id,
                        'customer_id' => $account->customer_id,
                        'payment_method_id' => $method->id,
                        'amount' => $amount,
                        'visit_id' => $visit?->id,
                    ],
                    $collector
                );

                return ['payment' => $payment, 'receipt' => $receipt->fresh(), 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Otro intento con el mismo token ganó la carrera.
            if ($replay = $this->replayOrConflict($company, $collector, $token, $fingerprint)) {
                return $replay;
            }
            abort(409, 'Este intento de cobro ya fue registrado con otros datos.');
        }

        // Fase 5A: alerta al dueño/supervisor después del commit. Si la
        // notificación falla, el cobro ya aplicado no se ve afectado.
        if (! $result['replayed']) {
            $this->alerts->paymentRegistered($company, $account, $result['payment'], $result['receipt']);
        }

        return $result;
    }

    /**
     * Cobro 100% de mora: la porción aplicada al préstamo es 0, así que no
     * hay reducción de CxC, pero el recibo sigue apuntando a un pago real
     * (monto 0.0000 = capital aplicado) para trazabilidad e idempotencia.
     */
    private function zeroLoanPayment(AccountReceivable $account, PaymentMethod $method, ?string $reference, ?string $notes, User $collector, Company $company): AccountReceivablePayment
    {
        return AccountReceivablePayment::create([
            'account_receivable_id' => $account->id,
            'company_id' => (int) $company->id,
            'branch_id' => (int) $account->branch_id,
            'customer_id' => $account->customer_id,
            'user_id' => $collector->id,
            'cash_session_id' => null,
            'payment_method_id' => $method->id,
            'amount' => '0.0000',
            'affects_cash_snapshot' => false,
            'cash_effect_amount' => '0.0000',
            'reference' => $reference,
            'notes' => $notes,
            'paid_at' => now(),
        ]);
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

    /**
     * Solo coordinación: las reglas siguen en Gate/CxC. Mantener los locks hasta
     * terminar pago + saldo + recibo. Shared locks permiten cobros independientes
     * y bloquean revocaciones incluso desde escrituras fuera de CobrOS.
     * SQLite serializa escritores; PostgreSQL usa locks reales por fila.
     *
     * @return array{User, Company, AccountReceivable}
     */
    private function lockPaymentContext(User $collector, Company $company, AccountReceivable $account): array
    {
        $company = Company::query()->whereKey($company->id)->sharedLock()->firstOrFail();
        $collector = User::query()->whereKey($collector->id)->sharedLock()->firstOrFail();
        $membership = DB::table('company_user')->where('company_id', $company->id)
            ->where('user_id', $collector->id)->sharedLock()->first();
        DB::table('roles')->where('id', $membership?->role_id)->sharedLock()->get();
        $permissionIds = DB::table('permission_role')->where('role_id', $membership?->role_id)
            ->orderBy('permission_id')->sharedLock()->pluck('permission_id');
        DB::table('permissions')->whereIn('id', $permissionIds)->orderBy('id')->sharedLock()->get();
        $company->license()->sharedLock()->get();
        $company->modules()->where('module_key', 'cobros')->sharedLock()->get();
        DB::table('cobros_collector_licenses')->where('company_id', $company->id)
            ->where('user_id', $collector->id)->sharedLock()->get();

        $account = AccountReceivable::query()->forCompany($company->id)
            ->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $account->setRelation('customer', $account->customer()->sharedLock()->first());
        DB::table('cobros_assignments')->where('company_id', $company->id)
            ->where('customer_id', $account->customer_id)->sharedLock()->get();
        DB::table('branches')->where('id', $account->branch_id)->sharedLock()->get();
        DB::table('branch_user')->where('branch_id', $account->branch_id)
            ->where('user_id', $collector->id)->sharedLock()->get();

        return [$collector, $company, $account];
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

    /** Efectivo recibido ("paga con"): opcional, solo para el recibo formal. */
    private function tendered(mixed $value): ?string
    {
        $value = is_string($value) || is_int($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{1,12}(\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['tendered' => 'Ingrese el efectivo recibido con hasta cuatro decimales.']);
        }

        return bcadd($value, '0', 4);
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
