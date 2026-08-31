<?php

namespace App\Services\Imports;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Services\Loyalty\LoyaltyAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LoyaltyMigrationImportService
{
    public const HEADERS = [
        'origen_migracion*', 'identificacion_cliente*', 'tipo_saldo*', 'fecha*', 'codigo_sucursal',
        'codigo_producto', 'codigo_barras', 'tipo_movimiento', 'puntos*', 'saldo_actual',
        'total_ganado', 'total_canjeado', 'total_vencido', 'fecha_ultima_compra', 'fecha_ultima_actividad',
        'activo*', 'stock_anterior', 'stock_nuevo', 'descripcion', 'metadata',
    ];

    private const MAP = [
        'origen_migracion' => 'source_key', 'identificacion_cliente' => 'identification',
        'tipo_saldo' => 'record_type', 'fecha' => 'effective_at', 'codigo_sucursal' => 'branch_code',
        'codigo_producto' => 'product_code', 'codigo_barras' => 'barcode', 'tipo_movimiento' => 'movement_type',
        'puntos' => 'points', 'saldo_actual' => 'balance', 'total_ganado' => 'total_earned',
        'total_canjeado' => 'total_redeemed', 'total_vencido' => 'total_expired', 'fecha_ultima_compra' => 'last_qualifying_purchase_at',
        'fecha_ultima_actividad' => 'last_activity_at', 'activo' => 'is_active',
        'stock_anterior' => 'previous_balance', 'stock_nuevo' => 'new_balance',
        'descripcion' => 'description', 'metadata' => 'metadata',
    ];

    private const LABELS = [
        'source_key' => 'origen_migracion', 'identification' => 'identificacion_cliente',
        'record_type' => 'tipo_saldo', 'balance' => 'saldo_actual', 'total_earned' => 'total_ganado',
        'total_redeemed' => 'total_canjeado', 'total_expired' => 'total_vencido',
        'last_qualifying_purchase_at' => 'fecha_ultima_compra', 'last_activity_at' => 'fecha_ultima_actividad',
        'is_active' => 'activo', 'movement_type' => 'tipo_movimiento', 'points' => 'puntos',
        'effective_at' => 'fecha', 'description' => 'descripcion', 'metadata' => 'metadata',
        'previous_balance' => 'stock_anterior', 'new_balance' => 'stock_nuevo',
    ];

    private const EARNING_TYPES = [
        LoyaltyMovement::TYPE_PURCHASE,
        LoyaltyMovement::TYPE_NEW_CUSTOMER,
        LoyaltyMovement::TYPE_BIRTHDAY,
        LoyaltyMovement::TYPE_RETURN_CUSTOMER,
        LoyaltyMovement::TYPE_PROMOTION,
    ];

    private const REDEMPTION_TYPES = [
        LoyaltyMovement::TYPE_REDEMPTION,
        LoyaltyMovement::TYPE_REWARD,
        LoyaltyMovement::TYPE_EXPIRATION,
    ];

    public function __construct(private readonly LoyaltyAccountService $accounts) {}

    private function bc(string $value, string $default = '0'): string
    {
        $normalized = preg_replace('/[^0-9.]/', '', (string) $value);

        return ($normalized === '' || $normalized === false) ? $default : $normalized;
    }

    public function preview(string $path, int $companyId): array
    {
        Company::query()->findOrFail($companyId);
        $source = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);

        if (count($source) < 2) {
            throw ValidationException::withMessages([
                'migrar_file' => 'El archivo debe incluir encabezados y al menos una fila.',
            ]);
        }

        $headers = $this->resolveHeaders(array_shift($source));
        $rows = [];

