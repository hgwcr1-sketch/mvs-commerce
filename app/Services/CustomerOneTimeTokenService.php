<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerOneTimeToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerOneTimeTokenService
{
    public const TTL_MINUTES = 5;
    public const TOKEN_LENGTH = 6; // 6 dígitos para PIN manual

    public function generate(Customer $customer, Company $company, string $purpose = 'redeem', int $ttlMinutes = self::TTL_MINUTES): array
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }

        $plain = $this->randomPin();

        // Revocar tokens previos vigentes del mismo propósito (opcional: mantener solo uno vigente)
        CustomerOneTimeToken::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        $token = CustomerOneTimeToken::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'purpose' => $purpose,
        ]);

        // QR local: el token mismo (sin datos sensibles)
        $qrSvg = null;
        if (app(CustomerPublicCodeService::class)->qrSupported()) {
            try {
                $qrSvg = app(CustomerPublicCodeService::class)->qrForToken($plain);
            } catch (\Throwable $e) {
                $qrSvg = null;
            }
        }

        return ['token' => $token, 'plain' => $plain, 'qrSvg' => $qrSvg];
    }

    public function verify(Customer $customer, Company $company, string $plain, string $purpose = 'redeem'): CustomerOneTimeToken
    {
        $plain = trim($plain);
        if ($plain === '') {
            throw ValidationException::withMessages(['token' => 'PIN requerido.']);
        }

        $hash = hash('sha256', $plain);
        $record = CustomerOneTimeToken::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->first();

        if (!$record) {
            throw ValidationException::withMessages(['token' => 'PIN no válido.']);
        }

        if ($record->isUsed()) {
            throw ValidationException::withMessages(['token' => 'PIN ya utilizado.']);
        }

        if ($record->isExpired()) {
            throw ValidationException::withMessages(['token' => 'PIN vencido.']);
        }

        // Marcar usado atomically
        $updated = CustomerOneTimeToken::query()
            ->where('id', $record->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['token' => 'PIN ya utilizado.']);
        }

        return $record->refresh();
    }

    public function isStaticQrTrustedForRedeem(): bool
    {
        return false; // Nunca confiar solo en QR estático para canjes
    }

    private function randomPin(): string
    {
        // 6 dígitos, sin ceros a la izquierda perdidos por ser string
        return str_pad((string) random_int(0, 999999), self::TOKEN_LENGTH, '0', STR_PAD_LEFT);
    }
}
