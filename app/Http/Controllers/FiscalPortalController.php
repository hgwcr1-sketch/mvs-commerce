<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyLicense;
use App\Models\ElectronicDocument;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\CompanyFiscalConfigService;
use App\Services\Fiscal\FiscalConsumptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal fiscal MVS del tenant (Facturación Electrónica).
 *
 * MVS Commerce es la interfaz fiscal del cliente: este portal habla con
 * FiscalManager y tipos neutrales; el proveedor concreto (hoy FacturaEnCR,
 * mañana MvsFiscalProvider) queda detrás del contrato, sin acoplarse a
 * Company, POS ni vistas.
 */
class FiscalPortalController extends Controller
{
    public const TYPE_LABELS = [
        '01' => 'Factura electrónica',
        '04' => 'Tiquete electrónico',
        '03' => 'Nota de crédito',
        '02' => 'Nota de débito',
    ];

    public const STATUS_LABELS = [
        'accepted' => 'Aceptado',
        'rejected' => 'Rechazado',
        'error' => 'Falló el envío',
        'pending' => 'En proceso',
        'queued' => 'En proceso',
        'signing' => 'En proceso',
        'sent' => 'En proceso',
        'polling' => 'En proceso',
    ];

    public function __construct(
        private readonly CompanyFiscalConfigService $configs,
        private readonly FiscalConsumptionService $consumption,
        private readonly CompanyLicenseService $licenses,
    ) {
    }

    private function company(): Company
    {
        return Company::findOrFail(session('active_company_id'));
    }

    public function index(): View
    {
        $company = $this->company();
        $license = $this->licenses->refresh($this->licenses->ensure($company));
        $config = $this->configs->ensure($company);
        $status = $this->configs->status($company, $license);
        $usage = $this->consumption->monthlyBreakdown($company->id);

        $recent = ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $latest = ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->orderByDesc('updated_at')
            ->first();

        return view('fiscal.index', [
            'company' => $company,
            'license' => $license,
            'config' => $config,
            'status' => $status,
            'statusLabel' => $this->statusLabel($status),
            'environmentLabel' => $config->isProduction() ? 'Producción' : 'Pruebas',
            'usage' => $usage,
            'quotaText' => $this->quotaText($license, $usage),
            'recent' => $recent,
            'latest' => $latest,
            'typeLabels' => self::TYPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
            'diagnostic' => $this->diagnostic($company, $license, $config, $latest),
        ]);
    }

