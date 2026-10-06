<?php

namespace App\Services\Fiscal;

use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Services\Facturaencr\FacturaencrClient;
use App\Services\Facturaencr\FacturaencrProvider;
use App\Services\Facturaencr\FacturaencrResponse;
use Throwable;

/**
 * Verificación automática de activación fiscal (solo lectura).
 *
 * Responde a la pregunta "¿ya puedo facturar desde MVS?" sin emitir, sin
 * consumir cuota, sin tocar consecutivos, auto_emit ni electronic_documents.
 *
 * Llamadas permitidas (todas de lectura, con las credenciales de la empresa):
 *  - auth/verify          → llaves válidas + ambiente de la llave
 *  - emisores/{id}/sucursales → alta del emisor en el proveedor
 *  - certificates         → certificado activo (sólo ambiente producción)
 *
 * Los códigos de sucursal y terminal, la identidad y la actividad económica
 * son datos que MVS ya posee: se validan aquí sin volver a pedirlos.
 * Nunca se leen ni se exponen llaves, secretos, PIN, .p12 ni credenciales.
 */
class FiscalActivationService
{
    public const STATE_PENDING_ACTIVATION = 'pending_activation';

    public const STATE_PENDING_CERTIFICATE = 'pending_certificate';

    public const STATE_INCOMPLETE = 'incomplete';

    public const STATE_PENDING_VERIFICATION = 'pending_verification';

    public const STATE_READY = 'ready';

    public const LABELS = [
        self::STATE_PENDING_ACTIVATION => 'Activación pendiente',
        self::STATE_PENDING_CERTIFICATE => 'Certificado pendiente',
        self::STATE_INCOMPLETE => 'Configuración incompleta',
        self::STATE_PENDING_VERIFICATION => 'Verificación pendiente',
        self::STATE_READY => 'Listo para facturar',
    ];

    public const HINTS = [
        self::STATE_PENDING_ACTIVATION => 'Complete el alta del emisor y registre sus llaves en la pantalla del proveedor. Al volver, MVS verifica automáticamente.',
        self::STATE_PENDING_CERTIFICATE => 'Falta el certificado de su emisor. Súbelo en la pantalla del proveedor y vuelva a verificar aquí.',
        self::STATE_INCOMPLETE => 'Complete los datos indicados en MVS y verifique de nuevo: no hace falta pedirlos otra vez en el proveedor.',
        self::STATE_PENDING_VERIFICATION => 'No se pudo confirmar todo en este momento. Presione "Verificar activación" cuando tenga conexión.',
        self::STATE_READY => 'Su empresa está lista para emitir facturación electrónica desde MVS.',
    ];

    public function __construct(private readonly CompanyFiscalConfigService $configs) {}

