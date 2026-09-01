<?php

namespace App\Services\Imports;

use App\Models\Company;
use App\Models\Customer;
use App\Services\PhoneNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CustomerImportService
{
    public const HEADERS = [
        'tipo_cliente*', 'tipo_identificacion', 'identificacion', 'nombre*', 'nombre_comercial',
        'codigo_pais', 'telefono', 'movil', 'correo', 'direccion', 'limite_credito',
        'dias_credito', 'nivel_precio', 'fecha_nacimiento', 'activo',
    ];

    private const HEADER_MAP = [
        'tipo_cliente' => 'customer_type',
        'tipo_identificacion' => 'identification_type',
        'identificacion' => 'identification',
        'nombre' => 'name',
        'nombre_comercial' => 'commercial_name',
        'codigo_pais' => 'phone_country_code',
        'telefono' => 'phone',
        'movil' => 'mobile',
        'correo' => 'email',
        'direccion' => 'address',
        'limite_credito' => 'credit_limit',
        'dias_credito' => 'credit_days',
        'nivel_precio' => 'price_level',
        'fecha_nacimiento' => 'birth_date',
        'activo' => 'is_active',
    ];

    private const FIELD_LABELS = [
        'customer_type' => 'tipo_cliente', 'identification_type' => 'tipo_identificacion',
        'identification' => 'identificacion', 'name' => 'nombre', 'commercial_name' => 'nombre_comercial',
        'phone_country_code' => 'codigo_pais', 'phone' => 'telefono', 'mobile' => 'movil',
        'email' => 'correo', 'address' => 'direccion', 'credit_limit' => 'limite_credito',
        'credit_days' => 'dias_credito', 'price_level' => 'nivel_precio',
        'birth_date' => 'fecha_nacimiento', 'is_active' => 'activo',
    ];

    public function __construct(private readonly PhoneNumberService $phones) {}

    public function preview(string $path, int $companyId): array
    {
        Company::query()->findOrFail($companyId);
        $sheetRows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);

        if (count($sheetRows) < 2) {
            throw ValidationException::withMessages([
                'customer_file' => 'El archivo debe incluir encabezados y al menos una fila de clientes.',
            ]);
        }

        $headers = $this->resolveHeaders(array_shift($sheetRows));
        $rows = [];

        foreach ($sheetRows as $offset => $values) {
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
                'customer_file' => 'El archivo no contiene filas de clientes para revisar.',
            ]);
        }

        return $this->validateRows($this->consolidateRows($this->normalizeRepeatedEmails($rows)), $companyId);
    }

    public function confirm(array $preview, int $companyId): int
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages([
                'customer_file' => 'La vista previa no pertenece a la empresa activa.',
            ]);
        }

        $rows = $this->validateRows($preview['rows'] ?? [], $companyId);
        $invalid = collect($rows)->firstWhere('valid', false);

        if ($invalid !== null) {
            throw ValidationException::withMessages([
                'customer_file' => 'La importación cambió o contiene errores. Vuelva a cargar el archivo y revise la fila '.($invalid['row_number'] ?? '?').'.',
            ]);
        }

        return DB::transaction(function () use ($rows, $companyId): int {
            $importableRows = collect($rows)->reject(fn (array $row) => $row['skipped'])->values();

            foreach ($importableRows as $row) {
                Customer::create(['company_id' => $companyId, ...$this->attributes($row)]);
            }

            return $importableRows->count();
        });
    }

    private function resolveHeaders(array $headers): array
    {
        $resolved = [];
        foreach ($headers as $column => $header) {
            $key = trim(Str::of((string) $header)->ascii()->lower()->replace([' ', '-', '*'], ['_', '_', ''])->toString(), '_');
            $resolved[$column] = self::HEADER_MAP[$key] ?? null;
        }

        foreach (['customer_type', 'name'] as $required) {
            if (! in_array($required, $resolved, true)) {
                throw ValidationException::withMessages([
                    'customer_file' => 'Falta la columna obligatoria '.array_search($required, self::HEADER_MAP, true).'. Descargue la plantilla vigente.',
                ]);
            }
        }

        return $resolved;
    }

    private function normalizeRow(array $data, int $rowNumber, int $companyId): array
    {
        $companyCode = Company::query()->whereKey($companyId)->value('default_phone_country_code');
        $countryCode = $this->phones->normalizeCountryCode($this->nullable($data['phone_country_code'] ?? null));
        $effectiveCountryCode = $countryCode ?? $this->phones->normalizeCountryCode($companyCode);
        [$phone, $phoneWarning] = $this->normalizeImportedPhone($data['phone'] ?? null, $effectiveCountryCode, 'telefono');
        [$mobile, $mobileWarning] = $this->normalizeImportedPhone($data['mobile'] ?? null, $effectiveCountryCode, 'movil');
        [$birthDate, $birthDateWarning] = $this->normalizeBirthDate($data['birth_date'] ?? null);
        [$email, $emailWarning] = $this->normalizeImportedEmail($data['email'] ?? null);
        [$name, $nameWarning] = $this->normalizeLegacyText(trim((string) ($data['name'] ?? '')), 'nombre');
        [$commercialName, $commercialNameWarning] = $this->normalizeLegacyText($this->nullable($data['commercial_name'] ?? null), 'nombre_comercial');
        [$address, $addressWarning] = $this->normalizeLegacyText($this->nullable($data['address'] ?? null), 'direccion');

        return [
            'row_number' => $rowNumber,
            'customer_type' => Str::lower(trim((string) ($data['customer_type'] ?? ''))),
            'identification_type' => $this->nullable($data['identification_type'] ?? null),
            'identification' => $this->nullable($data['identification'] ?? null),
            'name' => $name,
            'commercial_name' => $commercialName,
            'phone_country_code' => ($phone !== null || $mobile !== null)
                ? $effectiveCountryCode
                : null,
            'phone' => $phone,
            'mobile' => $mobile,
            'email' => $email,
            'address' => $address,
            'credit_limit' => $this->nullable($data['credit_limit'] ?? null) ?? '0',
            'credit_days' => $this->nullable($data['credit_days'] ?? null) ?? '0',
            'price_level' => Str::lower($this->nullable($data['price_level'] ?? null) ?? 'normal'),
            'birth_date' => $birthDate,
            'is_active' => $this->booleanValue($data['is_active'] ?? null),
            'valid' => true,
            'skipped' => false,
            'merged_into_row' => null,
            'source_rows' => [$rowNumber],
            'merge_errors' => [],
            'errors' => [],
            'warnings' => array_values(array_filter([
                $phoneWarning, $mobileWarning, $birthDateWarning, $emailWarning,
                $nameWarning, $commercialNameWarning, $addressWarning,
            ])),
        ];
    }

    private function validateRows(array $rows, int $companyId): array
    {
        $seen = ['phone' => [], 'email' => []];

        foreach ($rows as $index => $row) {
            $row['errors'] = $row['merge_errors'] ?? [];
            $row['warnings'] ??= [];

            if ($row['skipped']) {
                $row['valid'] = true;
                $rows[$index] = $row;

                continue;
            }

            if ($row['email'] !== null) {
                if (isset($seen['email'][$row['email']])) {
                    $row['warnings'][] = $this->warning(
                        'correo',
                        'El correo se repite desde la fila '.$seen['email'][$row['email']].'; se importará vacío en esta fila.'
                    );
                    $row['email'] = null;
                } else {
                    $seen['email'][$row['email']] = $row['row_number'];
                }
            }

            $validator = Validator::make($row, [
                'customer_type' => ['required', 'in:individual,company'],
                'identification_type' => ['nullable', 'in:01,02,03,04,05'],
                'identification' => ['nullable', 'string', 'max:50'],
                'name' => ['required', 'string', 'max:150'],
                'commercial_name' => ['nullable', 'string', 'max:150'],
                'phone_country_code' => ['nullable', 'regex:/^\+[1-9]\d{0,3}$/'],
                'phone' => ['nullable', 'regex:/^\d{4,15}$/'],
                'mobile' => ['nullable', 'regex:/^\d{4,15}$/'],
                'email' => ['nullable', 'email', 'max:150'],
                'address' => ['nullable', 'string'],
                'credit_limit' => ['required', 'decimal:0,2', 'gte:0'],
                'credit_days' => ['required', 'integer', 'gte:0'],
                'price_level' => ['required', 'in:normal,wholesale,a,b,c'],
                'birth_date' => ['nullable', 'date_format:Y-m-d'],
                'is_active' => ['required', 'boolean'],
            ], [], self::FIELD_LABELS);

            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $row['errors'][] = ['field' => self::FIELD_LABELS[$field] ?? $field, 'message' => $message];
                }
            }

            $identities = [
                'phone' => array_values(array_unique(array_filter([$row['phone'] ?? null, $row['mobile'] ?? null]))),
            ];

            foreach ($identities as $kind => $values) {
                foreach ($values as $value) {
                    if (isset($seen[$kind][$value])) {
                        $row['errors'][] = ['field' => self::FIELD_LABELS[$kind] ?? $kind, 'message' => 'El valor se repite en la fila '.$seen[$kind][$value].' del archivo.'];
                    } else {
                        $seen[$kind][$value] = $row['row_number'];
                    }
                }
            }

            $this->appendExistingErrors($row, $companyId);
            $row['valid'] = $row['errors'] === [];
            $rows[$index] = $row;
        }

        return $rows;
    }

    private function appendExistingErrors(array &$row, int $companyId): void
    {
        $query = Customer::withTrashed()->where('company_id', $companyId);
        $matches = [];

        if ($row['identification'] !== null && (clone $query)->where('identification', $row['identification'])->exists()) {
            $matches[] = ['field' => 'identificacion', 'message' => 'Ya existe un cliente de esta empresa con esa identificación.'];
        }

        $companyPhones = null;
        foreach (array_unique(array_filter([$row['phone'], $row['mobile']])) as $phone) {
            $companyPhones ??= (clone $query)->get(['phone', 'mobile']);
            $exists = $companyPhones->contains(fn (Customer $customer) => in_array($phone, array_filter([
                $this->phones->normalizePhone($customer->phone),
                $this->phones->normalizePhone($customer->mobile),
            ]), true));
            if ($exists) {
                $matches[] = ['field' => 'telefono', 'message' => 'Ya existe un cliente de esta empresa con ese teléfono o móvil.'];
            }
        }

        if ($row['email'] !== null && (clone $query)->whereRaw('LOWER(email) = ?', [$row['email']])->exists()) {
            $matches[] = ['field' => 'correo', 'message' => 'Ya existe un cliente de esta empresa con ese correo.'];
        }

        $row['errors'] = [...$row['errors'], ...$matches];
    }

    private function attributes(array $row): array
    {
        return collect($row)->except([
            'row_number', 'valid', 'skipped', 'merged_into_row', 'source_rows', 'merge_errors', 'errors', 'warnings',
        ])->all() + [
            'accepts_email_invoice' => true,
            'points' => 0,
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function booleanValue(mixed $value): bool
    {
        $value = Str::lower(trim((string) ($value ?? 'si')));

        return ! in_array($value, ['0', 'no', 'n', 'false', 'inactivo'], true);
    }

    private function consolidateRows(array $rows): array
    {
        $identificationIndex = [];
        $contactIndex = ['phone' => [], 'email' => []];

        foreach ($rows as $index => &$row) {
            $identificationMatches = $row['identification'] === null
                ? []
                : array_keys($identificationIndex[$row['identification']] ?? []);
            $contactMatches = $this->indexedContactMatches($row, $contactIndex);
            $mutatedCanonical = null;

            if ($identificationMatches !== []) {
                $candidate = $identificationMatches[0];
                if (array_diff($contactMatches, [$candidate]) !== []) {
                    $row['merge_errors'][] = $this->mergeConflict('identificacion', $row, 'La identificación y los contactos apuntan a clientes distintos dentro del archivo.');
                } else {
                    $this->omitRepeatedIdentification($rows[$candidate], $row);
                    $mutatedCanonical = $candidate;
                }
            } elseif (count($contactMatches) > 1) {
                $row['merge_errors'][] = $this->mergeConflict('contacto', $row, 'El teléfono/móvil o correo coincide con más de un cliente del archivo.');
            } elseif (count($contactMatches) === 1) {
                $candidate = $contactMatches[0];
                $candidateIdentification = $rows[$candidate]['identification'];
                if ($candidateIdentification !== null && $row['identification'] !== null && $candidateIdentification !== $row['identification']) {
                    $row['merge_errors'][] = $this->mergeConflict('identificacion', $row, 'El contacto coincide, pero las identificaciones son diferentes.');
                } else {
                    $this->mergeRows($rows[$candidate], $row);
                    $mutatedCanonical = $candidate;
                }
            }

            if ($mutatedCanonical !== null) {
                $this->indexCanonicalRow($rows[$mutatedCanonical], $mutatedCanonical, $identificationIndex, $contactIndex);
            }

            if (! $row['skipped']) {
                $this->indexCanonicalRow($row, $index, $identificationIndex, $contactIndex);
            }
        }
        unset($row);

        return $rows;
    }

    private function indexedContactMatches(array $row, array $contactIndex): array
    {
        $matches = [];

        foreach (array_unique(array_filter([$row['phone'], $row['mobile']])) as $phone) {
            foreach ($contactIndex['phone'][$phone] ?? [] as $index => $_) {
                $matches[$index] = true;
            }
        }

        if ($row['email'] !== null) {
            foreach ($contactIndex['email'][$row['email']] ?? [] as $index => $_) {
                $matches[$index] = true;
            }
        }

        $indexes = array_keys($matches);
        sort($indexes, SORT_NUMERIC);

        return $indexes;
    }

    private function indexCanonicalRow(array $row, int $index, array &$identificationIndex, array &$contactIndex): void
    {
        if ($row['identification'] !== null) {
            $identificationIndex[$row['identification']][$index] = true;
        }

        foreach (array_unique(array_filter([$row['phone'], $row['mobile']])) as $phone) {
            $contactIndex['phone'][$phone][$index] = true;
        }

        if ($row['email'] !== null) {
            $contactIndex['email'][$row['email']][$index] = true;
        }
    }

    private function normalizeRepeatedEmails(array $rows): array
    {
        $seen = [];

        foreach ($rows as &$row) {
            if ($row['email'] === null) {
                continue;
            }

            if (isset($seen[$row['email']])) {
                $this->appendWarningOnce($row, $this->warning(
                    'correo',
                    'El correo se repite desde la fila '.$seen[$row['email']].'; se importará vacío en esta fila.'
                ));
                $row['email'] = null;

                continue;
            }

            $seen[$row['email']] = $row['row_number'];
        }
        unset($row);

        return $rows;
    }

    private function omitRepeatedIdentification(array &$canonical, array &$duplicate): void
    {
        $duplicate['skipped'] = true;
        $duplicate['merged_into_row'] = $canonical['row_number'];
        $canonical['source_rows'] = array_values(array_unique([...$canonical['source_rows'], ...$duplicate['source_rows']]));

        $this->appendWarningOnce($duplicate, $this->warning(
            'identificacion',
            'La identificación ya apareció en la fila '.$canonical['row_number'].'; se conservará la primera aparición y esta fila se omitirá.'
        ));
    }

    private function mergeRows(array &$canonical, array &$duplicate): void
    {
        $duplicate['skipped'] = true;
        $duplicate['merged_into_row'] = $canonical['row_number'];
        $canonical['source_rows'] = array_values(array_unique([...$canonical['source_rows'], ...$duplicate['source_rows']]));

        foreach ([
            'customer_type', 'identification_type', 'identification', 'name', 'commercial_name',
            'phone_country_code', 'email', 'address', 'credit_limit', 'credit_days', 'price_level',
            'birth_date', 'is_active',
        ] as $field) {
            $current = $canonical[$field];
            $incoming = $duplicate[$field];

            if ($this->isEmptyMergeValue($current) && ! $this->isEmptyMergeValue($incoming)) {
                $canonical[$field] = $incoming;
            } elseif (! $this->isEmptyMergeValue($current) && ! $this->isEmptyMergeValue($incoming) && ! $this->sameMergeValue($current, $incoming)) {
                $canonical['merge_errors'][] = $this->mergeConflict(
                    self::FIELD_LABELS[$field] ?? $field,
                    $duplicate,
                    'Las filas '.$canonical['row_number'].' y '.$duplicate['row_number'].' contienen valores incompatibles; requiere revisión manual.'
                );
            }
        }

        $phones = array_values(array_unique(array_filter([
            $canonical['phone'], $canonical['mobile'], $duplicate['phone'], $duplicate['mobile'],
        ])));
        if (count($phones) <= 2) {
            $canonical['phone'] = $phones[0] ?? null;
            $canonical['mobile'] = $phones[1] ?? null;
        } else {
            $canonical['merge_errors'][] = $this->mergeConflict(
                'telefono',
                $duplicate,
                'Las filas consolidadas contienen más de dos teléfonos diferentes; requiere revisión manual.'
            );
        }

        $this->appendWarningOnce($canonical, $this->warning(
            'consolidacion',
            'Se consolidaron '.count($canonical['source_rows']).' filas originales en el cliente de la fila '.$canonical['row_number'].'.'
        ));
        $this->appendWarningOnce($duplicate, $this->warning(
            'consolidacion',
            'Fila consolidada en el cliente de la fila '.$canonical['row_number'].'; no creará otro cliente.'
        ));
    }

    private function isEmptyMergeValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function sameMergeValue(mixed $first, mixed $second): bool
    {
        if (is_bool($first) || is_bool($second)) {
            return (bool) $first === (bool) $second;
        }

        return mb_strtolower(trim((string) $first)) === mb_strtolower(trim((string) $second));
    }

    private function mergeConflict(string $field, array $row, string $message): array
    {
        return ['field' => $field, 'message' => $message, 'row_number' => $row['row_number']];
    }

    private function normalizeImportedPhone(mixed $value, ?string $countryCode, string $field): array
    {
        $original = $this->nullable($value);
        if ($original === null) {
            return [null, null];
        }

        $normalized = $this->phones->normalizePhone($original);
        if (preg_match('/^\d{4,15}$/', (string) $normalized)) {
            return [$normalized, null];
        }

        if (preg_match('/^\d{1,3}(,\d{3})+$/', $original)) {
            $digits = str_replace(',', '', $original);
            $countryDigits = ltrim((string) $countryCode, '+');

            if (strlen($digits) === 8) {
                return [$digits, $this->warning($field, 'Se quitaron separadores de miles del teléfono heredado.')];
            }

            foreach ([$countryDigits, $countryDigits.$countryDigits] as $prefix) {
                if ($prefix !== '' && str_starts_with($digits, $prefix) && strlen(substr($digits, strlen($prefix))) === 8) {
                    return [substr($digits, strlen($prefix)), $this->warning($field, 'Se recuperó el teléfono local eliminando separadores y prefijo(s) de país repetidos.')];
                }
            }
        }

        return [null, $this->warning($field, 'El valor heredado es inválido o ambiguo; se importará vacío.')];
    }

    private function normalizeBirthDate(mixed $value): array
    {
        $original = $this->nullable($value);
        if ($original === null) {
            return [null, $this->warning('fecha_nacimiento', 'La fecha está vacía; se importará vacía.')];
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $original);
        $valid = $date !== false
            && $date->format('Y-m-d') === $original
            && \DateTimeImmutable::getLastErrors() === false;

        return $valid
            ? [$original, null]
            : [null, $this->warning('fecha_nacimiento', 'La fecha heredada es inválida o ambigua; se importará vacía.')];
    }

    private function normalizeImportedEmail(mixed $value): array
    {
        $email = $this->nullable($value);
        if ($email === null) {
            return [null, null];
        }

        $email = Str::lower($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 150
            ? [$email, null]
            : [null, $this->warning('correo', 'El correo heredado es inválido; se importará vacío.')];
    }

    private function normalizeLegacyText(?string $value, string $field): array
    {
        if ($value === null || ! preg_match('/Ã.|Â.|â./u', $value)) {
            return [$value, null];
        }

        $repaired = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        $roundTrip = mb_convert_encoding($repaired, 'UTF-8', 'Windows-1252');

        if (mb_check_encoding($repaired, 'UTF-8') && $roundTrip === $value && ! preg_match('/Ã.|Â.|â./u', $repaired)) {
            return [$repaired, $this->warning($field, 'Se corrigió texto UTF-8 mal decodificado de forma reversible.')];
        }

        return [$value, null];
    }

    private function warning(string $field, string $message): array
    {
        return ['field' => $field, 'message' => $message];
    }

    private function appendWarningOnce(array &$row, array $warning): void
    {
        if (! in_array($warning, $row['warnings'], true)) {
            $row['warnings'][] = $warning;
        }
    }
}
