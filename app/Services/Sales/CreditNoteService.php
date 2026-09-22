<?php

namespace App\Services\Sales;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableAdjustment;
use App\Models\CompanySequence;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\CreditNoteCodeRotation;
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
 *  - La NC es nominativa: exige customer_id en la venta original, excepto
 *    NC Consumer Final (venta sin cliente identificado) emitida ÚNICAMENTE
 *    desde una devolución y solo si la empresa tiene el toggle
 *    credit_note_consumer_final = true.
 *  - 1 devolución = máximo 1 Nota de Crédito (sale_return_id UNIQUE).
 *  - Dinero exclusivamente con BCMath a escala 4; jamás floats.
 *  - La NC NO mueve inventario, NO modifica fidelización.
 *  - Invariante financiera: issued_amount = offset_amount + applied_amount + balance.
 *  - Fase 2B: la NC emitida sobre una venta con CxC se concilia
 *    automáticamente contra AccountReceivable cuando procede (misma empresa,
 *    cliente, venta, sucursal y moneda). El offset NO afecta caja ni pagos.
 *  - Fase 4A: la vigencia se calcula UNA VEZ al emitir según la política de
 *    la empresa (ncExpirationDays). expires_at null = sin vencimiento. Una NC
 *    vencida conserva saldo/historial pero no puede aplicarse (ni en
 *    applyToSale ni en applyBatchToSale) y no aparece como disponible.
 *  - La reversión de una aplicación NO valida vencimiento: restaura saldo y
 *    estado monetario; la NC permanece vencida e indisponible.
 *  - requires_ar_review es un semáforo transitorio: pasa a false tras la
 *    conciliación o cuando no existe AR / AR pagada / balance_due <= 0.
 *  - NC interna ≠ NC electrónica Hacienda: la integración fiscal será una
 *    entidad 1:1 separada.
 */
class CreditNoteService
{
    private const SCALE = 4;

    /**
     * Alfabeto de códigos de aplicación sin caracteres ambiguos.
     *
     * Excluye 0, O, 1, I y L. Con ASCII alfanumérico el conjunto máximo
     * tras esas exclusiones es de 31 símbolos (23 letras + 8 dígitos);
     * cada código de 12 caracteres ofrece ≈ 2^59.45 de entropía.
     */
    public const APPLICATION_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const APPLICATION_CODE_LENGTH = 12;

    public function __construct(
        private readonly AccountsReceivableReconciliationService $reconciliationService,
    ) {}

    /**
     * Normaliza un código de aplicación: trim, mayúsculas, sin guiones.
     */
    public static function normalizeApplicationCode(string $code): string
    {
        return strtoupper(str_replace('-', '', trim($code)));
    }

    /**
     * SHA-256 del código normalizado. Única representación persistida.
     */
    public static function applicationCodeHash(string $code): string
    {
        return hash('sha256', self::normalizeApplicationCode($code));
    }

    /**
     * Genera un código de aplicación secreto de 12 caracteres con CSPRNG.
     *
     * Formato visible XXXX-XXXX-XXXX. Distribución uniforme vía random_int
     * (sin sesgo modular). No deriva de timestamps, números ni datos
     * predecibles de la NC, la venta o la devolución.
     */
    public function generateApplicationCode(): string
    {
        $alphabet = self::APPLICATION_CODE_ALPHABET;
        $maxIndex = strlen($alphabet) - 1;
        $chars = [];

        for ($i = 0; $i < self::APPLICATION_CODE_LENGTH; $i++) {
            $chars[] = $alphabet[random_int(0, $maxIndex)];
        }

        return substr(implode('', $chars), 0, 4).'-'
            .substr(implode('', $chars), 4, 4).'-'
            .substr(implode('', $chars), 8, 4);
    }

    /**
     * Emite la Nota de Crédito derivada de una devolución.
     *
     * Wrapper retrocompatible: devuelve únicamente la CreditNote.
     * Para transportar el código de aplicación Consumer Final (una sola
     * entrega) usar issueFromReturnWithResult().
     *
     * Idempotencia: una misma devolución produce siempre la misma NC;
     * un idempotency_key repetido por empresa también la reutiliza.
     */
    public function issueFromReturn(SaleReturn $saleReturn, User $user, ?string $idempotencyKey = null): CreditNote
    {
        return $this->issueFromReturnWithResult($saleReturn, $user, $idempotencyKey)->creditNote;
    }