        foreach ($source as $offset => $values) {
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $data = [];
            foreach ($headers as $column => $field) {
                if ($field !== null) {
                    $data[$field] = $values[$column] ?? null;
                }
            }

            $rows[] = $this->normalizeRow($data, $offset + 2, $companyId);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'migrar_file' => 'El archivo no contiene filas para revisar.',
            ]);
        }

        return ['company_id' => $companyId, 'rows' => $this->validateRows($rows, $companyId)];
    }

    public function confirm(array $preview, int $companyId, int $userId): int
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages([
                'migrar_file' => 'La vista previa no pertenece a la empresa activa.',
            ]);
        }

        $rows = $this->validateRows($preview['rows'] ?? [], $companyId);

        if ($invalid = collect($rows)->firstWhere('valid', false)) {
            $error = $invalid['errors'][0] ?? ['field' => 'fila', 'message' => 'dato inválido'];
            throw ValidationException::withMessages([
                'migrar_file' => "La importación cambió o contiene errores. Fila {$invalid['row_number']}, {$error['field']}: {$error['message']}",
            ]);
        }

        $sourceKey = $rows[0]['source_key'];

        return DB::transaction(function () use ($rows, $companyId, $userId, $sourceKey): int {
            if (DB::table('loyalty_migration_batches')->where('company_id', $companyId)->where('source_key', $sourceKey)->lockForUpdate()->exists()) {
                return 0;
            }
            $batchId = DB::table('loyalty_migration_batches')->insertGetId([
                'company_id' => $companyId, 'user_id' => $userId, 'source_key' => $sourceKey,
                'row_count' => count($rows), 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($rows as $row) {
                $balanceRow = $row['record_type'] === 'initial_balance';

                if ($balanceRow) {
                    $this->importBalance($row, $companyId, $userId, $batchId);
                } else {
                    $this->importMovement($row, $companyId, $userId, $batchId);
                }
            }

            return count($rows);
        });
    }

    private function importBalance(array $row, int $companyId, int $userId, int $batchId): void
    {
        $customer = Customer::withTrashed()->where('company_id', $companyId)
            ->where('identification', $row['identification'])->first();
        if ($customer === null) {
            return;
        }

        $account = LoyaltyAccount::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->first();

        $isNew = $account === null;

        if ($isNew) {
            $account = new LoyaltyAccount([
                'company_id' => $companyId, 'customer_id' => $customer->id,
                'balance' => '0.0000', 'total_earned' => '0.0000',
                'total_redeemed' => '0.0000', 'total_expired' => '0.0000',
            ]);
        }

        $account->fill([
            'balance' => $row['balance'],
            'total_earned' => $row['total_earned'] ?? '0.0000',
            'total_redeemed' => $row['total_redeemed'] ?? '0.0000',
            'total_expired' => $row['total_expired'] ?? '0.0000',
            'last_qualifying_purchase_at' => $row['last_qualifying_purchase_at'],
            'last_activity_at' => $row['last_activity_at'],
            'is_active' => $row['is_active'],
        ]);
        $account->save();
    }

    private function importMovement(array $row, int $companyId, int $userId, int $batchId): void
    {
        $customer = Customer::withTrashed()->where('company_id', $companyId)
            ->where('identification', $row['identification'])->first();
        if ($customer === null) {
            return;
        }

        $account = LoyaltyAccount::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            $account = $this->accounts->getOrCreateAccount($customer, Company::query()->findOrFail($companyId));
        }

        $isRedemption = in_array($row['movement_type'], self::REDEMPTION_TYPES, true);
        $signedPoints = $isRedemption ? bcsub('0', $row['points'], 4) : $row['points'];

        $eventKey = 'loyalty_migration:'.$batchId.':'.$row['row_number'].':'.$row['identification'].':'.$row['movement_type'];

        $this->accounts->recordHistoricalMigrationMovement($account, $signedPoints, $row['movement_type'], [
            'event_key' => $eventKey,
            'user_id' => $userId,
            'effective_at' => $row['effective_at'],
            'balance_before' => $row['previous_balance'],
            'balance_after' => $row['new_balance'],
            'description' => $row['description'],
            'metadata' => $row['metadata'],
            'source_type' => 'LoyaltyMigration',
            'source_id' => $batchId,
        ]);
    }

    private function resolveHeaders(array $headers): array
    {
        $resolved = [];
        foreach ($headers as $column => $header) {
            $key = trim(Str::of((string) $header)->ascii()->lower()->replace([' ', '-', '*'], ['_', '_', ''])->toString(), '_');
            $resolved[$column] = self::MAP[$key] ?? null;
        }

        foreach (['source_key', 'identification', 'record_type', 'balance'] as $required) {
            if (! in_array($required, $resolved, true)) {
                throw ValidationException::withMessages([
                    'migrar_file' => 'Falta una columna obligatoria. Descargue la plantilla vigente.',
                ]);
            }
        }

        return $resolved;
    }

    private function normalizeRow(array $data, int $rowNumber, int $companyId): array
    {
        $identification = $this->text($data['identification'] ?? null);
        $recordType = match (Str::lower($this->text($data['record_type'] ?? '') ?? '')) {
            'saldo_inicial', 'balance', 'initial_balance' => 'initial_balance',
            'movimiento_historico', 'movimiento', 'historico', 'historical_movement' => 'historical_movement',
            default => null,
        };

        $customer = $identification === null ? null : Customer::withTrashed()
            ->where('company_id', $companyId)->where('identification', $identification)->first();

        $movementType = $recordType === 'historical_movement'
            ? $this->resolveMovementType($data['movement_type'] ?? null)
            : null;

        $occurredAt = $this->date($data['effective_at'] ?? ($data['occurred_at'] ?? null));
        $account = $customer ? LoyaltyAccount::query()->where('company_id', $companyId)->where('customer_id', $customer->id)->first() : null;

        return [
            'row_number' => $rowNumber,
            'source_key' => $this->text($data['source_key'] ?? null),
            'identification' => $identification,
            'record_type' => $recordType,
            'customer_id' => $customer?->id,
            'current_account_id' => $account?->id,
            'current_balance' => $account ? bcadd((string) $account->balance, '0', 4) : null,
            'current_movement_count' => $account?->movements()->count() ?? 0,
            'balance' => $this->decimal($data['balance'] ?? null),
            'total_earned' => $this->decimal($data['total_earned'] ?? null),
            'total_redeemed' => $this->decimal($data['total_redeemed'] ?? null),
            'total_expired' => $this->decimal($data['total_expired'] ?? null),
            'last_qualifying_purchase_at' => $this->date($data['last_qualifying_purchase_at'] ?? null),
            'last_activity_at' => $this->date($data['last_activity_at'] ?? null),
            'is_active' => $this->booleanValue($data['is_active'] ?? null),
            'movement_type' => $movementType,
            'points' => $this->decimal($data['points'] ?? null),
            'effective_at' => $occurredAt,
            'previous_balance' => $this->decimal($data['previous_balance'] ?? null),
            'new_balance' => $this->decimal($data['new_balance'] ?? null),
            'description' => $this->text($data['description'] ?? null),
            'metadata' => $this->text($data['metadata'] ?? null),
            'valid' => true,
            'errors' => [],
        ];
    }

    private function validateRows(array $rows, int $companyId): array
    {
        $sourceKeys = collect($rows)->pluck('source_key')->filter()->unique();
        $batchExists = $sourceKeys->count() === 1
            && DB::table('loyalty_migration_batches')
                ->where('company_id', $companyId)
                ->where('source_key', $sourceKeys->first())
                ->exists();

        $seenBalances = [];
        $chains = [];

        foreach ($rows as $index => $row) {
            $errors = [];

            if ($sourceKeys->count() !== 1 || ! $row['source_key']) {
                $errors[] = ['field' => 'origen_migracion', 'message' => 'Todas las filas deben compartir una única clave de origen.'];
            }

            if ($batchExists) {
                $errors[] = ['field' => 'origen_migracion', 'message' => 'Este origen ya fue importado; el reintento no duplicará movimientos.'];
            }

            if (! $row['record_type']) {
                $errors[] = ['field' => 'tipo_saldo', 'message' => 'Use saldo_inicial o movimiento_historico.'];
            }

            if ($row['identification'] !== null && $row['customer_id'] === null) {
                $errors[] = ['field' => 'identificacion_cliente', 'message' => 'El cliente no existe en la empresa activa.'];
            }

            if ($row['record_type'] === 'initial_balance') {
                $pair = $row['identification'];
                if (isset($seenBalances[$pair])) {
                    $errors[] = ['field' => 'tipo_saldo', 'message' => 'El saldo inicial del cliente está repetido.'];
                }
                $seenBalances[$pair] = true;

                if ($row['current_account_id'] && (bccomp($row['current_balance'], '0', 4) !== 0 || $row['current_movement_count'] > 0)) {
                    $errors[] = ['field' => 'saldo_actual', 'message' => 'La cuenta ya tiene saldo o movimientos operativos; no se sobrescribe para evitar duplicar beneficios.'];
                }

                if ($row['movement_type'] || $row['points'] !== null) {
                    $errors[] = ['field' => 'tipo_saldo', 'message' => 'Saldo inicial no usa tipo de movimiento ni puntos.'];
                }

                if (! $this->validDecimal($row['balance']) || bccomp($this->bc($row['balance']), '0', 4) < 0) {
                    $errors[] = ['field' => 'saldo_actual', 'message' => 'Es obligatorio y no negativo con cuatro decimales.'];
                }

                foreach (['total_earned' => 'total_ganado', 'total_redeemed' => 'total_canjeado', 'total_expired' => 'total_vencido'] as $field => $label) {
                    if ($row[$field] !== null && ! $this->validDecimal($row[$field])) {
                        $errors[] = ['field' => $label, 'message' => 'Use un decimal no negativo con máximo cuatro decimales.'];
                    }
                }
            }

            if ($row['record_type'] === 'historical_movement') {
                if (! $row['movement_type']) {
                    $errors[] = ['field' => 'tipo_movimiento', 'message' => 'Es obligatorio ('.implode(', ', LoyaltyMovement::TYPES).').'];
                }

                if (! $this->validDecimal($row['points']) || bccomp($this->bc($row['points']), '0', 4) <= 0) {
                    $errors[] = ['field' => 'puntos', 'message' => 'Es obligatorio y mayor que cero con cuatro decimales.'];
                }

                if ($row['points'] !== null && $this->validDecimal($row['points'])) {
                    if (in_array($row['movement_type'], self::EARNING_TYPES, true)) {
                        $expected = bcadd($this->bc($row['previous_balance'] ?? null), $this->bc($row['points']), 4);
                    } elseif (in_array($row['movement_type'], self::REDEMPTION_TYPES, true)) {
                        $expected = bcsub($this->bc($row['previous_balance'] ?? null), $this->bc($row['points']), 4);
                    } else {
                        $expected = null;
                    }

                    if ($expected !== null && (! $this->validDecimal($row['previous_balance']) || ! $this->validDecimal($row['new_balance']))) {
                        $errors[] = ['field' => 'stock_anterior', 'message' => 'Los saldos anterior y nuevo son obligatorios para conciliar el movimiento.'];
                    } elseif ($expected !== null && bccomp($expected, $row['new_balance'], 4) !== 0) {
                        $errors[] = ['field' => 'stock_nuevo', 'message' => "No concilia; el valor esperado es {$expected}."];
                    }
                }

                $pair = $row['identification'];
                if ($this->validDecimal($row['previous_balance']) && $this->validDecimal($row['new_balance'])) {
                    if (isset($chains[$pair]) && bccomp($chains[$pair], $row['previous_balance'], 4) !== 0) {
                        $errors[] = ['field' => 'stock_anterior', 'message' => 'No continúa el saldo nuevo del movimiento histórico anterior.'];
                    }
                    $chains[$pair] = $row['new_balance'];
                }
            }

            $row['errors'] = collect($errors)->unique(fn ($error) => $error['field'].'|'.$error['message'])->values()->all();
            $row['valid'] = $row['errors'] === [];
            $rows[$index] = $row;
        }

        return $rows;
    }

    private function resolveMovementType(mixed $value): ?string
    {
        $text = Str::lower($this->text($value) ?? '');
        $map = [
            'compra' => LoyaltyMovement::TYPE_PURCHASE, 'purchase' => LoyaltyMovement::TYPE_PURCHASE,
            'cliente_nuevo' => LoyaltyMovement::TYPE_NEW_CUSTOMER, 'new_customer' => LoyaltyMovement::TYPE_NEW_CUSTOMER,
            'cumpleanos' => LoyaltyMovement::TYPE_BIRTHDAY, 'birthday' => LoyaltyMovement::TYPE_BIRTHDAY,
            'retorno' => LoyaltyMovement::TYPE_RETURN_CUSTOMER, 'return_customer' => LoyaltyMovement::TYPE_RETURN_CUSTOMER,
            'promocion' => LoyaltyMovement::TYPE_PROMOTION, 'promotion' => LoyaltyMovement::TYPE_PROMOTION,
            'canje' => LoyaltyMovement::TYPE_REDEMPTION, 'redemption' => LoyaltyMovement::TYPE_REDEMPTION,
            'premio' => LoyaltyMovement::TYPE_REWARD, 'reward' => LoyaltyMovement::TYPE_REWARD,
            'devolucion' => LoyaltyMovement::TYPE_RETURN, 'return' => LoyaltyMovement::TYPE_RETURN,
            'anulacion' => LoyaltyMovement::TYPE_VOID, 'void' => LoyaltyMovement::TYPE_VOID,
            'vencimiento' => LoyaltyMovement::TYPE_EXPIRATION, 'expiration' => LoyaltyMovement::TYPE_EXPIRATION,
            'ajuste' => LoyaltyMovement::TYPE_ADJUSTMENT, 'adjustment' => LoyaltyMovement::TYPE_ADJUSTMENT,
        ];

        return $map[$text] ?? null;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?string
    {
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return $this->text($value);
    }

    private function validDecimal(?string $value): bool
    {
        return $value !== null && preg_match('/^\d+(?:\.\d{1,4})?$/', $value) === 1;
    }

    private function date(mixed $value): ?string
    {
        try {
            $date = is_numeric($value)
                ? CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value))
                : CarbonImmutable::parse((string) $value);

            return $date->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function booleanValue(mixed $value): bool
    {
        $value = Str::lower(trim((string) ($value ?? 'si')));

        return ! in_array($value, ['0', 'no', 'n', 'false', 'inactivo'], true);
    }
}
