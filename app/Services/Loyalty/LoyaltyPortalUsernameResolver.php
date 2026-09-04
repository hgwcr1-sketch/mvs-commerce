<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Services\PhoneNumberService;
use Illuminate\Support\Str;

class LoyaltyPortalUsernameResolver
{
    public function resolve(Customer $customer, Company $company): array
    {
        $phones = app(PhoneNumberService::class);
        $phoneNormalized = $phones->normalizePhone($customer->phone ?? $customer->mobile);
        $emailNormalized = $customer->email ? mb_strtolower(trim($customer->email)) : null;
        if ($emailNormalized === '') {
            $emailNormalized = null;
        }

        $username = null;
        if ($phoneNormalized) {
            $username = $phoneNormalized;
        } elseif ($emailNormalized && filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
            $username = $emailNormalized;
        } else {
            $base = Str::slug(trim((string) $customer->name));
            if ($base === '') {
                $base = 'user';
            }
            // Cortar a 30 para dejar espacio a sufijo
            $base = mb_substr($base, 0, 30);
            $username = $base;
            $attempts = 0;
            while ($attempts < 5 && LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('username', $username)->exists()) {
                $username = $base . '_' . random_int(1, 99);
                // Si sigue colisionando, probar con random más largo
                if ($attempts >= 3) {
                    $username = $base . '_' . Str::random(4);
                }
                $attempts++;
            }
        }

        // Validar unicidad final dentro de la empresa (username o email)
        $exists = LoyaltyPortalCredential::query()
            ->where('company_id', $company->id)
            ->where(function ($q) use ($username, $emailNormalized) {
                $q->where('username', $username);
                if ($emailNormalized) {
                    $q->orWhere('email', $emailNormalized);
                }
            })->exists();

        // Si colisiona y viene de nombre, intentar sufijo una vez más
        if ($exists && ! $phoneNormalized && ! ($emailNormalized && filter_var($emailNormalized, FILTER_VALIDATE_EMAIL))) {
            $base = Str::slug(trim((string) $customer->name));
            if ($base === '') {
                $base = 'user';
            }
            $base = mb_substr($base, 0, 30);
            $attempts = 0;
            do {
                $username = $base . '_' . random_int(10, 99);
                $attempts++;
            } while ($attempts < 5 && LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('username', $username)->exists());
        }

        $email = $emailNormalized ?? $username . '@portal.local';

        return [
            'username' => $username,
            'email' => $email,
            'phoneNormalized' => $phoneNormalized,
            'emailNormalized' => $emailNormalized,
        ];
    }
}