    /**
     * @return array{state: string, label: string, hint: string, checks: array<int, array{key: string, label: string, status: string, detail: string}>, generated_at: int, verified_at: string}
     */
    public function report(Company $company, ?CompanyFiscalConfig $config = null): array
    {
        $config ??= $this->configs->ensure($company);
        $checks = $this->checks($company, $config);
        $state = $this->state($checks);

        return [
            'state' => $state,
            'label' => self::LABELS[$state],
            'hint' => self::HINTS[$state],
            'checks' => $checks,
            'generated_at' => now()->getTimestamp(),
            'verified_at' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, status: string, detail: string}>
     */
    private function checks(Company $company, CompanyFiscalConfig $config): array
    {
        $remote = $this->remoteEnabled($config);
        $identification = trim((string) $company->identification_number);

        $keyEnvironment = null;
        $credentialsOk = false;

        if (! $config->hasCredentials()) {
            $credentials = $this->item('credentials', 'Llaves de conexión', 'pending', 'Aún no registra la llave y el secreto de conexión.');
        } elseif (! $remote) {
            $credentials = $this->item('credentials', 'Llaves de conexión', 'unknown', 'Este proveedor fiscal no permite comprobar las llaves desde MVS.');
        } else {
            $response = $this->remote(fn () => $this->client($company)->verify());
            $data = is_array($response?->data) ? $response->data : [];

            if ($response === null) {
                $credentials = $this->item('credentials', 'Llaves de conexión', 'unknown', 'No se pudo comprobar la conexión en este momento.');
            } elseif (! $response->isSuccess() || ($data['ok'] ?? null) === false) {
                $credentials = $this->item('credentials', 'Llaves de conexión', 'pending', 'Las llaves registradas no fueron aceptadas. Genere unas nuevas en la pantalla del proveedor.');
            } else {
                $credentialsOk = true;
                $keyEnvironment = is_string($data['apiKey']['environment'] ?? null) ? $data['apiKey']['environment'] : null;
                $credentials = $this->item('credentials', 'Llaves de conexión', 'ok', 'Las llaves fueron aceptadas.');
            }
        }

        if (! $credentialsOk) {
            $emisor = $this->item('emisor', 'Emisor', 'pending', 'Pendiente: primero registre y confirme sus llaves de conexión.');
        } elseif (! $remote || $identification === '') {
            $emisor = $this->item('emisor', 'Emisor', $identification === '' ? 'pending' : 'unknown', $identification === '' ? 'Falta la identificación de su empresa en el Paso 1.' : 'Este proveedor fiscal no permite comprobar el emisor desde MVS.');
        } else {
            $response = $this->remote(fn () => $this->client($company)->get("emisores/{$identification}/sucursales"));

            $emisor = match (true) {
                $response === null => $this->item('emisor', 'Emisor', 'unknown', 'No se pudo confirmar el emisor en este momento.'),
                $response->isSuccess() => $this->item('emisor', 'Emisor', 'ok', 'El emisor de su empresa está dado de alta.'),
                in_array($response->errorCode, ['not_found', 'emisor_no_encontrado', 'emisor_no_asignado', 'HTTP_404'], true) => $this->item('emisor', 'Emisor', 'pending', 'El emisor de su empresa aún no está dado de alta en la pantalla del proveedor.'),
                default => $this->item('emisor', 'Emisor', 'unknown', 'No se pudo confirmar el emisor en este momento.'),
            };
        }

        $environment = match (true) {
            $keyEnvironment === null => $this->item('environment', 'Ambiente', 'unknown', 'No se pudo confirmar el ambiente de su llave.'),
            $keyEnvironment !== $config->environment => $this->item('environment', 'Ambiente', 'pending', sprintf('Su llave es de %s y MVS está en %s: deben coincidir.', $this->environmentLabel($keyEnvironment), $this->environmentLabel($config->environment))),
            default => $this->item('environment', 'Ambiente', 'ok', sprintf('Ambiente correcto: %s.', $this->environmentLabel($config->environment))),
        };

        $certificate = $this->certificateCheck($company, $config, $credentialsOk, $keyEnvironment, $identification, $remote);

        $branch = $this->formatCheck('branch', 'Sucursal', (string) $config->fiscal_branch_code, 3, 'sucursal');
        $terminal = $this->formatCheck('terminal', 'Terminal', (string) $config->fiscal_terminal_code, 5, 'terminal');

        $fiscalMissing = [];
        foreach ([
            'razón social' => $company->legal_name,
            'identificación' => $company->identification_number,
            'actividad económica' => $config->economic_activity,
        ] as $label => $value) {
            if (trim((string) $value) === '') {
                $fiscalMissing[] = $label;
            }
        }

        $fiscal = $fiscalMissing === []
            ? $this->item('fiscal', 'Configuración fiscal', 'ok', 'Razón social, identificación y actividad económica registradas.')
            : $this->item('fiscal', 'Configuración fiscal', 'pending', 'Falta en el Paso 1: '.implode(', ', $fiscalMissing).'.');

        return [$credentials, $emisor, $environment, $certificate, $branch, $terminal, $fiscal];
    }

    /**
     * Certificado activo: en pruebas no es obligatorio; en producción se
     * consulta la lista de certificados de la cédula (sin subir nada).
     *
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function certificateCheck(
        Company $company,
        CompanyFiscalConfig $config,
        bool $credentialsOk,
        ?string $keyEnvironment,
        string $identification,
        bool $remote,
    ): array {
        if (! $config->isProduction()) {
            return $this->item('certificate', 'Certificado activo', 'ok', 'En modo pruebas no se requiere certificado propio todavía.');
        }

        if (! $credentialsOk) {
            return $this->item('certificate', 'Certificado activo', 'pending', 'Pendiente: primero registre y confirme sus llaves de conexión.');
        }

        if ($keyEnvironment === null) {
            return $this->item('certificate', 'Certificado activo', 'unknown', 'No se pudo comprobar el certificado sin confirmar el ambiente de su llave.');
        }

        if ($keyEnvironment !== $config->environment) {
            return $this->item('certificate', 'Certificado activo', 'unknown', 'El ambiente de su llave no coincide con MVS: corrija el ambiente y verifique de nuevo.');
        }

        if (! $remote || $identification === '') {
            return $this->item('certificate', 'Certificado activo', 'unknown', $identification === '' ? 'Falta la identificación de su empresa en el Paso 1.' : 'Este proveedor fiscal no permite comprobar el certificado desde MVS.');
        }

        $response = $this->remote(fn () => $this->client($company)->get('certificates', [], ['X-Emisor' => $identification]));

        if ($response === null) {
            return $this->item('certificate', 'Certificado activo', 'unknown', 'No se pudo comprobar el certificado en este momento.');
        }

        if (! $response->isSuccess()) {
            if (in_array($response->errorCode, ['forbidden_scope', 'HTTP_403'], true)) {
                return $this->item('certificate', 'Certificado activo', 'unknown', 'Su llave no tiene permiso para consultar certificados. Revíselo en la pantalla del proveedor.');
            }

            return $this->item('certificate', 'Certificado activo', 'unknown', 'No se pudo comprobar el certificado en este momento.');
        }

        $items = $this->certificateItems($response->data);

        if ($items === []) {
            return $this->item('certificate', 'Certificado activo', 'pending', 'Todavía no hay certificado para su emisor. Súbelo en la pantalla del proveedor.');
        }

        foreach ($items as $item) {
            if ($this->certificateLooksInactive($item)) {
                return $this->item('certificate', 'Certificado activo', 'pending', 'El certificado registrado no está activo. Revíselo en la pantalla del proveedor.');
            }
        }

        return $this->item('certificate', 'Certificado activo', 'ok', 'Certificado activo para su emisor.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function certificateItems(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $items = $data['items'] ?? $data['data'] ?? $data;

        if (! is_array($items)) {
            return [];
        }

        if (! array_is_list($items)) {
            $items = [$items];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private function certificateLooksInactive(array $item): bool
    {
        $status = strtolower(trim((string) ($item['status'] ?? $item['estado'] ?? '')));

        if (in_array($status, ['revoked', 'expired', 'inactive', 'invalid', 'revocado', 'vencido', 'inactivo'], true)) {
            return true;
        }

        foreach (['active', 'activo'] as $flag) {
            if (array_key_exists($flag, $item) && $item[$flag] === false) {
                return true;
            }
        }

        foreach (['expiresAt', 'expires_at', 'fechaExpiracion', 'validUntil'] as $field) {
            $expires = $item[$field] ?? null;

            if (is_string($expires) && $expires !== '' && strtotime($expires) !== false && strtotime($expires) < now()->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    private function state(array $checks): string
    {
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }

        foreach (['credentials', 'emisor'] as $key) {
            if (($byKey[$key]['status'] ?? null) === 'pending') {
                return self::STATE_PENDING_ACTIVATION;
            }
        }

        if (($byKey['certificate']['status'] ?? null) === 'pending') {
            return self::STATE_PENDING_CERTIFICATE;
        }

        foreach ($checks as $check) {
            if ($check['status'] === 'pending') {
                return self::STATE_INCOMPLETE;
            }
        }

        foreach ($checks as $check) {
            if ($check['status'] === 'unknown') {
                return self::STATE_PENDING_VERIFICATION;
            }
        }

        return self::STATE_READY;
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function formatCheck(string $key, string $label, string $value, int $digits, string $name): array
    {
        if ($value === '') {
            return $this->item($key, $label, 'pending', "Falta la {$name}: regístrela en el Paso 1.");
        }

        if (strlen($value) !== $digits || ! ctype_digit($value)) {
            return $this->item($key, $label, 'pending', "La {$name} debe tener {$digits} dígitos.");
        }

        return $this->item($key, $label, 'ok', "{$label}: {$value}.");
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function item(string $key, string $label, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    private function remoteEnabled(CompanyFiscalConfig $config): bool
    {
        return config('fiscal.providers.'.$config->provider) === FacturaencrProvider::class;
    }

    private function client(Company $company): FacturaencrClient
    {
        $context = $this->configs->contextFor($company);

        return new FacturaencrClient($context['api_key'], $context['api_secret']);
    }

    private function remote(callable $callback): ?FacturaencrResponse
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }

    private function environmentLabel(string $environment): string
    {
        return $environment === CompanyFiscalConfig::ENV_PRODUCTION ? 'Producción' : 'Pruebas';
    }
}
