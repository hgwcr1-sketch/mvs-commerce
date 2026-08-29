<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Str;

class CustomerPublicCodeService
{
    public const LENGTH = 8;
    public const MAX_ATTEMPTS = 20;

    public function ensure(Customer $customer): string
    {
        if (!empty($customer->public_code)) {
            return $customer->public_code;
        }

        return $this->generateFor($customer);
    }

    public function generateFor(Customer $customer): string
    {
        if (!empty($customer->public_code)) {
            return $customer->public_code;
        }

        $companyId = (int) $customer->company_id;

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $code = $this->randomCode();
            $exists = Customer::query()->where('company_id', $companyId)->where('public_code', $code)->exists();
            if (!$exists) {
                $customer->forceFill(['public_code' => $code])->save();
                $customer->refresh();
                return $code;
            }
        }

        throw new \RuntimeException('No fue posible generar código público único.');
    }

    public function randomCode(): string
    {
        // 8 chars, base36 uppercase, sin caracteres ambiguos 0/O 1/I se filtran opcional
        $code = Str::upper(Str::random(self::LENGTH));
        // Asegurar alfanumérico y al menos una letra y un número para evitar confusión con IDs secuenciales
        if (!preg_match('/[A-Z]/', $code) || !preg_match('/[0-9]/', $code)) {
            $code = 'A' . substr($code, 1, self::LENGTH - 2) . '1';
        }

        return $code;
    }

    public function isSensitiveLeak(Customer $customer, string $code): bool
    {
        $code = Str::upper($code);
        $needles = [
            $customer->identification ? Str::upper(preg_replace('/\D/', '', $customer->identification)) : null,
            $customer->phone ? Str::upper(preg_replace('/\D/', '', $customer->phone)) : null,
            $customer->mobile ? Str::upper(preg_replace('/\D/', '', $customer->mobile)) : null,
            $customer->email ? Str::upper(explode('@', $customer->email)[0]) : null,
        ];

        foreach ($needles as $needle) {
            if ($needle && strlen($needle) >= 4 && str_contains($code, substr($needle, -4))) {
                return true;
            }
            if ($needle && $needle !== '' && $code === Str::upper($needle)) {
                return true;
            }
        }

        return false;
    }
}
