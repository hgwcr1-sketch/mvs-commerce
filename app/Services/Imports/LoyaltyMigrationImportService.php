<?php

namespace App\Services\Imports;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Services\Loyalty\LoyaltyAccountService;
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
    ];

    public function __construct(private readonly LoyaltyAccountService $accounts) {}

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

        return ['company_id' => $companyId, 'source_key' => $sourceKey, 'rows' => $this->validateRows($rows, $companyId, $customerIndex)];
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
        if ($invalid = collect($rows)->firstWhere('valid', false)) {
            $error = $invalid['errors'][0] ?? ['field' => 'fila', 'message' => 'dato inválido'];
            throw ValidationException::withMessages([
                'migrar_file' => "La importación cambió o contiene errores. Fila {$invalid['row_number']}, {$error['field']}: {$error['message']}",
            ]);
        }

        $sourceKey = (string) ($preview['source_key'] ?? $rows[0]['source_key'] ?? '');

        return DB::transaction(function () use ($rows, $companyId, $userId, $sourceKey): int {
            if (DB::table('loyalty_migration_batches')->where('company_id', $companyId)
                ->where('source_key', $sourceKey)->lockForUpdate()->exists()) {
                return 0;
            }

            $batchId = DB::table('loyalty_migration_batches')->insertGetId([
                'company_id' => $companyId,
                'user_id' => $userId,
                'source_key' => $sourceKey,
                'row_count' => count($rows),
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $company = Company::query()->findOrFail($companyId);

            foreach ($rows as $row) {
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

            return count($rows);
        });
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
        }, $preview['rows'] ?? []);

        $preview['rows'] = $this->validateRows($rows, $companyId, $customerIndex);

        return $preview;
    }

    public function reusableManualResolutions(array $preview): array
    {
        $sourceKey = (string) ($preview['source_key'] ?? '');

        return collect($preview['rows'] ?? [])->filter(fn (array $row) => ! empty($row['manual_resolution']))
            ->mapWithKeys(function (array $row) use ($sourceKey): array {
                return [(string) $row['row_number'] => [
                    'source_key' => $sourceKey,
                    'row_number' => (int) $row['row_number'],
                    'normalized_name' => $row['normalized_name'],
                    'customer_id' => (int) $row['manual_resolution']['customer_id'],
                ]];
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
        }, $preview['rows'] ?? []);

        $preview['rows'] = $this->validateRows($rows, $companyId, $customerIndex);

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
                    'migrar_file' => 'P37 requiere únicamente NOMBRE, PUNTOS OTORGADOS, PUNTOS UTILIZADOS y SALDO.',
                ]);
            }
            $resolved[$column] = $field;
        }

        if (count($resolved) !== 4 || array_diff(array_values(self::MAP), array_values($resolved)) !== []) {
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
        $customer = $matches->count() === 1 ? $matches->first() : null;

        return $this->withAccountSnapshot([
            'row_number' => $rowNumber,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'customer_id' => $customer?->id,
            'customer_match_count' => $matches->count(),
            'customer_candidates' => $this->customerCandidates($matches),
            'awarded_points' => $this->decimal($data['awarded_points'] ?? null),
            'used_points' => $this->decimal($data['used_points'] ?? null),
            'balance' => $this->decimal($data['balance'] ?? null),
            'valid' => true,
            'errors' => [],
        ], $companyId);
    }

    private function validateRows(array $rows, int $companyId, Collection $customerIndex): array
    {
        $seenCustomers = [];

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

            if ($row['customer_id'] && isset($seenCustomers[$row['customer_id']])) {
                $errors[] = ['field' => 'nombre', 'message' => 'El cliente está repetido en el archivo.'];
            }
            if ($row['customer_id']) {
                $seenCustomers[$row['customer_id']] = true;
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
        $payload = array_map(fn (array $row) => [
            $row['normalized_name'], $row['awarded_points'], $row['used_points'], $row['balance'],
        ], $rows);

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
