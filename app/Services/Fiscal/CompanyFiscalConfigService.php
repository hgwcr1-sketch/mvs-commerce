<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalConnectionVerifiable;
use App\DTOs\Fiscal\FiscalConnectionResult;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyLicense;
use App\Models\FiscalConfigAudit;

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
     * Datos fiscales del emisor: actividad económica y códigos de
     * sucursal/terminal para series (dominio Hacienda, NO proveedor).
     * Formatos oficiales: actividad libre, sucursal 3 dígitos, terminal 5.
     */
    public function updateFiscalData(Company $company, array $attributes): CompanyFiscalConfig
    {
        $config = $this->ensure($company);

        if (array_key_exists('economic_activity', $attributes)) {
            $activity = trim((string) $attributes['economic_activity']);
            $config->economic_activity = $activity !== '' ? substr($activity, 0, 30) : null;
        }

        if (array_key_exists('fiscal_branch_code', $attributes)) {
            $branch = trim((string) $attributes['fiscal_branch_code']);
            $config->fiscal_branch_code = $branch !== '' ? $branch : null;
        }

        if (array_key_exists('fiscal_terminal_code', $attributes)) {
            $terminal = trim((string) $attributes['fiscal_terminal_code']);
            $config->fiscal_terminal_code = $terminal !== '' ? $terminal : null;
        }

        $config->save();

        return $config->fresh();
    }

    /**
     * Datos fiscales de identidad (vive en Company, fuente única).
     */
    public function updateIdentity(Company $company, array $attributes, ?int $actorId = null): Company
    {
        $company->update([
            'identification_type' => $attributes['identification_type'] ?? $company->identification_type,
            'identification_number' => $attributes['identification_number'] ?? $company->identification_number,
            'legal_name' => $attributes['legal_name'] ?? $company->legal_name,
            'email' => $attributes['email'] ?? $company->email,
            'province_id' => array_key_exists('province_id', $attributes) ? ($attributes['province_id'] ?: null) : $company->province_id,
            'canton_id' => array_key_exists('canton_id', $attributes) ? ($attributes['canton_id'] ?: null) : $company->canton_id,
            'district_id' => array_key_exists('district_id', $attributes) ? ($attributes['district_id'] ?: null) : $company->district_id,
            'address' => $attributes['address'] ?? $company->address,
        ]);

        $this->audit($company, $actorId, $this->ensure($company)->environment, FiscalConfigAudit::TYPE_IDENTITY, FiscalConfigAudit::RESULT_OK);

        return $company->fresh();
    }

    /**
     * Conexión por etapas: lo nuevo queda PENDIENTE sin tocar la conexión
     * vigente ni su verificación. Valores vacíos no cambian nada. Solo se
     * activa tras verificar con éxito.
     */
    public function stageConnection(Company $company, array $attributes, ?int $actorId = null): CompanyFiscalConfig
    {
        $config = $this->ensure($company);
        $allowedProviders = array_keys((array) config('fiscal.providers', []));

        if (isset($attributes['provider']) && in_array($attributes['provider'], $allowedProviders, true)) {
            $config->pending_provider = $attributes['provider'];
        }

        if (isset($attributes['environment'])
            && in_array($attributes['environment'], [CompanyFiscalConfig::ENV_SANDBOX, CompanyFiscalConfig::ENV_PRODUCTION], true)) {
            $config->pending_environment = $attributes['environment'];
        }

        if (array_key_exists('api_key', $attributes) && trim((string) $attributes['api_key']) !== '') {
            $config->pending_api_key = trim((string) $attributes['api_key']);
        }

        if (array_key_exists('api_secret', $attributes) && trim((string) $attributes['api_secret']) !== '') {
            $config->pending_api_secret = trim((string) $attributes['api_secret']);
        }

        $config->save();
        $this->audit($company, $actorId, $config->environment, FiscalConfigAudit::TYPE_CONNECTION_STAGED, FiscalConfigAudit::RESULT_OK);

        return $config->fresh();
    }

    public function discardPending(Company $company, ?int $actorId = null): CompanyFiscalConfig
    {
        $config = $this->ensure($company);
        $config->pending_provider = null;
        $config->pending_environment = null;
        $config->pending_api_key = null;
        $config->pending_api_secret = null;
        $config->save();
        $this->audit($company, $actorId, $config->environment, FiscalConfigAudit::TYPE_CONNECTION_DISCARDED, FiscalConfigAudit::RESULT_OK);

        return $config->fresh();
    }

    /**
     * Desconexión explícita: retira credenciales (vigentes y pendientes) y
     * verificación. Jamás toca historial fiscal, series ni consumos.
     */
    public function disconnect(Company $company, ?int $actorId = null): CompanyFiscalConfig
    {
        $config = $this->ensure($company);
        $config->provider_api_key = null;
        $config->provider_api_secret = null;
        $config->pending_provider = null;
        $config->pending_environment = null;
        $config->pending_api_key = null;
        $config->pending_api_secret = null;
        $config->last_verified_at = null;
        $config->last_error_code = null;
        $config->last_error_message = null;
        $config->save();
        $this->audit($company, $actorId, $config->environment, FiscalConfigAudit::TYPE_DISCONNECTED, FiscalConfigAudit::RESULT_OK);

        return $config->fresh();
    }

    public function updatePreferences(Company $company, array $attributes, ?int $actorId = null): CompanyFiscalConfig
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
        $this->audit($company, $actorId, $config->environment, FiscalConfigAudit::TYPE_PREFERENCES, FiscalConfigAudit::RESULT_OK);

        return $config->fresh();
    }

    /**
     * Verifica SIN emitir. Si hay cambios pendientes, se verifican esos y
     * solo se activan con éxito (la conexión vigente sigue intacta si
     * falla). Sin pendientes, revalida la conexión vigente.
     */
    public function verify(Company $company, ?int $actorId = null): FiscalConnectionResult
    {
        $config = $this->ensure($company);
        $pending = $config->hasPending();

        $providerCode = ($pending && $config->pending_provider !== null)
            ? $config->pending_provider
            : $config->provider;

        $key = ($pending && trim((string) ($config->pending_api_key ?? '')) !== '')
            ? (string) $config->pending_api_key
            : (string) ($config->provider_api_key ?? '');

        $secret = ($pending && trim((string) ($config->pending_api_secret ?? '')) !== '')
            ? (string) $config->pending_api_secret
            : (string) ($config->provider_api_secret ?? '');

        if (trim($key) === '' || trim($secret) === '') {
            return $this->recordFailure($config, $actorId, 'missing_credentials', 'Faltan las credenciales del proveedor.');
        }

        if (! in_array($providerCode, array_keys((array) config('fiscal.providers', [])), true)) {
            return $this->recordFailure($config, $actorId, 'unknown_provider', 'Proveedor fiscal no configurado.');
        }

        $class = config('fiscal.providers.' . $providerCode);

        if (! is_string($class) || ! class_exists($class)) {
            return $this->recordFailure($config, $actorId, 'unknown_provider', 'Proveedor fiscal no configurado.');
        }

        $provider = app()->make($class);

        if (! $provider instanceof FiscalConnectionVerifiable) {
            return $this->recordFailure($config, $actorId, 'verify_unsupported', 'Este proveedor no permite verificar sin emitir.');
        }

        $result = $provider->verifyConnection(['api_key' => $key, 'api_secret' => $secret]);

        if ($result->connected) {
            if ($pending) {
                if ($config->pending_provider !== null) {
                    $config->provider = $config->pending_provider;
                }

                if ($config->pending_environment !== null) {
                    $config->environment = $config->pending_environment;
                }

                if (trim((string) ($config->pending_api_key ?? '')) !== '') {
                    $config->provider_api_key = $config->pending_api_key;
                }

                if (trim((string) ($config->pending_api_secret ?? '')) !== '') {
                    $config->provider_api_secret = $config->pending_api_secret;
                }

                $config->pending_provider = null;
                $config->pending_environment = null;
                $config->pending_api_key = null;
                $config->pending_api_secret = null;
                $this->audit($company, $actorId, $config->environment, FiscalConfigAudit::TYPE_CONNECTION_ACTIVATED, FiscalConfigAudit::RESULT_OK);
            }

            $config->last_verified_at = now();
            $config->last_error_code = null;
            $config->last_error_message = null;
            $config->save();
            $this->audit($company, $actorId, $config->environment, FiscalConfigAudit::TYPE_VERIFIED, FiscalConfigAudit::RESULT_OK);

            return $result;
        }

        return $this->recordFailure($config, $actorId, $result->errorCode ?? 'verify_failed', $result->message ?? 'No se pudo verificar la conexión.');
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

        if ($config->last_error_code !== null) {
            return self::STATUS_ATTENTION;
        }

        if (! $this->identityComplete($company) || ! $config->hasCredentials()) {
            return self::STATUS_INCOMPLETE;
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

    private function recordFailure(CompanyFiscalConfig $config, ?int $actorId, string $code, string $message): FiscalConnectionResult
    {
        $config->last_verified_at = null;
        $config->last_error_code = $code;
        $config->last_error_message = $message;
        $config->save();
        $this->audit($config->company, $actorId, $config->environment, FiscalConfigAudit::TYPE_VERIFY_FAILED, FiscalConfigAudit::RESULT_ERROR);

        return FiscalConnectionResult::failed($code, $message);
    }

    private function audit(Company $company, ?int $actorId, string $environment, string $changeType, string $result): void
    {
        FiscalConfigAudit::create([
            'company_id' => $company->id,
            'user_id' => $actorId,
            'environment' => $environment,
            'change_type' => $changeType,
            'result' => $result,
        ]);
    }
}