    public function history(Request $request): View
    {
        $company = $this->company();
        $type = $request->query('type');

        $query = ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->orderByDesc('id');

        if (in_array($type, ['01', '04', '03', '02'], true)) {
            $query->where('document_type', $type);
        } else {
            $type = null;
        }

        return view('fiscal.history', [
            'company' => $company,
            'documents' => $query->paginate(20)->withQueryString(),
            'type' => $type,
            'typeLabels' => self::TYPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function setup(string $step): View
    {
        $company = $this->company();
        $config = $this->configs->ensure($company);
        $license = $this->licenses->refresh($this->licenses->ensure($company));

        return view('fiscal.setup', [
            'company' => $company,
            'config' => $config,
            'license' => $license,
            'step' => $step,
            'status' => $this->configs->status($company, $license),
            'providers' => $this->providerOptions(),
        ]);
    }

    public function store(Request $request, string $step): RedirectResponse
    {
        $company = $this->company();

        match ($step) {
            'datos' => $this->storeIdentity($request, $company),
            'conexion' => $this->storeConnection($request, $company),
            'preferencias' => $this->storePreferences($request, $company),
            default => null,
        };

        if ($step === 'preferencias') {
            return redirect()->route('fiscal.index')->with('status', 'Preferencias guardadas.');
        }

        return redirect()->route('fiscal.setup', ['step' => $this->nextStep($step)])
            ->with('status', 'Cambios guardados.');
    }

    public function verify(): RedirectResponse
    {
        $company = $this->company();
        $result = $this->configs->verify($company);

        if ($result->connected) {
            return redirect()->route('fiscal.setup', ['step' => 'verificar'])
                ->with('status', 'Conexión verificada. No se emitió ningún documento.');
        }

        return redirect()->route('fiscal.setup', ['step' => 'verificar'])
            ->withErrors(['verify' => $this->friendlyError($result->errorCode)]);
    }

    private function storeIdentity(Request $request, Company $company): void
    {
        $validated = $request->validate([
            'identification_type' => ['required', 'string', 'max:10'],
            'identification_number' => ['required', 'string', 'max:30'],
            'legal_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $this->configs->updateIdentity($company, $validated);
    }

    private function storeConnection(Request $request, Company $company): void
    {
        $allowedProviders = array_keys((array) config('fiscal.providers', []));

        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:' . implode(',', $allowedProviders)],
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $this->configs->updateConnection($company, $validated);
    }

    private function storePreferences(Request $request, Company $company): void
    {
        $validated = $request->validate([
            'default_document' => ['required', 'string', 'in:01,04'],
            'auto_emit_enabled' => ['nullable', 'boolean'],
            'notify_receptor_email' => ['nullable', 'boolean'],
        ]);

        $validated['auto_emit_enabled'] = $request->boolean('auto_emit_enabled');
        $validated['notify_receptor_email'] = $request->boolean('notify_receptor_email');

        $this->configs->updatePreferences($company, $validated);
    }

    private function nextStep(string $step): string
    {
        return match ($step) {
            'datos' => 'conexion',
            'conexion' => 'verificar',
            'verificar' => 'preferencias',
            default => 'confirmacion',
        };
    }

    private function providerOptions(): array
    {
        $options = [];

        foreach (array_keys((array) config('fiscal.providers', [])) as $code) {
            $options[$code] = $code === CompanyFiscalConfig::PROVIDER_FACTURAENCR
                ? 'FacturaEnCR (proveedor técnico actual)'
                : 'Proveedor ' . $code;
        }

        return $options;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            CompanyFiscalConfigService::STATUS_READY => 'Conectado',
            CompanyFiscalConfigService::STATUS_INCOMPLETE => 'Incompleto',
            CompanyFiscalConfigService::STATUS_ATTENTION => 'Requiere atención',
            default => 'Módulo no habilitado',
        };
    }

    private function quotaText(CompanyLicense $license, array $usage): string
    {
        if (! $license->fiscal_enabled) {
            return '';
        }

        if ($license->fiscal_monthly_quota === null) {
            return " Consumidos este mes: {$usage['total']} (sin límite).";
        }

        $left = max(0, $license->fiscal_monthly_quota - $usage['total']);

        return " Consumidos: {$usage['total']} de {$license->fiscal_monthly_quota} (disponibles: {$left}).";
    }

    /**
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    private function diagnostic(Company $company, CompanyLicense $license, CompanyFiscalConfig $config, ?ElectronicDocument $latest): array
    {
        return [
            [
                'label' => 'Conexión',
                'ok' => $config->last_verified_at !== null && $config->last_error_code === null,
                'detail' => $config->last_verified_at !== null
                    ? 'Verificada el ' . $config->last_verified_at->format('d/m/Y H:i') . '.'
                    : 'Sin verificar. Complete la conexión.',
            ],
            [
                'label' => 'Licencia',
                'ok' => (bool) $license->fiscal_enabled,
                'detail' => $license->fiscal_enabled
                    ? 'Módulo fiscal habilitado por Panel Maestro.'
                    : 'Módulo no habilitado. El POS solo emite tiquetes internos.',
            ],
            [
                'label' => 'Configuración fiscal',
                'ok' => $this->configs->identityComplete($company) && $config->hasCredentials(),
                'detail' => $this->configs->identityComplete($company) && $config->hasCredentials()
                    ? 'Datos fiscales y credenciales registradas.'
                    : 'Faltan datos fiscales o credenciales.',
            ],
            [
                'label' => 'Última comunicación',
                'ok' => $latest !== null && $latest->status === 'accepted',
                'detail' => $latest !== null
                    ? (self::STATUS_LABELS[$latest->status] ?? $latest->status) . ' el ' . $latest->updated_at->format('d/m/Y H:i') . '.'
                    : 'Aún no se ha emitido ningún documento.',
            ],
        ];
    }

    private function friendlyError(?string $code): string
    {
        return match ($code) {
            'missing_credentials' => 'Registre las credenciales del proveedor antes de verificar.',
            'invalid_credentials' => 'Las credenciales fueron rechazadas. Revíselas e intente de nuevo.',
            'unknown_provider' => 'Proveedor fiscal no configurado. Contacte soporte.',
            'verify_unsupported' => 'Este proveedor no permite verificar sin emitir.',
            default => 'No se pudo verificar la conexión. Reintente en unos minutos.',
        };
    }
}
