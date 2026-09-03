<?php

namespace App\Services\Imports;

use App\Jobs\ProcessLoyaltyMigration;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMigrationRun;
use App\Models\LoyaltyMovement;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\PhoneNumberService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LoyaltyMigrationImportService
{
    public const HEADERS = ['NOMBRE', 'PUNTOS OTORGADOS', 'PUNTOS UTILIZADOS', 'SALDO'];

    private const MAP = [
        'nombre' => 'name',
        'puntos_otorgados' => 'awarded_points',
        'puntos_utilizados' => 'used_points',
        'saldo' => 'balance',
        'identificacion' => 'identification',
        'telefono' => 'phone',
        'email' => 'email',
    ];

    private const REQUIRED_FIELDS = ['name', 'awarded_points', 'used_points', 'balance'];

    public function __construct(
        private readonly LoyaltyAccountService $accounts,
        private readonly PhoneNumberService $phones,
    ) {}

    public function preview(string $path, int $companyId): array
    {
        Company::query()->findOrFail($companyId);
        $reader = IOFactory::createReaderForFile($path);
        $reader->setValueBinder(new StringValueBinder);
        $source = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);

        if (count($source) < 2) {
            throw ValidationException::withMessages([
                'migrar_file' => 'El archivo debe incluir encabezados y al menos una fila.',
            ]);
        }

        $headers = $this->resolveHeaders(array_shift($source));
        $customerIndex = $this->customerIndex($companyId);
        $rows = [];

        foreach ($source as $offset => $values) {
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $data = [];
            foreach ($headers as $column => $field) {
                $data[$field] = $values[$column] ?? null;
            }
            $rows[] = $this->normalizeRow($data, $offset + 2, $companyId, $customerIndex);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'migrar_file' => 'El archivo no contiene filas para revisar.',
            ]);
        }

        $sourceKey = $this->sourceKey($rows);
        $rows = array_map(fn (array $row) => $row + ['source_key' => $sourceKey], $rows);

        return [
            'company_id' => $companyId,
            'source_key' => $sourceKey,
            'source_rows' => $rows,
            'rows' => $this->prepareRows($rows, $companyId, $customerIndex),
        ];
    }

    public function confirm(array $preview, int $companyId, int $userId): int
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages([
                'migrar_file' => 'La vista previa no pertenece a la empresa activa.',
            ]);
        }

        $sourceKey = (string) ($preview['source_key'] ?? '');
        if ($sourceKey !== '' && DB::table('loyalty_migration_batches')->where('company_id', $companyId)
            ->where('source_key', $sourceKey)->exists()) {
            return 0;
        }

        $rows = $this->validateRows($preview['rows'] ?? [], $companyId, $this->customerIndex($companyId));
        $validRows = collect($rows)->where('valid', true)->values()->all();
        $pendingRows = collect($rows)->where('valid', false)->values()->all();
        if ($validRows === []) {
            $invalid = $pendingRows[0] ?? [];
            $error = $invalid['errors'][0] ?? ['field' => 'fila', 'message' => 'dato inválido'];
            throw ValidationException::withMessages([
                'migrar_file' => 'No hay filas válidas para importar. Fila '.($invalid['row_number'] ?? '—').", {$error['field']}: {$error['message']}",
            ]);
        }

        $sourceKey = (string) ($preview['source_key'] ?? $rows[0]['source_key'] ?? '');

        return DB::transaction(function () use ($validRows, $pendingRows, $companyId, $userId, $sourceKey): int {
            if (DB::table('loyalty_migration_batches')->where('company_id', $companyId)
                ->where('source_key', $sourceKey)->lockForUpdate()->exists()) {
                return 0;
            }

            $batchId = DB::table('loyalty_migration_batches')->insertGetId([
                'company_id' => $companyId,
                'user_id' => $userId,
                'source_key' => $sourceKey,
                'row_count' => count($validRows),
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($pendingRows as $row) {
                DB::table('loyalty_migration_pending_rows')->insert([
                    'batch_id' => $batchId,
                    'company_id' => $companyId,
                    'source_key' => $sourceKey,
                    'row_number' => $row['row_number'],
                    'source_rows' => json_encode($row['source_row_numbers'] ?? [$row['row_number']], JSON_THROW_ON_ERROR),
                    'source_data' => json_encode(collect($row)->only(['name', 'identification', 'phone', 'email', 'awarded_points', 'used_points', 'balance'])->all(), JSON_THROW_ON_ERROR),
                    'reasons' => json_encode($row['errors'] ?? [], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $company = Company::query()->findOrFail($companyId);

            foreach ($validRows as $row) {
                $customer = Customer::withTrashed()->where('company_id', $companyId)->findOrFail($row['customer_id']);
                $account = $this->accounts->getOrCreateAccount($customer, $company);
                $context = [
                    'user_id' => $userId,
                    'source_type' => 'LoyaltyMigration',
                    'source_id' => $batchId,
                    'effective_at' => now(),
                ];

                if ($this->isLegacyInitialBalance($row)) {
                    $this->accounts->adjustPoints($account, $row['balance'], $context + [
                        'event_key' => "loyalty_migration:{$sourceKey}:{$row['row_number']}:legacy_initial_balance",
                        'description' => 'P37 · Saldo inicial legado migrado',
                        'metadata' => ['migration' => 'P37', 'kind' => 'legacy_initial_balance'],
                    ]);
                } elseif (bccomp($row['awarded_points'], '0', 4) > 0) {
                    $this->accounts->addPoints($account, $row['awarded_points'], LoyaltyMovement::TYPE_PROMOTION, $context + [
                        'event_key' => "loyalty_migration:{$sourceKey}:{$row['row_number']}:awarded",
                        'description' => 'P37 · Puntos otorgados migrados',
                        'metadata' => ['migration' => 'P37', 'kind' => 'awarded'],
                    ]);
                }
                if (bccomp($row['used_points'], '0', 4) > 0) {
                    $this->accounts->subtractPoints($account, $row['used_points'], LoyaltyMovement::TYPE_REDEMPTION, $context + [
                        'event_key' => "loyalty_migration:{$sourceKey}:{$row['row_number']}:used",
                        'description' => 'P37 · Puntos utilizados migrados',
                        'metadata' => ['migration' => 'P37', 'kind' => 'used'],
                    ]);
                }

                $account->refresh();
                if (bccomp((string) $account->balance, $row['balance'], 4) !== 0) {
                    throw ValidationException::withMessages([
                        'migrar_file' => "El saldo final no coincide para {$row['name']}.",
                    ]);
                }
            }

            return count($validRows);
        });
    }

    public function enqueue(array $preview, int $companyId, int $userId): LoyaltyMigrationRun
    {
        $summary = $this->validateForDispatch($preview, $companyId);
        $run = LoyaltyMigrationRun::query()->firstOrCreate(
            ['company_id' => $companyId, 'source_key' => $summary['source_key']],
            [
                'user_id' => $userId,
                'status' => LoyaltyMigrationRun::STATUS_PENDING,
                'preview_payload' => $preview,
                'valid_count' => $summary['valid_count'],
                'pending_count' => $summary['pending_count'],
                'consolidated_count' => $summary['consolidated_count'],
                'queued_at' => now(),
            ],
        );

        if ($run->wasRecentlyCreated) {
            ProcessLoyaltyMigration::dispatch($run->id)->afterCommit();
        }

        return $run;
    }

    public function retry(LoyaltyMigrationRun $run, int $companyId): LoyaltyMigrationRun
    {
        abort_unless((int) $run->company_id === $companyId, 404);

        $queued = LoyaltyMigrationRun::query()
            ->whereKey($run->id)
            ->where('company_id', $companyId)
            ->where('status', LoyaltyMigrationRun::STATUS_FAILED)
            ->update([
                'status' => LoyaltyMigrationRun::STATUS_PENDING,
                'queued_at' => now(),
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($queued === 1) {
            ProcessLoyaltyMigration::dispatch($run->id)->afterCommit();
        }

        return $run->fresh();
    }

    private function validateForDispatch(array $preview, int $companyId): array
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages([
                'migrar_file' => 'La vista previa no pertenece a la empresa activa.',
            ]);
        }

        $sourceKey = trim((string) ($preview['source_key'] ?? ''));
        $rows = collect($preview['rows'] ?? []);
        if ($sourceKey === '' || $rows->isEmpty() || $rows->contains(
            fn (array $row) => (string) ($row['source_key'] ?? '') !== $sourceKey
        )) {
            throw ValidationException::withMessages([
                'migrar_file' => 'El lote P37 no es válido. Genere nuevamente la vista previa.',
            ]);
        }

        $validCount = $rows->where('valid', true)->count();
        if ($validCount === 0) {
            throw ValidationException::withMessages([
                'migrar_file' => 'No hay filas válidas para importar.',
            ]);
        }

        return [
            'source_key' => $sourceKey,
            'valid_count' => $validCount,
            'pending_count' => $rows->where('valid', false)->count(),
            'consolidated_count' => $rows->sum(fn (array $row) => max(0, (int) ($row['consolidated_count'] ?? 1) - 1)),
        ];
    }

    public function resolveCustomers(array $preview, int $companyId, array $selections): array
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages([
                'migrar_file' => 'La vista previa no pertenece a la empresa activa.',
            ]);
        }

        $customerIndex = $this->customerIndex($companyId);
        $rows = array_map(function (array $row) use ($selections, $companyId): array {
            $rowNumber = (string) ($row['row_number'] ?? '');
            if (! array_key_exists($rowNumber, $selections)) {
                return $row;
            }

            $selectedId = filter_var($selections[$rowNumber], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $row['customer_id'] = $selectedId === false ? null : $selectedId;
            $row['manual_resolution'] = $selectedId === false ? null : ['customer_id' => $selectedId];

            return $this->withAccountSnapshot($row, $companyId);
        }, $preview['source_rows'] ?? $preview['rows'] ?? []);

        $preview['source_rows'] = $rows;
        $preview['rows'] = $this->prepareRows($rows, $companyId, $customerIndex);

        return $preview;
    }

    public function reusableManualResolutions(array $preview): array
    {
        $sourceKey = (string) ($preview['source_key'] ?? '');

        return collect($preview['rows'] ?? [])->filter(fn (array $row) => ! empty($row['manual_resolution']))
            ->flatMap(function (array $row) use ($sourceKey): array {
                return collect($row['source_row_numbers'] ?? [$row['row_number']])->mapWithKeys(fn (int $rowNumber) => [(string) $rowNumber => [
                    'source_key' => $sourceKey,
                    'row_number' => $rowNumber,
                    'normalized_name' => $row['normalized_name'],
                    'customer_id' => (int) $row['manual_resolution']['customer_id'],
                ]])->all();
            })->all();
    }

    public function storeManualResolutions(array $preview, int $companyId): void
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages([
                'migrar_file' => 'La vista previa no pertenece a la empresa activa.',
            ]);
        }

        $customerIndex = $this->customerIndex($companyId);
        $records = collect($this->reusableManualResolutions($preview))->filter(function (array $resolution) use ($customerIndex): bool {
            return $this->customerMatches($resolution['normalized_name'], $customerIndex)
                ->contains(fn (Customer $customer) => $customer->deleted_at === null
                    && (int) $customer->id === (int) $resolution['customer_id']);
        })->map(fn (array $resolution) => $resolution + [
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all();

        DB::transaction(function () use ($companyId, $preview, $records): void {
            DB::table('loyalty_migration_manual_resolutions')
                ->where('company_id', $companyId)
                ->where('source_key', $preview['source_key'])
                ->delete();

            if ($records !== []) {
                DB::table('loyalty_migration_manual_resolutions')->insert($records);
            }
        });
    }

    public function storedManualResolutions(int $companyId, string $sourceKey): array
    {
        return DB::table('loyalty_migration_manual_resolutions')
            ->where('company_id', $companyId)
            ->where('source_key', $sourceKey)
            ->get(['source_key', 'row_number', 'normalized_name', 'customer_id'])
            ->mapWithKeys(fn (object $resolution) => [(string) $resolution->row_number => (array) $resolution])
            ->all();
    }

    public function reuseManualResolutions(array $preview, int $companyId, array $resolutions): array
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages([
                'migrar_file' => 'La vista previa no pertenece a la empresa activa.',
            ]);
        }

        if ($resolutions === []) {
            return $preview;
        }

        $sourceKey = (string) ($preview['source_key'] ?? '');
        $customerIndex = $this->customerIndex($companyId);
        $rows = array_map(function (array $row) use ($resolutions, $sourceKey, $customerIndex, $companyId): array {
            $resolution = $resolutions[(string) ($row['row_number'] ?? '')] ?? null;
            if (! is_array($resolution)
                || ! hash_equals($sourceKey, (string) ($resolution['source_key'] ?? ''))
                || (int) ($resolution['row_number'] ?? 0) !== (int) ($row['row_number'] ?? 0)
                || (string) ($resolution['normalized_name'] ?? '') !== (string) ($row['normalized_name'] ?? '')) {
                return $row;
            }

            $selectedId = (int) ($resolution['customer_id'] ?? 0);
            $selected = $this->customerMatches($row['normalized_name'] ?? null, $customerIndex)
                ->first(fn (Customer $customer) => $customer->deleted_at === null && (int) $customer->id === $selectedId);
            if (! $selected) {
                $row['customer_id'] = $selectedId;
                $row['manual_resolution'] = ['customer_id' => $selectedId];

                return $this->withAccountSnapshot($row, $companyId);
            }

            $row['customer_id'] = $selectedId;
            $row['manual_resolution'] = ['customer_id' => $selectedId];

            return $this->withAccountSnapshot($row, $companyId);
        }, $preview['source_rows'] ?? $preview['rows'] ?? []);

        $preview['source_rows'] = $rows;
        $preview['rows'] = $this->prepareRows($rows, $companyId, $customerIndex);

        return $preview;
    }

    private function resolveHeaders(array $headers): array
    {
        $nonEmpty = collect($headers)->filter(fn ($header) => trim((string) $header) !== '');
        $resolved = [];
        foreach ($nonEmpty as $column => $header) {
            $field = self::MAP[$this->headerKey($header)] ?? null;
            if ($field === null) {
                throw ValidationException::withMessages([
                    'migrar_file' => 'P37 solo admite sus cuatro columnas base y, opcionalmente, IDENTIFICACION, TELEFONO y EMAIL.',
                ]);
            }
            $resolved[$column] = $field;
        }

        if (array_diff(self::REQUIRED_FIELDS, array_values($resolved)) !== []) {
            throw ValidationException::withMessages([
                'migrar_file' => 'Falta una columna obligatoria de la plantilla P37 vigente.',
            ]);
        }

        return $resolved;
    }

    private function normalizeRow(array $data, int $rowNumber, int $companyId, Collection $customerIndex): array
    {
        $name = $this->text($data['name'] ?? null);
        $normalizedName = $this->normalizeName($name);
        $matches = $this->customerMatches($normalizedName, $customerIndex);
        $identification = $this->text($data['identification'] ?? null);
        $phone = $this->text($data['phone'] ?? null);
        $email = $this->text($data['email'] ?? null);
        $customer = $matches->count() === 1
            ? $matches->first()
            : $this->customerByEvidence($matches, $identification, $phone, $email);

        return $this->withAccountSnapshot([
            'row_number' => $rowNumber,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'customer_id' => $customer?->id,
            'customer_match_count' => $matches->count(),
            'customer_candidates' => $this->customerCandidates($matches),
            'identification' => $identification,
            'phone' => $phone,
            'email' => $email,
            'resolution_method' => $customer && $matches->count() > 1 ? $this->resolutionMethod($customer, $identification, $phone, $email) : null,
            'awarded_points' => $this->decimal($data['awarded_points'] ?? null),
            'used_points' => $this->decimal($data['used_points'] ?? null),
            'balance' => $this->decimal($data['balance'] ?? null),
            'valid' => true,
            'errors' => [],
        ], $companyId);
    }

    private function prepareRows(array $rows, int $companyId, Collection $customerIndex): array
    {
        return $this->consolidateRows($this->validateRows($rows, $companyId, $customerIndex), $companyId);
    }

    private function consolidateRows(array $rows, int $companyId): array
    {
        $result = collect($rows)->filter(fn (array $row) => empty($row['customer_id']))->values();

        foreach (collect($rows)->filter(fn (array $row) => ! empty($row['customer_id']))->groupBy('customer_id') as $customerRows) {
            if ($customerRows->count() === 1) {
                $result->push($customerRows->first());

                continue;
            }

            $group = $customerRows->values()->all();
            $legacy = collect($group)->filter(fn (array $row) => $this->isLegacyInitialBalance($row));
            $row = $group[0];
            $row['source_row_numbers'] = collect($group)->pluck('row_number')->all();
            $row['consolidated_count'] = count($group);

            if ($customerRows->contains(fn (array $item) => ! $item['valid'])) {
                $row['valid'] = false;
                $row['pending'] = true;
                $row['consolidation_method'] = 'incompatible';
                $row['errors'] = collect(array_merge(
                    $customerRows->flatMap(fn (array $item) => $item['errors'])->values()->all(),
                    [[
                        'field' => 'fila',
                        'message' => 'El cliente tiene filas repetidas con errores; no se importará parcialmente.',
                    ]],
                ))->unique(fn (array $error) => $error['field'].'|'.$error['message'])->values()->all();
                $result->push($row);

                continue;
            }

            if ($legacy->count() === count($group)) {
                $balances = $legacy->pluck('balance')->map(fn (string $balance) => bcadd($balance, '0', 4))->unique();
                if ($balances->count() === 1) {
                    $row['consolidation_method'] = 'legacy_snapshot_identical';
                    $result->push($this->withAccountSnapshot($row, $companyId));

                    continue;
                }
                $reason = 'Los snapshots legacy repetidos tienen saldos finales distintos; no es seguro sumarlos.';
            } elseif ($legacy->isEmpty()) {
                $row['awarded_points'] = collect($group)->reduce(fn (string $sum, array $item) => bcadd($sum, $item['awarded_points'], 4), '0.0000');
                $row['used_points'] = collect($group)->reduce(fn (string $sum, array $item) => bcadd($sum, $item['used_points'], 4), '0.0000');
                $row['balance'] = bcsub($row['awarded_points'], $row['used_points'], 4);
                $row['consolidation_method'] = 'historical_totals_sum';
                $result->push($this->withAccountSnapshot($row, $companyId));

                continue;
            } else {
                $reason = 'El mismo cliente mezcla snapshot legacy con totales históricos; no se puede determinar un saldo único con certeza.';
            }

            $row['valid'] = false;
            $row['consolidation_method'] = 'incompatible';
            $row['errors'] = [['field' => 'saldo', 'message' => $reason]];
            $result->push($row);
        }

        return $result->sortBy('row_number')->values()->all();
    }

    private function validateRows(array $rows, int $companyId, Collection $customerIndex): array
    {
        foreach ($rows as $index => $row) {
            $errors = [];
            $matches = $this->customerMatches($row['normalized_name'] ?? null, $customerIndex);
            $selected = $matches->first(fn (Customer $customer) => (int) $customer->id === (int) ($row['customer_id'] ?? 0));
            $row['customer_match_count'] = $matches->count();
            $row['customer_candidates'] = $this->customerCandidates($matches);

            if (! $row['name']) {
                $errors[] = ['field' => 'nombre', 'message' => 'El nombre es obligatorio.'];
            } elseif ($matches->isEmpty()) {
                $errors[] = ['field' => 'nombre', 'message' => 'El cliente no existe en la empresa activa.'];
            } elseif ($matches->count() > 1 && ! $row['customer_id']) {
                $errors[] = ['field' => 'nombre', 'message' => 'Hay más de un cliente con este nombre normalizado; seleccione el cliente correcto.'];
            } elseif (! $selected || (! empty($row['manual_resolution']) && $selected->deleted_at !== null)) {
                $errors[] = ['field' => 'nombre', 'message' => 'El cliente cambió después de la vista previa; cargue el archivo nuevamente.'];
            }

            foreach (['awarded_points' => 'puntos_otorgados', 'used_points' => 'puntos_utilizados', 'balance' => 'saldo'] as $field => $label) {
                if (! $this->validDecimal($row[$field] ?? null)) {
                    $errors[] = ['field' => $label, 'message' => 'Es obligatorio, no negativo y admite máximo cuatro decimales.'];
                }
            }

            if ($this->validDecimal($row['awarded_points'] ?? null)
                && $this->validDecimal($row['used_points'] ?? null)
                && $this->validDecimal($row['balance'] ?? null)
                && ! $this->isLegacyInitialBalance($row)) {
                $expected = bcsub($row['awarded_points'], $row['used_points'], 4);
                if (bccomp($expected, $row['balance'], 4) !== 0) {
                    $errors[] = ['field' => 'saldo', 'message' => "No concilia; el saldo esperado es {$expected}."];
                }
            }

            $account = $row['customer_id']
                ? LoyaltyAccount::query()->where('company_id', $companyId)->where('customer_id', $row['customer_id'])->first()
                : null;
            if ($account && (bccomp((string) $account->balance, '0', 4) !== 0 || $account->movements()->exists())) {
                $errors[] = ['field' => 'saldo', 'message' => 'La cuenta ya tiene saldo o movimientos operativos; P37 no los sobrescribe.'];
            }
            if (($row['current_account_id'] ?? null) && (! $account
                || bccomp((string) $account->balance, (string) ($row['current_balance'] ?? '0'), 4) !== 0
                || $account->movements()->count() !== (int) ($row['current_movement_count'] ?? 0))) {
                $errors[] = ['field' => 'saldo', 'message' => 'La cuenta cambió después de la vista previa; cargue el archivo nuevamente.'];
            }

            $row['errors'] = collect($errors)->unique(fn ($error) => $error['field'].'|'.$error['message'])->values()->all();
            $row['valid'] = $row['errors'] === [];
            $row['pending'] = ! $row['valid'];
            $rows[$index] = $row;
        }

        return $rows;
    }

    private function customerIndex(int $companyId): Collection
    {
        return Customer::withTrashed()
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'identification', 'phone', 'mobile', 'email', 'deleted_at'])
            ->groupBy(fn (Customer $customer) => $this->normalizeName($customer->name));
    }

    private function customerMatches(?string $normalizedName, Collection $customerIndex): Collection
    {
        return $normalizedName === null || $normalizedName === ''
            ? collect()
            : $customerIndex->get($normalizedName, collect())->values();
    }

    private function customerCandidates(Collection $matches): array
    {
        return $matches->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'identification' => $customer->identification,
            'phone' => $customer->mobile ?: $customer->phone,
            'email' => $customer->email,
        ])->values()->all();
    }

    private function customerByEvidence(Collection $matches, ?string $identification, ?string $phone, ?string $email): ?Customer
    {
        if ($identification !== null) {
            $identified = $matches->filter(fn (Customer $customer) => $this->normalizeEvidence($customer->identification) === $this->normalizeEvidence($identification));
            if ($identified->count() === 1) {
                return $identified->first();
            }

            return null;
        }

        if ($phone !== null) {
            $normalized = $this->phones->normalizePhone($phone);
            $identified = $matches->filter(fn (Customer $customer) => collect([$customer->phone, $customer->mobile])
                ->contains(fn (?string $value) => $this->phones->normalizePhone($value) === $normalized));
            if ($identified->count() === 1) {
                return $identified->first();
            }

            return null;
        }

        if ($email !== null) {
            $normalized = $this->normalizeEvidence($email);
            $identified = $matches->filter(fn (Customer $customer) => $this->normalizeEvidence($customer->email) === $normalized);

            return $identified->count() === 1 ? $identified->first() : null;
        }

        return null;
    }

    private function resolutionMethod(Customer $customer, ?string $identification, ?string $phone, ?string $email): ?string
    {
        if ($identification !== null && $this->normalizeEvidence($customer->identification) === $this->normalizeEvidence($identification)) {
            return 'identification';
        }
        if ($phone !== null && collect([$customer->phone, $customer->mobile])->contains(
            fn (?string $value) => $this->phones->normalizePhone($value) === $this->phones->normalizePhone($phone)
        )) {
            return 'phone';
        }
        if ($email !== null && $this->normalizeEvidence($customer->email) === $this->normalizeEvidence($email)) {
            return 'email';
        }

        return null;
    }

    private function normalizeEvidence(?string $value): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : Str::lower($value);
    }

    private function withAccountSnapshot(array $row, int $companyId): array
    {
        $account = ! empty($row['customer_id'])
            ? LoyaltyAccount::query()->where('company_id', $companyId)->where('customer_id', $row['customer_id'])->first()
            : null;

        $row['current_account_id'] = $account?->id;
        $row['current_balance'] = $account ? bcadd((string) $account->balance, '0', 4) : null;
        $row['current_movement_count'] = $account?->movements()->count() ?? 0;

        return $row;
    }

    private function sourceKey(array $rows): string
    {
        $payload = array_map(function (array $row): array {
            $source = [$row['normalized_name'], $row['awarded_points'], $row['used_points'], $row['balance']];
            $evidence = array_filter([
                'identification' => $this->normalizeEvidence($row['identification'] ?? null),
                'phone' => $this->phones->normalizePhone($row['phone'] ?? null),
                'email' => $this->normalizeEvidence($row['email'] ?? null),
            ], fn (?string $value) => $value !== null);

            return $evidence === [] ? $source : [$source, $evidence];
        }, $rows);

        return 'P37-SIMPLE-'.strtoupper(substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 40));
    }

    private function headerKey(mixed $header): string
    {
        return Str::of((string) $header)->replace("\xEF\xBB\xBF", '')->ascii()->lower()->squish()
            ->replaceMatches('/[\s_-]+/', '_')->trim('_')->toString();
    }

    private function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = str_replace(
            ["\u{00C3}\u{2018}", "\u{00C3}\u{0091}", "\u{00C3}\u{00B1}", "\u{00C2}\u{00B4}"],
            ["\u{00D1}", "\u{00D1}", "\u{00F1}", "\u{00B4}"],
            $name,
        );
        $name = preg_replace('/[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{2018}\x{2019}\x{2032}]+/u', ' ', $name) ?? $name;

        return Str::of($name)->squish()->ascii()->lower()->toString();
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?string
    {
        return $this->text($value);
    }

    private function validDecimal(?string $value): bool
    {
        return $value !== null && preg_match('/^\d+(?:\.\d{1,4})?$/', $value) === 1;
    }

    private function isLegacyInitialBalance(array $row): bool
    {
        return $this->validDecimal($row['awarded_points'] ?? null)
            && $this->validDecimal($row['used_points'] ?? null)
            && $this->validDecimal($row['balance'] ?? null)
            && bccomp($row['awarded_points'], '0', 4) === 0
            && bccomp($row['used_points'], '0', 4) === 0
            && bccomp($row['balance'], '0', 4) > 0;
    }
}