    /**
     * Emite la Nota de Crédito derivada de una devolución y transporta
     * temporalmente el código de aplicación en texto plano.
     *
     * - NC nominativa (customer_id != null): flujo existente,
     *   application_code_hash null, sin código.
     * - NC Consumer Final (customer_id == null): permitida ÚNICAMENTE si la
     *   empresa tiene credit_note_consumer_final = true. Genera un código
     *   secreto de 12 caracteres, persiste solo su SHA-256 y lo entrega como
     *   resultado temporal para la respuesta inicial.
     *
     * Idempotencia: una misma devolución produce siempre la misma NC;
     * re-emisiones no regeneran ni revelan el código.
     *
     * Tras crear la NC, se intenta conciliación automática CxC dentro de la
     * misma unidad transaccional. Si existe AccountReceivable para la venta,
     * se determina el offset contra el saldo pendiente; de lo contrario
     * requires_ar_review queda en false. Si la conciliación falla la
     * transacción completa se revierte.
     */
    public function issueFromReturnWithResult(SaleReturn $saleReturn, User $user, ?string $idempotencyKey = null): IssuedCreditNoteResult
    {
        return DB::transaction(function () use ($saleReturn, $user, $idempotencyKey): IssuedCreditNoteResult {
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
                    return new IssuedCreditNoteResult($byKey, null);
                }
            }

            $existing = CreditNote::query()
                ->where('sale_return_id', $saleReturn->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return new IssuedCreditNoteResult($existing, null);
            }

            $sale = Sale::query()
                ->whereKey($saleReturn->sale_id)
                ->lockForUpdate()
                ->firstOrFail();

            $isConsumerFinal = $sale->customer_id === null;

            if ($isConsumerFinal) {
                $company = $sale->company;
                if ($company === null || ! $company->consumerFinalCreditNotesEnabled()) {
                    throw ValidationException::withMessages([
                        'customer' => 'Una Nota de Crédito requiere un cliente identificado en la venta original.',
                    ]);
                }
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

            if ($isConsumerFinal) {
                // Consumer Final no tiene cuenta por cobrar de cliente.
                $hasAr = false;
            }

            $now = now();

            $expiresAt = null;
            $expirationDays = $sale->company?->ncExpirationDays();
            if ($expirationDays !== null && $expirationDays > 0) {
                $expiresAt = $now->copy()->addDays($expirationDays);
            }

            $applicationCode = null;
            $applicationCodeHash = null;

            if ($isConsumerFinal) {
                $applicationCode = $this->generateApplicationCode();
                $applicationCodeHash = self::applicationCodeHash($applicationCode);
            }

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
                'expires_at' => $expiresAt,
                'application_code_hash' => $applicationCodeHash,
                'idempotency_key' => $idempotencyKey,
                'requires_ar_review' => $hasAr,
            ]);

            if ($hasAr) {
                $this->reconciliationService->reconcile($creditNote, $user);
            } else {
                $creditNote->update(['requires_ar_review' => false]);
            }

