<?php

namespace App\Services;

use App\Models\OfflineSyncOperation;
use App\Models\OfflineTerminal;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\OfflineAuthorizationService;
use App\Services\Sales\PosSaleProcessor;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OfflineSyncService
{
    public const SUPPORTED_OPERATION_TYPES = [
        OfflineSyncOperation::OPERATION_TYPE_OFFLINE_SALE,
    ];

    public function __construct(
        private readonly OfflineAuthorizationService $authorizationService,
        private readonly PosSaleProcessor $posSaleProcessor,
    ) {}

    /**
     * Synchronize one offline operation, one at a time (FIFO enforced client-side).
     *
     * Idempotency contract:
     * - same operation_uuid + same payload    -> returns previously processed result, never duplicates the Sale
     * - same operation_uuid + different payload -> rejected as a conflict (idempotency violation)
     * - concurrent requests for same operation_uuid -> UNIQUE(operation_uuid) prevents double processing
     *
     * @return array{status: string, operation_uuid: string, sale_id: ?int, sale_number: ?string, payload_hash: string}
     */
    public function sync(array $data, User $user, int $companyId, int $branchId): array
    {
        $operationUuid = (string) $data['operation_uuid'];
        $terminalUuid = (string) $data['terminal_uuid'];
        $createdAtLocal = $this->parseCreatedAtLocal($data['created_at_local']);

        $authPayload = $this->authorizationService->verifyTokenAllowExpired((string) $data['authorization']);

        if ($authPayload === null) {
            throw ValidationException::withMessages([
                'authorization' => 'La autorización no es válida para sincronizar.',
            ]);
        }

        $this->assertContextMatchesSession($authPayload, $companyId, $branchId, $terminalUuid);
        $this->assertCreationWithinAuthorizationWindow($authPayload, $createdAtLocal);

        $this->assertOperationTypeSupported((string) $data['operation_type']);
        $this->assertUserCanOperate($user, $companyId, $branchId);

        $canonical = $this->canonicalizePayload($data['payload']);
        $payloadHash = $this->payloadHash($operationUuid, $terminalUuid, $createdAtLocal, $canonical);

        [$record, $created] = $this->reserveOperation(
            $operationUuid,
            (string) $data['operation_type'],
            $companyId,
            $branchId,
            $terminalUuid,
            $user->id,
            (int) $data['payload_version'],
            $createdAtLocal,
            $canonical,
            $payloadHash,
        );

        if ($record->isSynced()) {
            return $this->result($record, 'already_processed');
        }

        if (! $created && $record->isProcessing()) {
            $sale = $this->saleForOperation($companyId, $operationUuid);

            if ($sale !== null) {
                $record->markSynced($sale);

                return $this->result($record, 'already_processed');
            }

            return [
                'status' => 'in_progress',
                'operation_uuid' => $operationUuid,
                'sale_id' => null,
                'sale_number' => null,
                'payload_hash' => $payloadHash,
            ];
        }

        try {
            $processedData = $this->processorContract($canonical, $operationUuid);
            $processed = $this->posSaleProcessor->process(
                $processedData,
                $user,
                $companyId,
                $branchId,
            );

            $sale = $processed['sale'];
            $record->markSynced($sale);

            return $this->result($record, $processed['duplicate'] ? 'already_processed' : 'processed');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?? 'La venta offline fue rechazada por reglas del negocio.';
            $record->markConflict($message);

            throw $exception;
        } catch (ConflictHttpException $exception) {
            $record->markConflict($exception->getMessage());

            throw $exception;
        } catch (QueryException $exception) {
            $record->markFailed('Error transitorio de base de datos: '.$exception->getMessage());

            throw $exception;
        } catch (\Throwable $exception) {
            $record->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    /**
     * Reserve the operation under the UNIQUE(operation_uuid) constraint.
     * If the row already exists, idempotency rules decide the outcome.
     *
     * @return array{0: OfflineSyncOperation, 1: bool} [record, wasCreatedNow]
     */
    private function reserveOperation(
        string $operationUuid,
        string $operationType,
        int $companyId,
        int $branchId,
        string $terminalUuid,
        int $userId,
        int $payloadVersion,
        CarbonInterface $createdAtLocal,
        array $canonical,
        string $payloadHash,
    ): array {
        try {
            return [
                OfflineSyncOperation::create([
                    'operation_uuid' => $operationUuid,
                    'operation_type' => $operationType,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'terminal_uuid' => $terminalUuid,
                    'user_id' => $userId,
                    'payload_version' => (string) $payloadVersion,
                    'payload' => $canonical,
                    'payload_hash' => $payloadHash,
                    'created_at_local' => $createdAtLocal,
                    'status' => OfflineSyncOperation::STATUS_PROCESSING,
                ]),
                true,
            ];
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findOperation($operationUuid, $companyId, $branchId);

            if ($existing === null) {
                throw $exception;
            }

            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                $existing->markConflict(
                    'El operation_uuid ya fue utilizado con un payload diferente.',
                );

                throw new ConflictHttpException(
                    'El operation_uuid ya fue utilizado con datos diferentes.',
                );
            }

            return [$existing, false];
        }
    }

    private function canonicalizePayload(array $payload): array
    {
        $items = [];

        foreach ($payload['items'] ?? [] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);

            if ($productId <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'Cada línea debe indicar un producto válido.',
                ]);
            }

            $items[] = [
                'product_id' => $productId,
                'quantity' => $this->stringifyNumber($item['quantity'] ?? '1'),
                'discount' => $this->stringifyNumber($item['discount'] ?? '0'),
                'discount_type' => (string) ($item['discount_type'] ?? 'fixed'),
                'unit_price' => isset($item['unit_price']) && $item['unit_price'] !== null
                    ? $this->stringifyNumber($item['unit_price'])
                    : null,
            ];
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'La operación debe contener al menos un producto.',
            ]);
        }

        $payments = [];

        foreach ($payload['payments'] ?? [] as $payment) {
            $paymentMethodId = (int) ($payment['payment_method_id'] ?? 0);

            if ($paymentMethodId <= 0) {
                throw ValidationException::withMessages([
                    'payments' => 'Cada pago debe indicar un método de pago válido.',
                ]);
            }

            $payments[] = [
                'payment_method_id' => $paymentMethodId,
                'amount' => $this->stringifyNumber($payment['amount'] ?? '0'),
                'received_amount' => isset($payment['received_amount']) && $payment['received_amount'] !== null
                    ? $this->stringifyNumber($payment['received_amount'])
                    : null,
                'received_amount_usd' => isset($payment['received_amount_usd'])
                    && $payment['received_amount_usd'] !== null
                    ? $this->stringifyNumber($payment['received_amount_usd'])
                    : null,
                'change_currency' => $payment['change_currency'] ?? null,
                'reference' => isset($payment['reference']) && trim((string) $payment['reference']) !== ''
                    ? trim((string) $payment['reference'])
                    : null,
            ];
        }

        if ($payments === []) {
            throw ValidationException::withMessages([
                'payments' => 'La operación debe contener al menos un pago.',
            ]);
        }

        return [
            'payload_version' => (int) ($payload['payload_version'] ?? 1),
            'document_type' => $this->resolveDocumentType($payload['document_type'] ?? null),
            'cash_session_id' => isset($payload['cash_session_id'])
                ? (int) $payload['cash_session_id']
                : null,
            'customer_id' => isset($payload['customer_id'])
                ? (int) $payload['customer_id']
                : null,
            'requested_points' => isset($payload['requested_points'])
                && $payload['requested_points'] !== null
                ? $this->stringifyNumber($payload['requested_points'])
                : null,
            'discount_total' => isset($payload['discount_total'])
                && $payload['discount_total'] !== null
                ? $this->stringifyNumber($payload['discount_total'])
                : null,
            'discount_total_type' => (string) ($payload['discount_total_type'] ?? 'fixed'),
            'items' => $items,
            'payments' => $payments,
        ];
    }

    private function processorContract(array $canonical, string $operationUuid): array
    {
        $contract = [
            'checkout_token' => $operationUuid,
            'document_type' => $canonical['document_type'],
            'cash_session_id' => $canonical['cash_session_id'],
            'customer_id' => $canonical['customer_id'],
            'items' => $canonical['items'],
        ];

        if ($canonical['requested_points'] !== null) {
            $contract['requested_points'] = $canonical['requested_points'];
        }

        if ($canonical['discount_total'] !== null) {
            $contract['discount_total'] = $canonical['discount_total'];
            $contract['discount_total_type'] = $canonical['discount_total_type'];
        }

        $contract['payments'] = array_map(
            fn (array $payment) => array_filter($payment, fn ($value) => $value !== null),
            $canonical['payments'],
        );

        return $contract;
    }

    private function payloadHash(
        string $operationUuid,
        string $terminalUuid,
        CarbonInterface $createdAtLocal,
        array $canonical,
    ): string {
        $normalized = [
            'operation_uuid' => $operationUuid,
            'terminal_uuid' => $terminalUuid,
            'created_at_local' => $createdAtLocal->toIso8601String(),
            'payload' => $this->stableSort($canonical),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function stableSort(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? $this->stableSort($item) : $item, $value);
        }

        ksort($value, SORT_STRING);

        return array_map(
            fn ($item) => is_array($item) ? $this->stableSort($item) : $item,
            $value,
        );
    }

    private function resolveDocumentType(?string $documentType): string
    {
        return match ($documentType) {
            Sale::DOCUMENT_ELECTRONIC_INVOICE, 'invoice' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            default => Sale::DOCUMENT_ELECTRONIC_TICKET,
        };
    }

    private function stringifyNumber(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');
        }

        $string = (string) $value;

        if (preg_match('/^-?\d+(?:\.\d{1,8})?$/', $string)) {
            if (! str_contains($string, '.')) {
                return $string;
            }

            return rtrim(rtrim($string, '0'), '.');
        }

        throw ValidationException::withMessages([
            'payload' => 'Un monto del payload no tiene un formato decimal válido.',
        ]);
    }

    private function parseCreatedAtLocal(string $createdAtLocal): CarbonInterface
    {
        try {
            return \Carbon\Carbon::parse($createdAtLocal);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'created_at_local' => 'La fecha local de creación no es válida.',
            ]);
        }
    }

    private function assertContextMatchesSession(
        array $authPayload,
        int $companyId,
        int $branchId,
        string $terminalUuid,
    ): void {
        if ((int) $authPayload['company_id'] !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'La empresa de la autorización no coincide con la sesión activa.',
            ]);
        }

        if ((int) $authPayload['branch_id'] !== $branchId || $authPayload['terminal_uuid'] !== $terminalUuid) {
            throw ValidationException::withMessages([
                'branch_id' => 'La sucursal o terminal de la autorización no coincide con la sesión activa.',
            ]);
        }

        $terminal = OfflineTerminal::where('terminal_uuid', $terminalUuid)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->first();

        if ($terminal === null || $terminal->isRevoked()) {
            throw ValidationException::withMessages([
                'terminal_uuid' => 'La terminal no está disponible para sincronizar.',
            ]);
        }
    }

    private function assertCreationWithinAuthorizationWindow(
        array $authPayload,
        CarbonInterface $createdAtLocal,
    ): void {
        $issuedAt = \Carbon\Carbon::parse($authPayload['issued_at']);
        $validUntil = \Carbon\Carbon::parse($authPayload['valid_until']);

        if ($createdAtLocal->lt($issuedAt) || $createdAtLocal->gt($validUntil)) {
            throw ValidationException::withMessages([
                'created_at_local' => 'La operación fue creada fuera de la ventana de autorización.',
            ]);
        }
    }

    private function assertOperationTypeSupported(string $operationType): void
    {
        if (! in_array($operationType, self::SUPPORTED_OPERATION_TYPES, true)) {
            throw ValidationException::withMessages([
                'operation_type' => 'El tipo de operación no está soportado para sincronización.',
            ]);
        }
    }

    private function assertUserCanOperate(User $user, int $companyId, int $branchId): void
    {
        if (! $user->companies()->whereKey($companyId)->exists()) {
            throw ValidationException::withMessages([
                'company_id' => 'El usuario no tiene acceso a esta empresa.',
            ]);
        }

        if (! $user->branches()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages([
                'branch_id' => 'El usuario no tiene acceso a esta sucursal.',
            ]);
        }
    }

    private function findOperation(string $operationUuid, int $companyId, int $branchId): ?OfflineSyncOperation
    {
        return OfflineSyncOperation::query()
            ->where('operation_uuid', $operationUuid)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->first();
    }

    private function saleForOperation(int $companyId, string $operationUuid): ?Sale
    {
        return Sale::query()
            ->where('company_id', $companyId)
            ->where('checkout_token', $operationUuid)
            ->first();
    }

    private function result(OfflineSyncOperation $record, string $status): array
    {
        $sale = $record->sale_id !== null ? $record->sale : null;

        return [
            'status' => $status,
            'operation_uuid' => $record->operation_uuid,
            'sale_id' => $sale?->id,
            'sale_number' => $sale?->sale_number,
            'payload_hash' => (string) $record->payload_hash,
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        if (in_array($code, ['23505', '23000', '19', '2067'], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique')
            || str_contains($message, 'offline_sync_operations_operation_uuid_unique')
            || str_contains($message, 'unique constraint');
    }
}