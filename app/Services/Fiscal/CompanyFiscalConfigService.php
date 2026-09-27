<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalConnectionVerifiable;
use App\DTOs\Fiscal\FiscalConnectionResult;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyLicense;

/**
 * Configuración fiscal operativa del tenant (multiempresa).
 *
 * Autoridad comercial = Panel Maestro (CompanyLicense: fiscal_enabled,
 * quota, overage, precio). Aquí solo operación: proveedor, ambiente,
 * credenciales cifradas, preferencias y última verificación. Los secretos
 * viajan cifrados (casts encrypted) y jamás salen completos a vistas,
 * logs o auditoría.
 */
class CompanyFiscalConfigService
{
    public const STATUS_DISABLED = 'disabled';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_READY = 'ready';

    public const STATUS_ATTENTION = 'attention';

    public function ensure(Company $company): CompanyFiscalConfig
    {
        return CompanyFiscalConfig::firstOrCreate(
            ['company_id' => $company->id],
            [
                'provider' => config('fiscal.provider', CompanyFiscalConfig::PROVIDER_FACTURAENCR),
                'environment' => CompanyFiscalConfig::ENV_SANDBOX,
            ]
        );
    }

    /**
     * @return array{provider: string, environment: string, api_key: string, api_secret: string}
     */
    public function contextFor(Company $company): array
    {
        $config = $this->ensure($company);

        return [
            'provider' => $this->providerCodeFor($company, $config),
            'environment' => $config->environment === CompanyFiscalConfig::ENV_PRODUCTION
                ? CompanyFiscalConfig::ENV_PRODUCTION
                : CompanyFiscalConfig::ENV_SANDBOX,
            'api_key' => (string) ($config->provider_api_key ?? config('facturaencr.api_key', '')),
            'api_secret' => (string) ($config->provider_api_secret ?? config('facturaencr.api_secret', '')),
        ];
    }

    public function providerCodeFor(Company $company, ?CompanyFiscalConfig $config = null): string
    {
        $config ??= $this->ensure($company);
        $allowed = array_keys((array) config('fiscal.providers', []));

        if (in_array($config->provider, $allowed, true)) {
            return $config->provider;
        }

        return (string) config('fiscal.provider', CompanyFiscalConfig::PROVIDER_FACTURAENCR);
    }

    /**
     * Datos fiscales de identidad (vive en Company, fuente única).
     */
    public function updateIdentity(Company $company, array $attributes): Company
    {
        $company->update([
            'identification_type' => $attributes['identification_type'] ?? $company->identification_type,
            'identification_number' => $attributes['identification_number'] ?? $company->identification_number,
            'legal_name' => $attributes['legal_name'] ?? $company->legal_name,
            'email' => $attributes['email'] ?? $company->email,
        ]);

        return $company->fresh();
    }

    /**
     * Conexión: valores vacíos conservan el secreto existente (rotación
     * explícita solo con valor nuevo). Cambiar credenciales o ambiente
     * invalida la verificación anterior.
     */
    public function updateConnection(Company $company, array $attributes): CompanyFiscalConfig
    {
        $config = $this->ensure($company);
        $allowedProviders = array_keys((array) config('fiscal.providers', []));

        if (isset($attributes['provider']) && in_array($attributes['provider'], $allowedProviders, true)) {
            $config->provider = $attributes['provider'];
        }

        if (isset($attributes['environment'])
            && in_array($attributes['environment'], [CompanyFiscalConfig::ENV_SANDBOX, CompanyFiscalConfig::ENV_PRODUCTION], true)
            && $attributes['environment'] !== $config->environment) {
            $config->environment = $attributes['environment'];
            $config->last_verified_at = null;
            $config->last_error_code = null;
            $config->last_error_message = null;
        }

        $rotated = false;

        if (array_key_exists('api_key', $attributes) && trim((string) $attributes['api_key']) !== '') {
            $config->provider_api_key = trim((string) $attributes['api_key']);
            $rotated = true;
        }

        if (array_key_exists('api_secret', $attributes) && trim((string) $attributes['api_secret']) !== '') {
            $config->provider_api_secret = trim((string) $attributes['api_secret']);
            $rotated = true;
        }

        if ($rotated) {
            $config->last_verified_at = null;
            $config->last_error_code = null;
            $config->last_error_message = null;
        }

        $config->save();

        return $config->fresh();
    }

    public function updatePreferences(Company $company, array $attributes): CompanyFiscalConfig
    {
        $config = $this->ensure($company);

        if (isset($attributes['default_document'])
            && in_array($attributes['default_document'], ['01', '04'], true)) {
            $config->default_document = $attributes['default_document'];
        }

        if (array_key_exists('auto_emit_enabled', $attributes)) {
            $config->auto_emit_enabled = (bool) $attributes['auto_emit_enabled'];
        }

        if (array_key_exists('notify_receptor_email', $attributes)) {
            $config->notify_receptor_email = (bool) $attributes['notify_receptor_email'];
        }

        $config->save();

        return $config->fresh();
    }

    /**
     * Verifica conexión SIN emitir (usa capacidad verify del proveedor).
     * Guarda solo códigos/mensajes propios sanitizados, jamás secretos.
     */
    public function verify(Company $company): FiscalConnectionResult
    {
        $config = $this->ensure($company);
        $context = $this->contextFor($company);

        if (trim($context['api_key']) === '' || trim($context['api_secret']) === '') {
            return $this->recordFailure($config, 'missing_credentials', 'Faltan las credenciales del proveedor.');
        }

        $class = config('fiscal.providers.' . $context['provider']);

        if (! is_string($class) || ! class_exists($class)) {
            return $this->recordFailure($config, 'unknown_provider', 'Proveedor fiscal no configurado.');
        }

        $provider = app()->make($class);

        if (! $provider instanceof FiscalConnectionVerifiable) {
            return $this->recordFailure($config, 'verify_unsupported', 'Este proveedor no permite verificar sin emitir.');
        }

        $result = $provider->verifyConnection([
            'api_key' => $context['api_key'],
            'api_secret' => $context['api_secret'],
        ]);

        if ($result->connected) {
            $config->last_verified_at = now();
            $config->last_error_code = null;
            $config->last_error_message = null;
            $config->save();

            return $result;
        }

        return $this->recordFailure($config, $result->errorCode ?? 'verify_failed', $result->message ?? 'No se pudo verificar la conexión.');
    }

    /**
     * Estado operativo para el portal: disabled (licencia) | incomplete |
     * ready | attention. La licencia comercial la decide Panel Maestro.
     */
    public function status(Company $company, ?CompanyLicense $license = null): string
    {
        $license ??= CompanyLicense::query()->where('company_id', $company->id)->first();

        if ($license === null || ! $license->fiscal_enabled) {
            return self::STATUS_DISABLED;
        }

        $config = $this->ensure($company);

        if (! $this->identityComplete($company) || ! $config->hasCredentials()) {
            return self::STATUS_INCOMPLETE;
        }

        if ($config->last_error_code !== null) {
            return self::STATUS_ATTENTION;
        }

        if ($config->last_verified_at === null) {
            return self::STATUS_INCOMPLETE;
        }

        return self::STATUS_READY;
    }

    public function identityComplete(Company $company): bool
    {
        return trim((string) $company->identification_number) !== ''
            && trim((string) $company->legal_name) !== '';
    }

    private function recordFailure(CompanyFiscalConfig $config, string $code, string $message): FiscalConnectionResult
    {
        $config->last_verified_at = null;
        $config->last_error_code = $code;
        $config->last_error_message = $message;
        $config->save();

        return FiscalConnectionResult::failed($code, $message);
    }
}
