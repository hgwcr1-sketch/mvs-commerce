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

        $period = now()->startOfMonth();

        $counts = [
            'accepted' => ElectronicDocument::query()->where('company_id', $company->id)->where('status', 'accepted')->where('created_at', '>=', $period)->count(),
            'rejected' => ElectronicDocument::query()->where('company_id', $company->id)->where('status', 'rejected')->where('created_at', '>=', $period)->count(),
            'pending' => ElectronicDocument::query()->where('company_id', $company->id)->whereIn('status', ['pending', 'queued', 'signing', 'sent', 'polling'])->where('created_at', '>=', $period)->count(),
        ];

        $series = \App\Models\FiscalSeries::query()
            ->where('company_id', $company->id)
            ->orderBy('environment')->orderBy('document_type')
            ->limit(6)
            ->get();

        $consumption = $this->consumptionCard($license, $usage);

        return view('fiscal.index', [
            'company' => $company,
            'license' => $license,
            'config' => $config,
            'status' => $status,
            'statusLabel' => $this->statusLabel($status),
            'environmentLabel' => $config->isProduction() ? 'Producción' : 'Pruebas',
            'usage' => $usage,
            'consumption' => $consumption,
            'recent' => $recent,
            'latest' => $latest,
            'counts' => $counts,
            'series' => $series,
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
        $status = $this->configs->status($company, $license);

        return view('fiscal.setup', [
            'company' => $company,
            'config' => $config,
            'license' => $license,
            'step' => $step,
            'status' => $status,
            'statusLabel' => $this->statusLabel($status),
            'wizardTitle' => in_array($status, ['ready', 'attention'], true)
                ? 'Configuración de Facturación Electrónica'
                : 'Conectar facturación',
            'providers' => $this->providerOptions(),
            'locationComplete' => $company->province_id !== null && $company->canton_id !== null && $company->district_id !== null,
            'provinces' => \App\Models\Province::orderBy('id')->get(),
            'cantons' => \App\Models\Canton::orderBy('province_id')->orderBy('id')->get(),
            'districts' => \App\Models\District::orderBy('canton_id')->orderBy('id')->get(),
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
            return redirect()->route('fiscal.setup', ['step' => 'confirmacion'])->with('status', 'Preferencias guardadas. Revise y finalice.');
        }

        $next = $this->nextStep($step);
        $saved = match ($step) {
            'datos' => 'Datos guardados. Continúe en el Paso 2.',
            'conexion' => 'Conexión guardada. Verifíquela en el Paso 3.',
            default => 'Cambios guardados.',
        };

        return redirect()->route('fiscal.setup', ['step' => $next])->with('status', $saved);
    }

    public function verify(): RedirectResponse
    {
        $company = $this->company();
        $result = $this->configs->verify($company, auth()->id());

        if ($result->connected) {
            return redirect()->route('fiscal.setup', ['step' => 'preferencias'])
                ->with('status', 'Conexión verificada y activada. No se emitió ningún documento.');
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
            'economic_activity' => ['nullable', 'string', 'max:30'],
            'fiscal_branch_code' => ['nullable', 'regex:/^\d{3}$/'],
            'fiscal_terminal_code' => ['nullable', 'regex:/^\d{5}$/'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'canton_id' => ['nullable', 'exists:cantons,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $this->configs->updateIdentity($company, $validated, auth()->id());
        $this->configs->updateFiscalData($company, $validated);
    }

    private function storeConnection(Request $request, Company $company): void
    {
        $allowedProviders = array_keys((array) config('fiscal.providers', []));

        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'in:' . implode(',', $allowedProviders)],
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'production_confirm' => ['nullable'],
        ]);

        if ($validated['environment'] === 'production' && ! $request->boolean('production_confirm')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'production_confirm' => 'Confirme explícitamente que usará producción con validez fiscal.',
            ]);
        }

        $this->configs->stageConnection($company, $validated, auth()->id());
    }

    public function discardPending(): RedirectResponse
    {
        $company = $this->company();
        $this->configs->discardPending($company, auth()->id());

        return redirect()->route('fiscal.setup', ['step' => 'conexion'])
            ->with('status', 'Cambios descartados. La conexión vigente sigue intacta.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->validate(['disconnect_confirm' => ['accepted']]);

        $company = $this->company();
        $this->configs->disconnect($company, auth()->id());

        return redirect()->route('fiscal.index')
            ->with('status', 'Conexión retirada. El historial fiscal se conserva.');
    }

    public function finish(): RedirectResponse
    {
        return redirect()->route('fiscal.index')
            ->with('status', 'Configuración finalizada. Revise el estado en el portal.');
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

        $this->configs->updatePreferences($company, $validated, auth()->id());
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
                ? 'Conexión fiscal estándar'
                : 'Conexión fiscal alternativa';
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

    /**
     * @return array{used: int, quota: ?int, left: ?int, percent: int, unlimited: bool, overage: int}
     */
    private function consumptionCard(CompanyLicense $license, array $usage): array
    {
        if (! $license->fiscal_enabled || $license->fiscal_monthly_quota === null) {
            return [
                'used' => $usage['total'], 'quota' => null, 'left' => null,
                'percent' => $usage['total'] > 0 ? 100 : 0,
                'unlimited' => true, 'overage' => $usage['overage'],
            ];
        }

        $quota = (int) $license->fiscal_monthly_quota;

        return [
            'used' => $usage['total'],
            'quota' => $quota,
            'left' => max(0, $quota - $usage['total']),
            'percent' => $quota > 0 ? min(100, (int) round($usage['total'] / $quota * 100)) : 0,
            'unlimited' => false,
            'overage' => $usage['overage'],
        ];
    }

    /**
     * @return array<int, array{label: string, ok: bool, detail: string, resolve: ?string}>
     */
    private function diagnostic(Company $company, CompanyLicense $license, CompanyFiscalConfig $config, ?ElectronicDocument $latest): array
    {
        $seriesCount = \App\Models\FiscalSeries::query()->where('company_id', $company->id)->count();

        $configState = $this->configurationState($company, $config);

        return [
            [
                'label' => 'Licencia',
                'ok' => (bool) $license->fiscal_enabled,
                'detail' => $license->fiscal_enabled
                    ? 'Módulo fiscal habilitado por Panel Maestro.'
                    : 'Módulo no habilitado. El POS solo emite tiquetes internos.',
                'resolve' => null,
            ],
            [
                'label' => 'Datos fiscales',
                'ok' => $this->configs->identityComplete($company),
                'detail' => $this->configs->identityComplete($company)
                    ? 'Identificación y nombre fiscal registrados.'
                    : 'Faltan datos fiscales de la empresa.',
                'resolve' => $this->configs->identityComplete($company) ? null : 'datos',
            ],
            [
                'label' => 'Configuración fiscal',
                'ok' => $configState === 'verified',
                'detail' => match ($configState) {
                    'verified' => 'Verificada y lista para emitir.',
                    'pending' => 'Pendiente de verificar.',
                    default => 'Incompleta.',
                },
                'resolve' => $configState === 'verified' ? null : 'verificar',
            ],
            [
                'label' => 'Credenciales de conexión',
                'ok' => $config->hasCredentials(),
                'detail' => $config->hasCredentials()
                    ? 'Credenciales registradas.'
                    : 'Faltan credenciales de conexión.',
                'resolve' => $config->hasCredentials() ? null : 'conexion',
            ],
            [
                'label' => 'Series',
                'ok' => $seriesCount > 0,
                'detail' => $seriesCount > 0
                    ? "{$seriesCount} serie(s) observada(s), sin resets."
                    : 'Las series se registrarán al emitir.',
                'resolve' => null,
            ],
            [
                'label' => 'Actividad con Hacienda',
                'ok' => $latest !== null && $latest->status === 'accepted',
                'detail' => $this->haciendaActivity($latest),
                'resolve' => $latest !== null ? 'historial' : null,
            ],
        ];
    }

    private function configurationState(Company $company, CompanyFiscalConfig $config): string
    {
        if (! $this->configs->identityComplete($company) || ! $config->hasCredentials()) {
            return 'incomplete';
        }

        if ($config->last_error_code !== null || $config->last_verified_at === null) {
            return 'pending';
        }

        return 'verified';
    }

    private function haciendaActivity(?ElectronicDocument $latest): string
    {
        if ($latest === null) {
            return 'Aún no se han enviado documentos.';
        }

        $when = $latest->updated_at->format('d/m/Y H:i');

        return match ($latest->status) {
            'accepted' => "Último documento aceptado el {$when}.",
            'rejected' => "Último documento rechazado el {$when}.",
            default => 'Hay documentos en proceso.',
        };
    }

    public function series(): View
    {
        $company = $this->company();

        return view('fiscal.series', [
            'company' => $company,
            'series' => \App\Models\FiscalSeries::query()
                ->where('company_id', $company->id)
                ->orderBy('environment')->orderBy('document_type')
                ->orderBy('branch_code')->orderBy('terminal_code')
                ->paginate(20),
            'typeLabels' => self::TYPE_LABELS,
        ]);
    }

    public function importSeries(Request $request): RedirectResponse
    {
        $company = $this->company();

        $validated = $request->validate([
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'branch_code' => ['required', 'regex:/^\d{3}$/'],
            'terminal_code' => ['required', 'regex:/^\d{5}$/'],
            'document_type' => ['required', 'string', 'in:01,04,03,02'],
            'last_consecutivo' => ['required', 'regex:/^\d{20}$/'],
        ]);

        try {
            app(\App\Services\Fiscal\FiscalSeriesService::class)->import(
                $company->id,
                $validated['environment'],
                $validated['branch_code'],
                $validated['terminal_code'],
                $validated['document_type'],
                $validated['last_consecutivo'],
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('fiscal.series')->withErrors(['last_consecutivo' => $e->getMessage()]);
        }

        return redirect()->route('fiscal.series')->with('status', 'Serie registrada. Nunca se retrocede ni se resetea.');
    }

    public function showDocument(ElectronicDocument $document): View
    {
        $company = $this->company();

        abort_unless((int) $document->company_id === (int) $company->id, 404);

        return view('fiscal.document', [
            'company' => $company,
            'document' => $document,
            'typeLabels' => self::TYPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
            'custody' => \App\Models\FiscalDocumentCustody::query()
                ->where('electronic_document_id', $document->id)
                ->first(),
        ]);
    }

    /**
     * Pantalla interna MVS (NO tenant): el cambio de proveedor es
     * responsabilidad administrativa. El tenant recibe 403 aunque
     * conozca la URL.
     */
    public function switchChecklist(Request $request): View
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);

        return $this->switchView($this->company(), (string) $request->query('to', ''));
    }

    /**
     * Cambio de proveedor desde Panel Maestro para una empresa explícita.
     */
    public function switchForCompany(Company $company, Request $request): View
    {
        return $this->switchView($company, (string) $request->query('to', ''));
    }

    private function switchView(Company $company, string $target): View
    {
        $check = $target !== ''
            ? app(\App\Services\Fiscal\FiscalProviderSwitchService::class)->canSwitch($company, $target)
            : null;

        return view('fiscal.switch', [
            'company' => $company,
            'providers' => array_keys((array) config('fiscal.providers', [])),
            'target' => $target,
            'check' => $check,
        ]);
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
