<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\OfflineAuthorization;
use App\Models\OfflineTerminal;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfflineAuthorizationService
{
    private ?string $privateKey = null;
    private ?string $publicKey = null;

    public function registerTerminal(Company $company, Branch $branch, User $actor, ?string $name = null, ?string $notes = null): OfflineTerminal
    {
        abort_unless($actor->isPlatformAdmin() || $actor->hasPermission('configuracion.editar', $company), 403);

        if ((int) $branch->company_id !== (int) $company->id) {
            abort(422, 'La sucursal no pertenece a la empresa especificada.');
        }

        return OfflineTerminal::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => (string) Str::uuid(),
            'name' => $name,
            'status' => OfflineTerminal::STATUS_ACTIVE,
            'registered_by' => $actor->id,
            'notes' => $notes,
        ]);
    }

    public function provisionTerminal(Company $company, Branch $branch, User $actor, string $terminalUuid): OfflineTerminal
    {
        if ((int) $branch->company_id !== (int) $company->id) {
            abort(422, 'La sucursal no pertenece a la empresa especificada.');
        }

        $terminal = OfflineTerminal::where('terminal_uuid', $terminalUuid)->first();
        if ($terminal) {
            abort_unless(
                (int) $terminal->company_id === (int) $company->id
                    && (int) $terminal->branch_id === (int) $branch->id,
                403,
                'La terminal pertenece a otro contexto.'
            );

            return $terminal;
        }

        return OfflineTerminal::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminalUuid,
            'name' => 'POS '.$terminalUuid,
            'status' => OfflineTerminal::STATUS_ACTIVE,
            'registered_by' => $actor->id,
        ]);
    }

    public function authorize(OfflineTerminal $terminal, User $actor): array
    {
        $company = $terminal->company;
        $branch = $terminal->branch;

        $this->validateTerminalForAuthorization($terminal);
        $this->validateCompanyForAuthorization($company);
        $this->validateBranchForAuthorization($branch, $company);
        $this->validateUserForAuthorization($actor, $company, $branch);

        $license = $this->resolveLicenseForAuthorization($company);
        $maxHours = min(48, max(1, (int) config('offline.max_hours', 48)));
        $now = now();
        $validUntil = $now->copy()->addHours($maxHours);

        $authorizationRecord = OfflineAuthorization::create([
            'offline_terminal_id' => $terminal->id,
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $actor->id,
            'authorization_id' => (string) Str::uuid(),
            'issued_at' => $now,
            'valid_until' => $validUntil,
            'result' => OfflineAuthorization::RESULT_GRANTED,
        ]);

        $terminal->update(['last_validated_at' => $now]);

        $token = $this->signToken([
            'authorization_id' => $authorizationRecord->authorization_id,
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $actor->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'issued_at' => $now->toIso8601String(),
            'valid_until' => $validUntil->toIso8601String(),
            'server_time' => $now->toIso8601String(),
            'license_status' => $license?->status ?? 'unknown',
        ]);

        return [
            'authorization' => $token,
            'token' => $token,
            'authorization_id' => $authorizationRecord->authorization_id,
            'server_time' => $now->toIso8601String(),
            'issued_at' => $now->toIso8601String(),
            'valid_until' => $validUntil->toIso8601String(),
            'terminal_uuid' => $terminal->terminal_uuid,
        ];
    }

    public function verifyToken(string $token): ?array
    {
        $payload = $this->decodeVerifiedToken($token);

        if ($payload === null) {
            return null;
        }

        $validUntil = \Carbon\Carbon::parse($payload['valid_until']);
        if ($validUntil->isPast()) {
            return null;
        }

        return $payload;
    }

    /**
     * Verify an authorization token even if it has already expired.
     *
     * Signature, format, terminal state and context are still fully verified.
     * This is intended for OFFLINE SYNCHRONIZATION ONLY: a legitimate operation
     * created while the authorization window was valid must be able to sync even
     * after the 48h window elapsed. The caller MUST additionally verify that
     * the operation's created_at_local falls inside [issued_at, valid_until] to
     * prevent fabricating new operations after expiration.
     */
    public function verifyTokenAllowExpired(string $token): ?array
    {
        return $this->decodeVerifiedToken($token);
    }

    private function decodeVerifiedToken(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$headerB64, $payloadB64, $signatureB64] = $parts;

            $header = json_decode(base64_decode($headerB64, true), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($header)) {
                return null;
            }

            $supportedAlgs = ['RS256'];
            $supportedVersions = [1];
            if (! in_array($header['alg'] ?? null, $supportedAlgs, true)) {
                return null;
            }
            if (($header['typ'] ?? null) !== 'MVS-Offline-Auth' || ! in_array($header['version'] ?? null, $supportedVersions, true)) {
                return null;
            }

            $signature = base64_decode(strtr($signatureB64, '-_', '+/'), true);
            if ($signature === false) {
                return null;
            }

            $signedData = $headerB64 . '.' . $payloadB64;
            $publicKey = $this->loadPublicKey();
            if (! $publicKey) {
                return null;
            }

            $valid = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            if ($valid !== 1) {
                return null;
            }

            $payload = json_decode(base64_decode($payloadB64, true), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                return null;
            }

            $required = ['authorization_id', 'company_id', 'branch_id', 'terminal_uuid', 'issued_at', 'valid_until'];
            foreach ($required as $field) {
                if (! isset($payload[$field])) {
                    return null;
                }
            }

            $terminal = OfflineTerminal::where('terminal_uuid', $payload['terminal_uuid'])
                ->where('company_id', $payload['company_id'])
                ->where('branch_id', $payload['branch_id'])
                ->first();

            if (! $terminal || $terminal->isRevoked()) {
                return null;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }

    public function revokeTerminal(OfflineTerminal $terminal, User $actor): void
    {
        abort_unless($actor->isPlatformAdmin() || $actor->hasPermission('configuracion.editar', $terminal->company), 403);

        $terminal->revoke();
    }

    public function denyAuthorization(OfflineTerminal $terminal, User $actor, ?string $reason = null): OfflineAuthorization
    {
        abort_unless($actor->isPlatformAdmin() || $actor->hasPermission('configuracion.editar', $terminal->company), 403);

        return OfflineAuthorization::create([
            'offline_terminal_id' => $terminal->id,
            'company_id' => $terminal->company_id,
            'branch_id' => $terminal->branch_id,
            'user_id' => $actor->id,
            'authorization_id' => (string) Str::uuid(),
            'issued_at' => now(),
            'valid_until' => now(),
            'result' => OfflineAuthorization::RESULT_DENIED,
            'notes' => $reason,
        ]);
    }

    public function getPublicKey(): ?string
    {
        return $this->loadPublicKey();
    }

    public function hasKeyPair(): bool
    {
        return $this->loadPrivateKey() !== null && $this->loadPublicKey() !== null;
    }

    private function signToken(array $payload): string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'MVS-Offline-Auth',
            'version' => config('offline.token_version', 1),
        ];

        $headerB64 = $this->base64url(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = $this->base64url(json_encode($payload, JSON_THROW_ON_ERROR));

        $signedData = $headerB64 . '.' . $payloadB64;

        $privateKey = $this->loadPrivateKey();
        if (! $privateKey) {
            throw new \RuntimeException('Offline authorization private key not available.');
        }

        $signature = '';
        if (! openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign offline authorization token.');
        }

        $signatureB64 = $this->base64url($signature);

        return $headerB64 . '.' . $payloadB64 . '.' . $signatureB64;
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function loadPrivateKey(): ?string
    {
        if ($this->privateKey !== null) {
            return $this->privateKey;
        }

        $path = storage_path(config('offline.keys.private'));
        if (! File::exists($path)) {
            return null;
        }

        $this->privateKey = File::get($path);

        return $this->privateKey;
    }

    private function loadPublicKey(): ?string
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        $path = storage_path(config('offline.keys.public'));
        if (! File::exists($path)) {
            return null;
        }

        $this->publicKey = File::get($path);

        return $this->publicKey;
    }

    private function validateTerminalForAuthorization(OfflineTerminal $terminal): void
    {
        if ($terminal->isRevoked()) {
            throw ValidationException::withMessages([
                'terminal' => 'La terminal está revocada y no puede recibir autorizaciones.',
            ]);
        }
    }

    private function validateCompanyForAuthorization(Company $company): void
    {
        if (! $company->is_active) {
            throw ValidationException::withMessages([
                'company' => 'La empresa no está activa.',
            ]);
        }
    }

    private function validateBranchForAuthorization(Branch $branch, Company $company): void
    {
        if ((int) $branch->company_id !== (int) $company->id) {
            abort(422, 'La sucursal no pertenece a la empresa.');
        }

        if (! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch' => 'La sucursal no está activa.',
            ]);
        }
    }

    private function validateUserForAuthorization(User $actor, Company $company, Branch $branch): void
    {
        if (! $actor->isActive()) {
            throw ValidationException::withMessages([
                'user' => 'El usuario no está activo.',
            ]);
        }

        if ($actor->isPlatformAdmin()) {
            return;
        }

        if (! $actor->companies()->where('companies.id', $company->id)->exists()) {
            abort(403, 'El usuario no tiene acceso a esta empresa.');
        }

        if (! $actor->branches()->where('branches.id', $branch->id)->exists()) {
            abort(403, 'El usuario no tiene acceso a esta sucursal.');
        }
    }

    private function resolveLicenseForAuthorization(Company $company): ?CompanyLicense
    {
        $licenseService = app(CompanyLicenseService::class);
        $license = $company->license;

        if (! $license) {
            $license = $licenseService->ensure($company);
        } else {
            $license = $licenseService->refresh($license);
        }

        return $license;
    }
}
