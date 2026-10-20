<?php

namespace App\Services\Cobros;

use App\Contracts\Cobros\MaestroReferralCredentialProvider;
use App\Models\Company;
use App\Models\CobrosMaestroCompanyBinding;
use App\Models\User;
use InvalidArgumentException;

final class MaestroJwsCredentialProvider implements MaestroReferralCredentialProvider
{
    public function __construct(private readonly MaestroIdentityBindingResolver $bindings) {}

    public function for(User $user, Company $company): ?string
    {
        $privateKey = (string) config('maestro.identity.private_key', '');
        $issuer = (string) config('maestro.identity.issuer', '');
        $kid = (string) config('maestro.identity.kid', '');
        if ($privateKey === '' || $issuer === '' || $kid === '') return null;

        $binding = $this->bindings->for($user, $company);
        if ($binding === null) return null;
        $mapping = CobrosMaestroCompanyBinding::query()->where('company_id', $company->id)
            ->where('maestro_company_id', $binding->maestro_company_id)
            ->where('maestro_application_id', $binding->maestro_application_id)
            ->where('maestro_installation_id', $binding->maestro_installation_id)
            ->where('source_system', 'cobros')->where('status', 'approved')->whereNotNull('approved_at')->whereNull('revoked_at')->first();
        if ($mapping === null) return null;

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) return null;
        $iat = time();
        $claims = [
            'iss' => $issuer, 'aud' => 'maestro', 'sub' => (string) $binding->external_user_id,
            'iat' => $iat, 'exp' => $iat + 45, 'jti' => bin2hex(random_bytes(16)),
            'installation_uuid' => (string) $binding->maestro_installation_id,
            'system' => 'cobros', 'external_user_id' => (string) $binding->external_user_id,
            'company_id' => (string) $mapping->maestro_company_id, 'application_id' => (string) $binding->maestro_application_id,
        ];
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']);
        $payload = $encode($claims);
        if (!openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256)) return null;
        return $header.'.'.$payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
