<?php

namespace App\Services\Imports;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/** Match candidate values in batches; never fetch all tenant contacts for every row. */
class CustomerImportIdentityMatcher
{
    public function identification(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/[\s.\-]/', '', (string) $value));

        return $value === '' ? null : $value;
    }

    public function keys(array $data, ?string $defaultCode): array
    {
        $code = $data['phone_country_code'] ?: $defaultCode;
        $keys = [
            'identification_key' => $this->identification($data['identification'] ?? null),
            'phone_key' => $this->phone($data['phone'] ?? null, $code),
            'mobile_key' => $this->phone($data['mobile'] ?? null, $code),
            'email_key' => strtolower(trim($data['email'] ?? '')) ?: null,
        ];

        return array_map(fn ($value) => $value === null ? null : hash('sha256', $value), $keys);
    }

    private function phone(?string $value, ?string $code): ?string
    {
        $digits = preg_replace('/[\s+().,\-]/', '', (string) $value);
        if ($digits === '') {
            return null;
        }
        $prefix = ltrim((string) $code, '+');
        // The existing CR legacy normalizer also accepts duplicated 506 prefixes.
        if ($prefix === '506') {
            while (strlen($digits) > 8 && str_starts_with($digits, '506')) {
                $digits = substr($digits, 3);
            }
        }

        return $prefix.':'.$digits;
    }

    public function add(array &$index, array $keys, int $id): void
    {
        foreach ($keys as $field => $key) {
            if ($key !== null) {
                $index[$this->group($field)][$key][$id] = $id;
            }
        }
    }

    public function matches(array $index, array $keys): array
    {
        $matches = [];
        foreach ($keys as $field => $key) {
            foreach ($index[$this->group($field)][$key] ?? [] as $id) {
                $matches[$id] = $id;
            }
        }

        return array_values($matches);
    }

    private function group(string $field): string
    {
        return $field === 'mobile_key' ? 'phone_key' : $field;
    }

    public function customers(array $rows, int $companyId, ?string $code): array
    {
        $ids = $emails = $phones = [];
        foreach ($rows as $row) {
            if ($id = $this->identification($row['identification'] ?? null)) {
                $ids[] = $id;
            }
            if ($email = strtolower(trim($row['email'] ?? ''))) {
                $emails[] = $email;
            }
            foreach (['phone', 'mobile'] as $field) {
                if (! empty($row[$field])) {
                    $phones[] = explode(':', $this->phone($row[$field], $row['phone_country_code'] ?: $code), 2)[1];
                }
            }
        }
        if ($ids === [] && $emails === [] && $phones === []) {
            return [];
        }
        $index = [];
        $query = Customer::withTrashed()->where('company_id', $companyId)->where(function ($query) use ($ids, $emails, $phones) {
            $query->whereRaw('1 = 0');
            if ($ids !== []) {
                $query->orWhereIn(DB::raw('UPPER('.$this->cleanSql('identification', [' ', '-', '.', "\t", "\n", "\r"]).')'), array_unique($ids));
            }
            if ($emails !== []) {
                $query->orWhereIn(DB::raw('LOWER(TRIM(email))'), array_unique($emails));
            }
            if ($phones !== []) {
                foreach (['phone', 'mobile'] as $field) {
                    $clean = $this->cleanSql($field, [' ', '-', '.', '(', ')', '+', ',', "\t", "\n", "\r"]);
                    $local = "CASE WHEN LENGTH($clean) = 14 AND SUBSTR($clean, 1, 6) = '506506' THEN SUBSTR($clean, 7) WHEN LENGTH($clean) = 11 AND SUBSTR($clean, 1, 3) = '506' THEN SUBSTR($clean, 4) ELSE $clean END";
                    $query->orWhereIn(DB::raw($local), array_unique($phones));
                }
            }
        });
        foreach ($query->cursor(['id', 'identification', 'phone_country_code', 'phone', 'mobile', 'email']) as $customer) {
            $this->add($index, $this->keys($customer->getAttributes(), $code), $customer->id);
        }

        return $index;
    }

    private function cleanSql(string $field, array $characters): string
    {
        $sql = "COALESCE($field, '')";
        foreach ($characters as $character) {
            $sql = "REPLACE($sql, '$character', '')";
        }

        return $sql;
    }
}
