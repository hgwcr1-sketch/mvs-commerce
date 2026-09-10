<?php

namespace App\Services\Imports;

use App\Models\Company;
use App\Services\PhoneNumberService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerImportService
{
    public const HEADERS = [
        'tipo_cliente*', 'tipo_identificacion', 'identificacion', 'nombre*', 'nombre_comercial',
        'codigo_pais', 'telefono', 'movil', 'correo', 'direccion', 'limite_credito',
        'dias_credito', 'nivel_precio', 'fecha_nacimiento', 'activo', 'puntos_iniciales',
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
        'puntos_iniciales' => 'initial_points',
    ];

    private const FIELD_LABELS = [
        'customer_type' => 'tipo_cliente', 'identification_type' => 'tipo_identificacion',
        'identification' => 'identificacion', 'name' => 'nombre', 'commercial_name' => 'nombre_comercial',
        'phone_country_code' => 'codigo_pais', 'phone' => 'telefono', 'mobile' => 'movil',
        'email' => 'correo', 'address' => 'direccion', 'credit_limit' => 'limite_credito',
        'credit_days' => 'dias_credito', 'price_level' => 'nivel_precio',
        'birth_date' => 'fecha_nacimiento', 'is_active' => 'activo', 'initial_points' => 'puntos_iniciales',
    ];

    public function __construct(private readonly PhoneNumberService $phones) {}

    public function readChunk(string $path, Company $company, int $start, int $size): array
    {
        $chunk = app(CustomerImportChunkReader::class)->read($path, $start, $size);
        $headers = $this->resolveHeaders($chunk['headers']);
        $rows = [];
        foreach ($chunk['rows'] as $offset => $values) {
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }
            $data = [];
            foreach ($headers as $column => $field) {
                if ($field !== null) {
                    $data[$field] = $values[$column] ?? null;
                }
            }
            $rows[] = $this->normalizeRow($data, $start + $offset, $company);
        }
        return ['rows' => $rows, 'end' => $chunk['end'], 'last_row' => $chunk['last_row']];
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

    private function normalizeRow(array $data, int $rowNumber, Company $company): array
    {
        $companyCode = $company->default_phone_country_code;
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
            'identification_type' => $this->normalizeIdentificationType($data['identification_type'] ?? null),
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
            'initial_points' => $this->nullable($data['initial_points'] ?? null) ?? '0',
            'warnings' => array_values(array_filter([
                $phoneWarning, $mobileWarning, $birthDateWarning, $emailWarning,
                $nameWarning, $commercialNameWarning, $addressWarning,
            ])),
        ];
    }

    public function errors(array $row): array
    {
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
                'initial_points' => ['required', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            ], [], self::FIELD_LABELS);


        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = ['field' => self::FIELD_LABELS[$field] ?? $field, 'message' => $message];
            }
        }
        return $errors;
    }

    public function attributes(array $row): array
    {
        return collect($row)->only(array_diff(array_values(self::HEADER_MAP), ['initial_points']))->all()
            + ['accepts_email_invoice' => true, 'points' => 0];
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

    private function normalizeIdentificationType(mixed $value): ?string
    {
        $type = $this->nullable($value);

        return in_array($type, ['1', '2', '3', '4', '5'], true)
            ? str_pad($type, 2, '0', STR_PAD_LEFT)
            : $type;
    }

    private function normalizeImportedPhone(mixed $value, ?string $countryCode, string $field): array
    {
        $original = $this->nullable($value);
        if ($original === null) {
            return [null, null];
        }

        $normalized = $this->phones->normalizePhone($original);
        if ($countryCode === '+506' && preg_match('/^\+?506(\d{8})$/', (string) $normalized, $match)) {
            $normalized = $match[1];
        }
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
