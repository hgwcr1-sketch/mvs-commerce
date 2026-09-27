<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyFiscalConfig extends Model
{
    public const PROVIDER_FACTURAENCR = 'facturaencr';

    /** Futuro proveedor MVS: se registra en config/fiscal.php sin rehacer portal/POS. */
    public const PROVIDER_MVSFISCAL = 'mvsfiscal';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    protected $fillable = [
        'company_id',
        'provider',
        'environment',
        'economic_activity',
        'fiscal_branch_code',
        'fiscal_terminal_code',
        'default_document',
        'auto_emit_enabled',
        'notify_receptor_email',
        'last_verified_at',
        'last_error_code',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'provider_api_key' => 'encrypted',
            'provider_api_secret' => 'encrypted',
            'auto_emit_enabled' => 'boolean',
            'notify_receptor_email' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function hasCredentials(): bool
    {
        return trim((string) $this->provider_api_key) !== ''
            && trim((string) $this->provider_api_secret) !== '';
    }

    public function isProduction(): bool
    {
        return $this->environment === self::ENV_PRODUCTION;
    }

    public function maskedKey(): ?string
    {
        $key = trim((string) $this->provider_api_key);

        if ($key === '') {
            return null;
        }

        return '••••' . substr($key, -4);
    }

    public function maskedSecret(): ?string
    {
        if (trim((string) $this->provider_api_secret) === '') {
            return null;
        }

        return '••••••••';
    }
}
