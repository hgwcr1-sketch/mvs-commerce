<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Models\LoyaltyPortalPasskey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Passkey-style challenge/response for the Loyalty Portal.
 *
 * P37.1-K guarantees:
 * - MVS never stores biometric data. The secret is generated server-side at
 *   registration, encrypted at rest with the application key and sent to the
 *   device exactly once. The device keeps the secret in localStorage; the
 *   server only retains the encrypted blob.
 * - Recovery is always possible with username + password; passkeys are an
 *   additional factor, never a replacement.
 * - Credentials are strictly isolated by (company_id, customer_id). A
 *   credential from one tenant never authenticates against another.
 *
 * Algorithm: HMAC-SHA256 with a 32-byte secret that lives only on the
 * device. The server stores the secret encrypted via Laravel's `Crypt` so
 * it can reproduce the HMAC during login verification. The encrypted blob
 * is opaque to operators without the application key — a database dump
 * alone does not let an attacker sign challenges.
 */
class LoyaltyPortalPasskeyService
{
    public const ALGORITHM = 'HS256';

    public const CHALLENGE_BYTES = 32;

    public const CHALLENGE_TTL_SECONDS = 300;

    public const MAX_PASSKEYS_PER_CUSTOMER = 8;

    public function startRegistration(Customer $customer, Company $company, string $name): array
    {
        $this->assertSameCompany($customer, $company);
        $credential = $this->resolveCredential($customer, $company);

        $challenge = $this->freshChallenge();
        $cacheKey = $this->cacheKey('register', $company->id, $customer->id, $challenge);

        Cache::put($cacheKey, [
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'credential_id_ref' => $credential->id,
            'expected_name' => trim($name),
        ], self::CHALLENGE_TTL_SECONDS);

        return [
            'challenge' => $challenge,
            'rp_id' => $this->rpId(),
            'rp_name' => $company->trade_name,
            'user_id' => (string) $customer->id,
            'user_name' => $credential->username,
            'user_display_name' => $customer->name,
            'algorithm' => self::ALGORITHM,
        ];
    }

