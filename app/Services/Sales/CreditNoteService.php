<?php

namespace App\Services\Sales;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableAdjustment;
use App\Models\CompanySequence;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Núcleo de Notas de Crédito (Fase 1 + Fase 2B).
 *
 * Reglas permanentes:
 *  - Toda Nota de Crédito es nominativa: exige customer_id en la venta original.
 *  - 1 devolución = máximo 1 Nota de Crédito (sale_return_id UNIQUE).
 *  - Dinero exclusivamente con BCMath a escala 4; jamás floats.
 *  - La NC NO mueve inventario, NO modifica fidelización.
 *  - Invariante financiera: issued_amount = offset_amount + applied_amount + balance.
 *  - Fase 2B: la NC emitida sobre una venta con CxC se concilia
 *    automáticamente contra AccountReceivable cuando procede (misma empresa,
 *    cliente, venta, sucursal y moneda). El offset NO afecta caja ni pagos.
 *  - requires_ar_review es un semáforo transitorio: pasa a false tras la
 *    conciliación o cuando no existe AR / AR pagada / balance_due <= 0.
 *  - NC interna ≠ NC electrónica Hacienda: la integración fiscal será una
 *    entidad 1:1 separada.
 */
class CreditNoteService
{
    private const SCALE = 4;

    public function __construct(
        private readonly AccountsReceivableReconciliationService $reconciliationService,
    ) {}