            return new IssuedCreditNoteResult($creditNote->fresh(), $applicationCode);
        });
    }

    /**
     * Error genérico de NO autorización para NC Consumer Final por portador.
     *
     * No revela si el número existe, si el código es incorrecto, si la NC
     * pertenece a otra empresa, si es nominativa, si está vencida o si el
     * hash falta. Mismo mensaje para todos los fallos de autorización.
     */
    public const BEARER_GENERIC_ERROR = 'No se pudo validar la nota de crédito.';

    /**
     * Autoriza una NC Consumer Final por número + código secreto + monto.
     *
     * Es una PREvalidación sin locks: sirve para resolver de forma segura el
     * credit_note_id canónico antes del checkout. La validación definitiva
     * (saldo, estado, vencimiento, toggle, empresa) se revalida SIEMPRE dentro
     * de la transacción de checkout bajo lock (applyBatchToSale).
     *
     * Reglas:
     *  - lookup estricto por company_id + credit_note_number (multitenancy).
     *  - solo NC Consumer Final (customer_id null, application_code_hash presente).
     *  - comparación constant-time hash_equals del código normalizado.
     *  - empresa debe tener credit_note_consumer_final activo (toggle OFF = rechazo).
     *  - estado aplicable, saldo > 0, vigente (expires_at null o futuro).
     *  - monto > 0 y <= saldo disponible.
     *
     * Toda falla devuelve el error genérico; jamás el hash ni el código.
     */
    public function authorizeBearerApplication(
        int $companyId,
        string $creditNoteNumber,
        string $applicationCode,
        string $amount,
    ): CreditNote {
        $candidateHash = self::applicationCodeHash($applicationCode);
        $dummyHash = str_repeat('f', 64);
        $requested = bcadd((string) $amount, '0', self::SCALE);

        $note = CreditNote::query()
            ->where('company_id', $companyId)
            ->where('credit_note_number', trim($creditNoteNumber))
            ->first();

        if ($note === null || $note->isConsumerFinal() === false || $note->application_code_hash === null) {
            hash_equals($dummyHash, $candidateHash);
            throw ValidationException::withMessages([
                'credit_note_bearer_applications' => self::BEARER_GENERIC_ERROR,
            ]);
        }

        $company = $note->company;

        if (! hash_equals($note->application_code_hash, $candidateHash)) {
            throw ValidationException::withMessages([
                'credit_note_bearer_applications' => self::BEARER_GENERIC_ERROR,
            ]);
        }

        if ($company === null || ! $company->consumerFinalCreditNotesEnabled()) {
            throw ValidationException::withMessages([
                'credit_note_bearer_applications' => self::BEARER_GENERIC_ERROR,
            ]);
        }

        if (in_array($note->status, [CreditNote::STATUS_VOIDED, CreditNote::STATUS_APPLIED], true)
            || bccomp((string) $note->balance, '0', self::SCALE) <= 0
            || $note->isExpired()
            || bccomp($requested, '0', self::SCALE) <= 0
            || bccomp($requested, (string) $note->balance, self::SCALE) > 0) {
            throw ValidationException::withMessages([
                'credit_note_bearer_applications' => self::BEARER_GENERIC_ERROR,
            ]);
        }

        return $note;
    }

    /**
     * Regenera el código secreto de una NC Consumer Final (Fase 4B-4).
     *
     * Solo NC Consumer Final (customer_id null) con application_code_hash
     * emitido pueden regenerar. La operación:
     *  - localiza la NC EXCLUSIVAMENTE dentro de company_id (multitenancy),
     *  - la bloquea con lockForUpdate,
     *  - valida elegibilidad (vigente, no voided, no applied, balance > 0),
     *  - genera un NUEVO código con EXACTAMENTE el generador certificado 4B-1,
     *  - normaliza y persiste únicamente su SHA-256 reemplazando el hash anterior
     *    (el código anterior queda inválido inmediatamente tras el commit),
     *  - registra auditoría en credit_note_code_rotations sin secretos,
     *  - devuelve el plaintext nuevo SOLO temporalmente para la entrega única.
     *
     * NO modifica saldo, montos, vencimiento, estado monetario, número, cliente
     * ni historial de aplicaciones. NO crea CreditNoteApplication, SalePayment
     * ni CashMovement.
     *
     * @return array{credit_note: CreditNote, application_code: string}
     */
    public function regenerateApplicationCode(
        int $companyId,
        int $creditNoteId,
        User $user,
        string $reason,
    ): array {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar un motivo (mínimo 3 caracteres) para regenerar el código.',
            ]);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo no puede superar los 500 caracteres.',
            ]);
        }

        return DB::transaction(function () use ($companyId, $creditNoteId, $user, $reason): array {
            $note = CreditNote::query()
                ->forCompany($companyId)
                ->whereKey($creditNoteId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $note->canRegenerateCode()) {
                throw ValidationException::withMessages([
                    'credit_note' => 'La Nota de Crédito no está disponible para regenerar su código.',
                ]);
            }

            $newCode = $this->generateApplicationCode();

            $note->update([
                'application_code_hash' => self::applicationCodeHash($newCode),
            ]);

            CreditNoteCodeRotation::create([
                'company_id' => $note->company_id,
                'credit_note_id' => $note->id,
                'user_id' => $user->id,
                'reason' => $reason,
            ]);

            return [
                'credit_note' => $note->fresh(),
                'application_code' => $newCode,
            ];
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

            if ($note->isExpired()) {
                throw ValidationException::withMessages([
                    'credit_note' => 'La Nota de Crédito está vencida y ya no puede aplicarse.',
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

            if ($note->isConsumerFinal()) {
                throw ValidationException::withMessages([
                    'customer' => 'La Nota de Crédito a consumidor final solo puede aplicarse en el POS presentando su código secreto.',
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
     * Aplica múltiples NC a una venta en orden determinista (POS batch).
     *
     * Lock order canónico: Sale → NC1 → NC2 → ... (ASC by id).
     * Valida TODAS las NC antes de empezar writes económicos.
     * Preserva invariante: issued_amount = offset_amount + applied_amount + balance.
     *
     * @param array<int, array{credit_note_id: int, amount: string, bearer?: bool}> $canonicalNC
     * @return CreditNoteApplication[]
     */
    public function applyBatchToSale(
        Sale $sale,
        array $canonicalNC,
        User $user,
        string $checkoutToken,
    ): array {
        if ($canonicalNC === []) {
            return [];
        }

        return DB::transaction(function () use ($sale, $canonicalNC, $user, $checkoutToken): array {
            $target = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Lock NCs en orden determinista ASC
            $ncIds = array_column($canonicalNC, 'credit_note_id');
            $sortedNCs = CreditNote::query()
                ->whereIn('id', $ncIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sortedNCs->count() !== count($ncIds)) {
                throw ValidationException::withMessages([
                    'credit_note_applications' => 'Una o más Notas de Crédito no están disponibles.',
                ]);
            }

            // ── FASE 1: Validar TODO el batch antes de cualquier write ──
            foreach ($canonicalNC as $ncReq) {
                $note = $sortedNCs->get($ncReq['credit_note_id']);

                if ((int) $note->company_id !== (int) $target->company_id) {
                    throw ValidationException::withMessages([
                        'credit_note_applications' => 'La NC '.$note->credit_note_number.' pertenece a otra empresa.',
                    ]);
                }

                if ($note->isConsumerFinal()) {
                    // NC Consumer Final (valor al portador). Únicamente puede
                    // ingresar por la vía autorizada con número + código (4B-2);
                    // la bandera bearer se marca durante esa autorización. El
                    // toggle de empresa se REvalida aquí, dentro de la
                    // transacción de checkout, aunque ya esté en true.
                    // Ambos rechazos usan el mensaje genérico para no revelar,
                    // por la vía nominativa con un id adivinado, que existe una
                    // NC consumidor final ni su número.
                    if (empty($ncReq['bearer'])) {
                        throw ValidationException::withMessages([
                            'credit_note_applications' => self::BEARER_GENERIC_ERROR,
                        ]);
                    }

                    if (! $note->company?->consumerFinalCreditNotesEnabled()) {
                        throw ValidationException::withMessages([
                            'credit_note_applications' => self::BEARER_GENERIC_ERROR,
                        ]);
                    }
                } elseif ($target->customer_id === null || (int) $note->customer_id !== (int) $target->customer_id) {
                    throw ValidationException::withMessages([
                        'credit_note_applications' => 'La NC '.$note->credit_note_number.' no pertenece a este cliente.',
                    ]);
                }

                if (in_array($note->status, [CreditNote::STATUS_VOIDED, CreditNote::STATUS_APPLIED], true)) {
                    throw ValidationException::withMessages([
                        'credit_note_applications' => 'La NC '.$note->credit_note_number.' no tiene saldo aplicable.',
                    ]);
                }

                if ($note->isExpired()) {
                    throw ValidationException::withMessages([
                        'credit_note_applications' => 'La NC '.$note->credit_note_number.' está vencida y ya no puede aplicarse.',
                    ]);
                }

                if ($note->requires_ar_review) {
                    throw ValidationException::withMessages([
                        'credit_note_applications' => 'La NC '.$note->credit_note_number.' requiere conciliación CxC.',
                    ]);
                }

                $applied = bcadd((string) $ncReq['amount'], '0', self::SCALE);

                if (bccomp($applied, '0', self::SCALE) <= 0) {
                    throw ValidationException::withMessages([
                        'credit_note_applications' => 'El monto para NC '.$note->credit_note_number.' debe ser mayor que cero.',
                    ]);
                }

                if (bccomp($applied, (string) $note->balance, self::SCALE) > 0) {
                    throw ValidationException::withMessages([
                        'credit_note_applications' => 'El monto para NC '.$note->credit_note_number.' supera su saldo disponible ('.number_format((float) $note->balance, 2, '.', '').').',
                    ]);
                }
            }

            // ── FASE 2: Crear applications y actualizar NCs ──
            $applications = [];
            $now = now();

            foreach ($canonicalNC as $ncReq) {
                $note = $sortedNCs->get($ncReq['credit_note_id']);
                $applicationToken = "pos-sale:{$checkoutToken}:cn:{$note->id}";

                // Idempotencia
                $existing = CreditNoteApplication::query()
                    ->where('company_id', $note->company_id)
                    ->where('application_token', $applicationToken)
                    ->first();

                if ($existing !== null) {
                    $applications[] = $existing;
                    continue;
                }

                $applied = bcadd((string) $ncReq['amount'], '0', self::SCALE);
                $newBalance = bcsub((string) $note->balance, $applied, self::SCALE);
                $newApplied = bcadd((string) $note->applied_amount, $applied, self::SCALE);
                $fullyApplied = bccomp($newBalance, '0', self::SCALE) === 0;

                $applications[] = CreditNoteApplication::create([
                    'company_id' => $note->company_id,
                    'credit_note_id' => $note->id,
                    'sale_id' => $target->id,
                    'customer_id' => $note->isConsumerFinal() ? $target->customer_id : $note->customer_id,
                    'amount' => $applied,
                    'application_token' => $applicationToken,
                    'applied_by' => $user->id,
                    'applied_at' => $now,
                    'status' => CreditNoteApplication::STATUS_APPLIED,
                    'notes' => 'Aplicación POS venta '.$target->sale_number,
                ]);

                $note->update([
                    'applied_amount' => $newApplied,
                    'balance' => $newBalance,
                    'status' => $fullyApplied
                        ? CreditNote::STATUS_APPLIED
                        : CreditNote::STATUS_PARTIALLY_APPLIED,
                ]);
            }

            return $applications;
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

    /**
     * Revierte una aplicación de Nota de Crédito (usada al anular la venta destino).
     *
     * Esta operación:
     * - Marca la CreditNoteApplication como voided (con auditoría)
     * - Restaura applied_amount y balance de la CreditNote
     * - Actualiza el status de la CreditNote (issued/partially_applied)
     *
     * Idempotencia: una aplicación ya revertida no puede revertirse de nuevo.
     * Lock order: Sale → CreditNoteApplication → CreditNote (orden global aprobado).
     *
     * @param  CreditNoteApplication  $application  La aplicación a revertir
     * @param  User                   $user         Usuario que ejecuta la reversión
     * @param  string                 $reason       Motivo de la reversión (p.ej. "Anulación de venta POS-00000001")
     * @return CreditNoteApplication                La aplicación revertida (fresh)
     */
    public function reverseApplication(
        CreditNoteApplication $application,
        User $user,
        string $reason
    ): CreditNoteApplication {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar el motivo de la reversión.',
            ]);
        }

        return DB::transaction(function () use ($application, $user, $reason): CreditNoteApplication {
            // Lock orden global: Sale → Application → CreditNote
            $app = CreditNoteApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($app->status === CreditNoteApplication::STATUS_VOIDED) {
                throw ValidationException::withMessages([
                    'application' => 'La aplicación ya ha sido revertida.',
                ]);
            }

            if ($app->status !== CreditNoteApplication::STATUS_APPLIED) {
                throw ValidationException::withMessages([
                    'application' => 'Solo se pueden revertir aplicaciones con estado "applied".',
                ]);
            }

            // Lock CreditNote
            $note = CreditNote::query()
                ->whereKey($app->credit_note_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Validar que la NC pertenece a la misma empresa/venta
            if ((int) $note->company_id !== (int) $app->company_id) {
                throw ValidationException::withMessages([
                    'application' => 'La aplicación pertenece a una NC de otra empresa.',
                ]);
            }

            // Marcar aplicación como revertida
            $now = now();
            $app->update([
                'status' => CreditNoteApplication::STATUS_VOIDED,
                'voided_by' => $user->id,
                'voided_at' => $now,
                'void_reason' => $reason,
            ]);

            // Restaurar la NC: restar el monto aplicado
            $amount = (string) $app->amount;
            $newApplied = bcsub((string) $note->applied_amount, $amount, self::SCALE);
            $newBalance = bcadd((string) $note->balance, $amount, self::SCALE);

            // Determinar nuevo status de la NC
            $fullyApplied = bccomp($newBalance, '0', self::SCALE) === 0;
            $newStatus = $fullyApplied
                ? CreditNote::STATUS_APPLIED
                : ($newApplied === '0.0000' ? CreditNote::STATUS_ISSUED : CreditNote::STATUS_PARTIALLY_APPLIED);

            $note->update([
                'applied_amount' => $newApplied,
                'balance' => $newBalance,
                'status' => $newStatus,
            ]);

            return $app->fresh();
        });
    }

    /**
     * Revierte múltiples aplicaciones de NC (batch) para una venta anulada.
     *
     * Lock order: Sale → Applications (ASC by id) → CreditNotes (ASC by id)
     *
     * @param  array<int, CreditNoteApplication>  $applications
     * @param  User                               $user
     * @param  string                             $reason
     * @return CreditNoteApplication[]
     */
    public function reverseApplications(
        array $applications,
        User $user,
        string $reason
    ): array {
        if ($applications === []) {
            return [];
        }

        return DB::transaction(function () use ($applications, $user, $reason): array {
            // Lock todas las aplicaciones en orden determinista
            $appIds = array_map(fn ($app) => $app->id, $applications);
            $sortedApps = CreditNoteApplication::query()
                ->whereIn('id', $appIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sortedApps->count() !== count($appIds)) {
                throw ValidationException::withMessages([
                    'applications' => 'Una o más aplicaciones no están disponibles para reversión.',
                ]);
            }

            // Lock todas las NCs involucradas en orden determinista
            $ncIds = $sortedApps->pluck('credit_note_id')->unique()->values()->all();
            $sortedNCs = CreditNote::query()
                ->whereIn('id', $ncIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sortedNCs->count() !== count($ncIds)) {
                throw ValidationException::withMessages([
                    'applications' => 'Una o más NCs asociadas no están disponibles.',
                ]);
            }

            $reversed = [];
            $now = now();

            foreach ($sortedApps as $app) {
                if ($app->status === CreditNoteApplication::STATUS_VOIDED) {
                    throw ValidationException::withMessages([
                        'applications' => 'La aplicación '.$app->id.' ya ha sido revertida.',
                    ]);
                }

                if ($app->status !== CreditNoteApplication::STATUS_APPLIED) {
                    throw ValidationException::withMessages([
                        'applications' => 'La aplicación '.$app->id.' no tiene estado "applied".',
                    ]);
                }

                $note = $sortedNCs->get($app->credit_note_id);

                if ($note === null) {
                    throw ValidationException::withMessages([
                        'applications' => 'NC no encontrada para la aplicación '.$app->id.'.',
                    ]);
                }

                if ((int) $note->company_id !== (int) $app->company_id) {
                    throw ValidationException::withMessages([
                        'applications' => 'La aplicación '.$app->id.' pertenece a una NC de otra empresa.',
                    ]);
                }

                // Marcar aplicación como revertida
                $app->update([
                    'status' => CreditNoteApplication::STATUS_VOIDED,
                    'voided_by' => $user->id,
                    'voided_at' => $now,
                    'void_reason' => $reason,
                ]);

                // Restaurar la NC
                $amount = (string) $app->amount;
                $newApplied = bcsub((string) $note->applied_amount, $amount, self::SCALE);
                $newBalance = bcadd((string) $note->balance, $amount, self::SCALE);

                $fullyApplied = bccomp($newBalance, '0', self::SCALE) === 0;
                $newStatus = $fullyApplied
                    ? CreditNote::STATUS_APPLIED
                    : ($newApplied === '0.0000' ? CreditNote::STATUS_ISSUED : CreditNote::STATUS_PARTIALLY_APPLIED);

                $note->update([
                    'applied_amount' => $newApplied,
                    'balance' => $newBalance,
                    'status' => $newStatus,
                ]);

                $reversed[] = $app->fresh();
            }

            return $reversed;
        });
    }
}