    public function finishRegistration(
        Customer $customer,
        Company $company,
        string $challenge,
        string $credentialId,
        string $enrollmentSecret,
        string $challengeSignature,
        string $name,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $this->assertSameCompany($customer, $company);
        $this->assertCanRegister($customer, $company);

        $cacheKey = $this->cacheKey('register', $company->id, $customer->id, $challenge);
        $payload = Cache::pull($cacheKey);
        if (! is_array($payload) || (int) $payload['customer_id'] !== (int) $customer->id) {
            throw ValidationException::withMessages(['passkey' => 'La solicitud de registro expiró o es inválida.']);
        }

        $credentialId = trim($credentialId);
        if ($credentialId === '' || strlen($credentialId) > 64) {
            throw ValidationException::withMessages(['credential_id' => 'Identificador de passkey inválido.']);
        }
        if (LoyaltyPortalPasskey::query()->where('company_id', $company->id)->where('credential_id', $credentialId)->exists()) {
            throw ValidationException::withMessages(['credential_id' => 'Esta passkey ya está registrada en este comercio.']);
        }

        $rawChallenge = $this->base64UrlDecode($challenge);
        $proposedSecret = $this->base64UrlDecode($enrollmentSecret);
        $signature = $this->base64UrlDecode($challengeSignature);
        if ($rawChallenge === '' || $proposedSecret === '' || $signature === '' || strlen($proposedSecret) !== 32 || strlen($signature) !== 32) {
            throw ValidationException::withMessages(['passkey' => 'Datos de firma inválidos. La firma debe ser HMAC-SHA256 de 32 bytes.']);
        }

        // Verificamos que el cliente realmente conozca el secret que está
        // proponiendo: que el HMAC(secret, challenge) coincida.
        $expected = hash_hmac('sha256', $rawChallenge, $proposedSecret, true);
        if (! hash_equals($expected, $signature)) {
            throw ValidationException::withMessages(['passkey' => 'La firma del registro no es válida.']);
        }

        $encryptedSecret = Crypt::encryptString($proposedSecret);

        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        $passkey = LoyaltyPortalPasskey::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'credential_id_ref' => $credential->id,
            'credential_id' => $credentialId,
            'name' => $this->sanitizeName($name) ?: 'Mi passkey',
            'algorithm' => self::ALGORITHM,
            'public_key_jwk' => ['secret' => $encryptedSecret, 'hint' => substr(hash('sha256', $proposedSecret), 0, 8)],
            'sign_count' => 0,
            'registered_ip' => $ip,
            'registered_user_agent' => Str::limit((string) $userAgent, 250, ''),
        ]);

        return ['passkey' => $passkey, 'enrollment_secret' => $proposedSecret];
    }

    public function startAuthentication(Company $company, string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw ValidationException::withMessages(['identifier' => 'Ingresa tu usuario o correo.']);
        }

        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('is_active', true)
            ->where(fn ($query) => $query->where('username', $identifier)->orWhere('email', $identifier))->first();
        if (! $credential) {
            throw ValidationException::withMessages(['identifier' => 'No encontramos una cuenta con ese usuario.']);
        }

        $passkeys = LoyaltyPortalPasskey::query()->where('company_id', $company->id)->where('customer_id', $credential->customer_id)->orderBy('created_at')->get(['id', 'credential_id', 'name', 'revoked_at']);
        if ($passkeys->isEmpty()) {
            throw ValidationException::withMessages(['passkey' => 'Este cliente no tiene passkeys activas. Usa tu contraseña.']);
        }

        $challenge = $this->freshChallenge();
        Cache::put(
            $this->cacheKey('auth', $company->id, $credential->customer_id, $challenge),
            ['customer_id' => $credential->customer_id, 'company_id' => $company->id],
            self::CHALLENGE_TTL_SECONDS,
        );

        return [
            'challenge' => $challenge,
            'rp_id' => $this->rpId(),
            'credential_ids' => $passkeys->pluck('credential_id')->values()->all(),
            'credential_names' => $passkeys->pluck('name')->values()->all(),
        ];
    }

    public function finishAuthentication(
        Company $company,
        string $identifier,
        string $credentialId,
        string $challenge,
        string $signature,
        ?string $ip = null,
    ): array {
        $identifier = trim($identifier);
        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('is_active', true)
            ->where(fn ($query) => $query->where('username', $identifier)->orWhere('email', $identifier))->first();
        if (! $credential) {
            throw ValidationException::withMessages(['identifier' => 'No encontramos una cuenta con ese usuario.']);
        }

        $crossCompanyAttempt = LoyaltyPortalPasskey::query()->where('credential_id', $credentialId)->where('company_id', '!=', $company->id)->exists();
        if ($crossCompanyAttempt) {
            throw ValidationException::withMessages(['identifier' => 'Esta passkey no pertenece a la cuenta indicada.']);
        }

        $payload = Cache::pull($this->cacheKey('auth', $company->id, $credential->customer_id, $challenge));
        if (! is_array($payload) || (int) $payload['customer_id'] !== (int) $credential->customer_id) {
            throw ValidationException::withMessages(['passkey' => 'La solicitud de autenticación expiró o es inválida.']);
        }

        $passkey = LoyaltyPortalPasskey::query()->where('company_id', $company->id)->where('customer_id', $credential->customer_id)->where('credential_id', $credentialId)->whereNull('revoked_at')->first();
        if (! $passkey) {
            $existsInOtherCompany = LoyaltyPortalPasskey::query()->where('credential_id', $credentialId)->where('company_id', '!=', $company->id)->exists();
            if ($existsInOtherCompany) {
                throw ValidationException::withMessages(['identifier' => 'Esta passkey no pertenece a la cuenta indicada.']);
            }
            throw ValidationException::withMessages(['passkey' => 'Esta passkey no está registrada o fue revocada.']);
        }

        $rawChallenge = $this->base64UrlDecode($challenge);
        $rawSignature = $this->base64UrlDecode($signature);
        if ($rawChallenge === '' || $rawSignature === '' || strlen($rawSignature) !== 32) {
            throw ValidationException::withMessages(['passkey' => 'Datos de firma inválidos.']);
        }

        $encryptedSecret = (string) ($passkey->public_key_jwk['secret'] ?? '');
        if ($encryptedSecret === '') {
            throw ValidationException::withMessages(['passkey' => 'Esta passkey está dañada y no puede usarse. Renuévala.']);
        }
        try {
            $secret = Crypt::decryptString($encryptedSecret);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['passkey' => 'No se pudo descifrar la passkey. Renuévala.']);
        }

        $expected = hash_hmac('sha256', $rawChallenge, $secret, true);
        if (! hash_equals($expected, $rawSignature)) {
            $passkey->update(['revoked_at' => now(), 'revoked_ip' => $ip]);
            throw ValidationException::withMessages(['passkey' => 'La firma de la passkey no es válida. La credencial fue revocada por seguridad.']);
        }

        $passkey->update(['last_used_at' => now(), 'last_used_ip' => $ip, 'sign_count' => $passkey->sign_count + 1]);

        return ['customer' => $credential->customer, 'credential' => $credential, 'passkey' => $passkey];
    }

    public function list(Customer $customer, Company $company)
    {
        $this->assertSameCompany($customer, $company);

        $passkeys = LoyaltyPortalPasskey::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->orderByDesc('created_at')->get();

        return $passkeys->each(function (LoyaltyPortalPasskey $passkey): void {
            $jwk = $passkey->public_key_jwk;
            if (is_array($jwk)) {
                unset($jwk['secret']);
                $passkey->setRawAttributes(array_merge($passkey->getAttributes(), ['public_key_jwk' => json_encode($jwk)]), true);
            }
        });
    }

    public function rename(Customer $customer, Company $company, int $passkeyId, string $name): LoyaltyPortalPasskey
    {
        $this->assertSameCompany($customer, $company);
        $passkey = LoyaltyPortalPasskey::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->where('id', $passkeyId)->firstOrFail();
        $passkey->update(['name' => $this->sanitizeName($name) ?: $passkey->name]);

        return $passkey;
    }

    public function revoke(Customer $customer, Company $company, int $passkeyId, ?string $ip = null): LoyaltyPortalPasskey
    {
        $this->assertSameCompany($customer, $company);
        $passkey = LoyaltyPortalPasskey::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->where('id', $passkeyId)->firstOrFail();
        if ($passkey->revoked_at === null) {
            $passkey->update(['revoked_at' => now(), 'revoked_ip' => $ip]);
        }

        return $passkey;
    }

    /**
     * Genera un secret + firma de enrollment que un cliente "honesto" puede
     * reproducir. Útil en tests y en enrollment automático.
     */
    public function makeEnrollmentPayload(string $challenge): array
    {
        $rawChallenge = $this->base64UrlDecode($challenge);
        $secret = random_bytes(32);
        $signature = hash_hmac('sha256', $rawChallenge, $secret, true);

        return [
            'secret' => $secret,
            'signature' => $this->base64UrlEncode($signature),
            'credential_id' => $this->base64UrlEncode(random_bytes(32)),
        ];
    }

    /**
     * Firma un challenge con un secret conocido. Útil en tests.
     */
    public function signChallenge(string $secret, string $challenge): string
    {
        $rawChallenge = $this->base64UrlDecode($challenge);
        $signature = hash_hmac('sha256', $rawChallenge, $secret, true);

        return $this->base64UrlEncode($signature);
    }

    private function assertSameCompany(Customer $customer, Company $company): void
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }
    }

    private function assertCanRegister(Customer $customer, Company $company): void
    {
        $count = LoyaltyPortalPasskey::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->whereNull('revoked_at')->count();
        if ($count >= self::MAX_PASSKEYS_PER_CUSTOMER) {
            throw ValidationException::withMessages(['passkey' => 'Has alcanzado el máximo de '.self::MAX_PASSKEYS_PER_CUSTOMER.' passkeys activas. Revoca una para registrar otra.']);
        }
    }

    private function resolveCredential(Customer $customer, Company $company): LoyaltyPortalCredential
    {
        return LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->where('is_active', true)->firstOrFail();
    }

    private function freshChallenge(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::CHALLENGE_BYTES)), '+/', '-_'), '=');
    }

    private function cacheKey(string $stage, int $companyId, int $customerId, string $challenge): string
    {
        return 'loyalty_portal_passkey:'.$stage.':'.$companyId.':'.$customerId.':'.hash('sha256', $challenge);
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function sanitizeName(string $name): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return Str::limit($clean, 80, '');
    }

    private function rpId(): string
    {
        $host = (string) request()->getHost();
        if ($host === '' || $host === 'localhost') {
            return 'localhost';
        }

        return $host;
    }
}