    /**
     * Emite la Nota de Crédito derivada de una devolución.
     *
     * Idempotencia: una misma devolución produce siempre la misma NC;
     * un idempotency_key repetido por empresa también la reutiliza.
     *
     * Tras crear la NC, se intenta conciliación automática CxC dentro de la
     * misma unidad transaccional. Si existe AccountReceivable para la venta,
     * se determina el offset contra el saldo pendiente; de lo contrario
     * requires_ar_review queda en false. Si la conciliación falla la
     * transacción completa se revierte.
     */
    public function issueFromReturn(SaleReturn $saleReturn, User $user, ?string $idempotencyKey = null): CreditNote
    {
        return DB::transaction(function () use ($saleReturn, $user, $idempotencyKey): CreditNote {
            $saleReturn = SaleReturn::query()
                ->whereKey($saleReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($idempotencyKey !== null) {
                $byKey = CreditNote::query()
                    ->where('company_id', $saleReturn->company_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($byKey !== null) {
                    return $byKey;
                }
            }

            $existing = CreditNote::query()
                ->where('sale_return_id', $saleReturn->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $sale = Sale::query()
                ->whereKey($saleReturn->sale_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->customer_id === null) {
                throw ValidationException::withMessages([
                    'customer' => 'Una Nota de Crédito requiere un cliente identificado en la venta original.',
                ]);
            }

            $issuedAmount = '0';

            foreach ($saleReturn->items()->pluck('total') as $total) {
                $issuedAmount = bcadd($issuedAmount, (string) $total, self::SCALE);
            }

            if (bccomp($issuedAmount, '0', self::SCALE) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'La devolución no tiene importe acreditable para una Nota de Crédito.',
                ]);
            }

            $hasAr = AccountReceivable::query()
                ->where('company_id', $sale->company_id)
                ->where('sale_id', $sale->id)
                ->exists();

            $now = now();

            $creditNote = CreditNote::create([
                'company_id' => $saleReturn->company_id,
                'branch_id' => $saleReturn->branch_id,
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'sale_return_id' => $saleReturn->id,
                'credit_note_number' => CompanySequence::nextCreditNoteNumber(
                    (int) $saleReturn->company_id,
                ),
                'currency_code' => $sale->currency_code,
                'issued_amount' => $issuedAmount,
                'offset_amount' => '0',
                'applied_amount' => '0',
                'balance' => $issuedAmount,
                'status' => CreditNote::STATUS_ISSUED,
                'reason' => $saleReturn->reason,
                'issued_by' => $user->id,
                'issued_at' => $now,
                'idempotency_key' => $idempotencyKey,
                'requires_ar_review' => $hasAr,
            ]);

            if ($hasAr) {
                $this->reconciliationService->reconcile($creditNote, $user);
            } else {
                $creditNote->update(['requires_ar_review' => false]);
            }

            return $creditNote->fresh();
        });
    }

    /**
     * Notas de crédito disponibles (saldo > 0) para un cliente de una empresa.
     *
     * Orden FIFO por fecha de emisión. Exclusivamente NC con saldo disponible
     * y estado issued/partially_applied. La conciliación automática de Fase 2B
     * resuelve requires_ar_review al emitir la NC; el guard de applyToSale
     * permanece como defensa en profundidad.
     *
     * @return Collection<int, CreditNote>
     */
    public function availableForCustomer(int $companyId, int $customerId): Collection
    {
        return CreditNote::query()
            ->forCompany($companyId)
            ->forCustomer($customerId)
            ->available()
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Aplica un monto de la NC a una venta destino del mismo cliente y empresa.
     *
     * Idempotencia garantizada por (company_id, application_token) UNIQUE.
     * Fase 1: registra la aplicación a nivel dominio; NO modifica el saldo de
     * la venta destino ni la CxC de ninguna cuenta.
     *
     * Lock order: Sale → CreditNote (orden global aprobado).
     */
    public function applyToSale(
        CreditNote $creditNote,
        Sale $sale,
        string $amount,
        User $user,
        string $applicationToken,
        ?string $notes = null,
    ): CreditNoteApplication {
        $applicationToken = trim($applicationToken);

        if ($applicationToken === '') {
            throw ValidationException::withMessages([
                'application_token' => 'Se requiere un token de aplicación para idempotencia.',
            ]);
        }

        return DB::transaction(function () use ($creditNote, $sale, $amount, $user, $applicationToken, $notes): CreditNoteApplication {
            $existing = CreditNoteApplication::query()
                ->where('company_id', $creditNote->company_id)
                ->where('application_token', $applicationToken)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $target = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            $note = CreditNote::query()
                ->whereKey($creditNote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($note->status, [CreditNote::STATUS_VOIDED, CreditNote::STATUS_APPLIED], true)) {
                throw ValidationException::withMessages([
                    'credit_note' => 'La Nota de Crédito no tiene saldo aplicable.',
                ]);
            }

            if ($note->requires_ar_review) {
                throw ValidationException::withMessages([
                    'credit_note' => 'Esta Nota de Crédito proviene de una venta con cuenta por cobrar y requiere conciliación CxC antes de poder aplicarse.',
                ]);
            }

            $applied = bcadd((string) $amount, '0', self::SCALE);

            if (bccomp($applied, '0', self::SCALE) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto a aplicar debe ser mayor que cero.',
                ]);
            }

            if (bccomp($applied, (string) $note->balance, self::SCALE) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto supera el saldo disponible de la Nota de Crédito.',
                ]);
            }

            if ((int) $target->company_id !== (int) $note->company_id) {
                throw ValidationException::withMessages([
                    'sale' => 'La venta destino pertenece a otra empresa.',
                ]);
            }

            if ($target->customer_id === null || (int) $target->customer_id !== (int) $note->customer_id) {
                throw ValidationException::withMessages([
                    'customer' => 'La venta destino debe pertenecer al mismo cliente de la Nota de Crédito.',
                ]);
            }

            if ($target->currency_code !== $note->currency_code) {
                throw ValidationException::withMessages([
                    'sale' => 'La moneda de la venta destino no coincide con la Nota de Crédito.',
                ]);
            }

            if ($target->is_historical || ! in_array($target->status, [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
            ], true)) {
                throw ValidationException::withMessages([
                    'sale' => 'Solo se pueden aplicar Notas de Crédito a ventas completadas o parcialmente devueltas.',
                ]);
            }

            $now = now();
            $newBalance = bcsub((string) $note->balance, $applied, self::SCALE);
            $newApplied = bcadd((string) $note->applied_amount, $applied, self::SCALE);
            $fullyApplied = bccomp($newBalance, '0', self::SCALE) === 0;

            $application = CreditNoteApplication::create([
                'company_id' => $note->company_id,
                'credit_note_id' => $note->id,
                'sale_id' => $target->id,
                'customer_id' => $note->customer_id,
                'amount' => $applied,
                'application_token' => $applicationToken,
                'applied_by' => $user->id,
                'applied_at' => $now,
                'status' => CreditNoteApplication::STATUS_APPLIED,
                'notes' => $notes,
            ]);

            $note->update([
                'applied_amount' => $newApplied,
                'balance' => $newBalance,
                'status' => $fullyApplied
                    ? CreditNote::STATUS_APPLIED
                    : CreditNote::STATUS_PARTIALLY_APPLIED,
            ]);

            return $application;
        });
    }

    /**
     * Anula una Nota de Crédito jamás aplicada ni compensada.
     *
     * Solo es anulación directa si:
     *  - applied_amount == 0.0000
     *  - offset_amount == 0.0000
     *  - sin CreditNoteApplications activas
     *  - sin AccountReceivableAdjustments activos
     *
     * Una NC con aplicaciones, compensaciones o ajustes activos requiere
     * revertir primero; la reversión de ajustes pertenece a una fase posterior.
     *
     * Al anular: status voided, balance pasa a 0.0000 y se registran
     * voided_by/voided_at/void_reason. issued_amount y offset_amount se
     * conservan para auditoría.
     */
    public function void(CreditNote $creditNote, User $user, string $reason): CreditNote
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar el motivo de la anulación.',
            ]);
        }

        return DB::transaction(function () use ($creditNote, $user, $reason): CreditNote {
            $note = CreditNote::query()
                ->whereKey($creditNote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($note->status === CreditNote::STATUS_VOIDED) {
                throw ValidationException::withMessages([
                    'credit_note' => 'La Nota de Crédito ya está anulada.',
                ]);
            }

            $hasActiveApplications = CreditNoteApplication::query()
                ->where('credit_note_id', $note->id)
                ->where('status', CreditNoteApplication::STATUS_APPLIED)
                ->exists();

            $hasActiveAdjustments = AccountReceivableAdjustment::query()
                ->where('credit_note_id', $note->id)
                ->where('status', AccountReceivableAdjustment::STATUS_ACTIVE)
                ->exists();

            if (bccomp((string) $note->applied_amount, '0', self::SCALE) > 0 || $hasActiveApplications || bccomp((string) $note->offset_amount, '0', self::SCALE) > 0 || $hasActiveAdjustments) {
                throw ValidationException::withMessages([
                    'credit_note' => 'La Nota de Crédito tiene aplicaciones o compensaciones CxC registradas: deben revertirse primero antes de anularla.',
                ]);
            }

            $note->update([
                'status' => CreditNote::STATUS_VOIDED,
                'balance' => '0',
                'voided_by' => $user->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $note->fresh();
        });
    }
}
