<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Canton;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyLicense;
use App\Models\District;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentMailDelivery;
use App\Models\FiscalDocumentCustody;
use App\Models\FiscalSeries;
use App\Models\Province;
use App\Models\Sale;
use App\Services\CompanyLicenseService;
use App\Services\Facturaencr\FacturaencrEmissionService;
use App\Services\Fiscal\CompanyFiscalConfigService;
use App\Services\Fiscal\ElectronicDocumentMailDispatcher;
use App\Services\Fiscal\FiscalActivationService;
use App\Services\Fiscal\FiscalConsumptionService;
use App\Services\Fiscal\FiscalCutoffService;
use App\Services\Fiscal\FiscalProviderSwitchService;
use App\Services\Fiscal\FiscalSeriesService;
use App\Services\TaxpayerLookupService;
use App\Support\IdentificationRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    public const TYPE_ABBREVIATIONS = [
        '01' => 'FE',
        '04' => 'TE',
        '03' => 'NC',
        '02' => 'ND',
    ];

    private const REJECTED_STATUSES = ['rejected', 'error'];

    private const PENDING_STATUSES = ['pending', 'queued', 'signing', 'sent', 'polling'];

    /** Segundos que se reutiliza el resultado de verificación en sesión. */
    private const ACTIVATION_REPORT_TTL = 120;

    private const ACTIVATION_TONES = [
        FiscalActivationService::STATE_PENDING_ACTIVATION => 'bg-amber-100 text-amber-900',
        FiscalActivationService::STATE_PENDING_CERTIFICATE => 'bg-rose-100 text-rose-900',
        FiscalActivationService::STATE_INCOMPLETE => 'bg-orange-100 text-orange-900',
        FiscalActivationService::STATE_PENDING_VERIFICATION => 'bg-sky-100 text-sky-900',
        FiscalActivationService::STATE_READY => 'bg-emerald-100 text-emerald-900',
    ];

    public const DELIVERY_STATUS_LABELS = [
        'sent' => 'Enviado',
        'failed' => 'Falló',
        'skipped' => 'Sin correo',
        'pending' => 'Pendiente',
        'processing' => 'Pendiente',
    ];

    public const DELIVERY_TYPE_LABELS = [
        'automatic' => 'Automático',
        'manual' => 'Manual',
    ];

    public function __construct(
        private readonly CompanyFiscalConfigService $configs,
        private readonly FiscalConsumptionService $consumption,
        private readonly CompanyLicenseService $licenses,
        private readonly FiscalActivationService $activation,
    ) {}

    private function company(): Company
    {
        return Company::findOrFail(session('active_company_id'));
    }

    /**
     * Consulta de contribuyente para el Paso 1 del wizard.
     *
     * Reutiliza exactamente el mismo servicio y las mismas reglas de
     * identificación que el módulo de Clientes y POS: no hay una segunda fuente
     * de datos ni una segunda integración con Hacienda.
     */
    public function taxpayerLookup(Request $request): JsonResponse
    {
        $type = (string) $request->query('tipo', '');
        $identification = trim((string) $request->query('identificacion', ''));

        if (! IdentificationRules::isComplete($type, $identification)) {
            return response()->json([
                'status' => TaxpayerLookupService::STATUS_INVALID,
                'name' => null,
                'message' => 'La identificación no está completa para el tipo seleccionado.',
            ], 422);
        }

        return response()->json(
            app(TaxpayerLookupService::class)->lookup($identification)
        );
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

        $series = FiscalSeries::query()
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
            'activation' => $step === 'conexion' ? $this->activationState($company, $config) : null,
            'locationComplete' => $company->province_id !== null && $company->canton_id !== null && $company->district_id !== null,
            'provinces' => Province::orderBy('id')->get(),
            'cantons' => Canton::orderBy('province_id')->orderBy('id')->get(),
            'districts' => District::orderBy('canton_id')->orderBy('id')->get(),
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
        $requiresCredentials = ! $this->configs->ensure($company)->hasCredentials();

        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'in:'.implode(',', $allowedProviders)],
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'api_key' => [$requiresCredentials ? 'required' : 'nullable', 'string', 'max:255'],
            'api_secret' => [$requiresCredentials ? 'required' : 'nullable', 'string', 'max:255'],
            'production_confirm' => ['nullable'],
        ], [
            'api_key.required' => 'Registre la llave de conexión: esta empresa aún no tiene credenciales activas.',
            'api_secret.required' => 'Registre el secreto de conexión: esta empresa aún no tiene credenciales activas.',
        ]);

        if ($validated['environment'] === 'production' && ! $request->boolean('production_confirm')) {
            throw ValidationException::withMessages([
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

    /**
     * Acción "Continuar activación fiscal": el alta del emisor y del
     * certificado ocurre en la pantalla del proveedor fiscal (intervención
     * manual). MVS solo redirige; nunca lleva llaves, secretos ni archivos
     * sensibles y jamás emite documentos por este camino.
     */
    public function continueActivation(): RedirectResponse
    {
        $company = $this->company();
        $url = (string) config('facturaencr.portal_url', '');

        if ($url === '') {
            return redirect()->route('fiscal.setup', ['step' => 'conexion'])
                ->withErrors(['activation' => 'Falta la dirección de la pantalla de activación del proveedor. Pida al administrador que la configure.']);
        }

        Log::info('fiscal.activation.continue', [
            'company_id' => $company->id,
            'user_id' => auth()->id(),
        ]);

        // Al salir a la pantalla del proveedor se descarta el resultado
        // guardado para que el regreso verifique de nuevo automáticamente.
        session()->forget('fiscal_activation_report_'.$company->id);

        return redirect()->away($url);
    }

    /**
     * Acción "Verificar activación": consulta de solo lectura (llaves,
     * ambiente, emisor, certificado y datos fiscales de esta empresa) sin
     * emitir documentos, sin consumir cuota y sin tocar consecutivos.
     */
    public function verifyActivation(): RedirectResponse
    {
        $company = $this->company();
        $config = $this->configs->ensure($company);
        $report = null;

        try {
            $report = $this->activation->report($company, $config);
        } catch (\Throwable $e) {
            Log::warning('fiscal.activation.verify_failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($report === null) {
            return redirect()->route('fiscal.setup', ['step' => 'conexion'])
                ->withErrors(['activation' => 'No se pudo verificar la activación en este momento. Inténtelo de nuevo.']);
        }

        session(['fiscal_activation_report_'.$company->id => $report]);

        return redirect()->route('fiscal.setup', ['step' => 'conexion'])
            ->with('status', 'Verificación de activación: '.$report['label'].'. '.$report['hint']);
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
            'fiscal_start_at' => ['nullable', 'date'],
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

    /**
     * Panel de activación fiscal del Paso 2.
     *
     * Reutiliza los datos ya guardados en MVS y añade el resultado de la
     * verificación automática de activación (solo lectura, sin emitir). El
     * resultado se guarda en sesión por empresa: una empresa nunca ve la
     * verificación de otra.
     *
     * @return array{state: string, label: string, hint: string, toneClass: string, checks: array<int, array{key: string, label: string, status: string, detail: string}>, ready: bool, verifiedAt: string, rows: array<int, array{label: string, value: ?string, missing: string}>}
     */
    private function activationState(Company $company, CompanyFiscalConfig $config): array
    {
        $report = $this->activationReport($company, $config);

        $rows = [
            ['label' => 'Razón social', 'value' => $this->filled($company->legal_name), 'missing' => 'Falta en Paso 1'],
            ['label' => 'Identificación', 'value' => $this->filled(trim((string) $company->identification_type.' '.(string) $company->identification_number)), 'missing' => 'Falta en Paso 1'],
            ['label' => 'Actividad económica', 'value' => $this->filled($config->economic_activity), 'missing' => 'Falta en Paso 1'],
            ['label' => 'Sucursal', 'value' => $this->filled($config->fiscal_branch_code), 'missing' => 'Falta en Paso 1'],
            ['label' => 'Terminal', 'value' => $this->filled($config->fiscal_terminal_code), 'missing' => 'Falta en Paso 1'],
            ['label' => 'Ambiente', 'value' => $config->isProduction() ? 'Producción' : 'Pruebas', 'missing' => ''],
            ['label' => 'Régimen fiscal', 'value' => $this->taxRegimeLabel($config->tax_regime), 'missing' => 'Sin determinar'],
        ];

        if ($report['state'] === FiscalActivationService::STATE_READY) {
            $rows[] = ['label' => 'Certificado activo', 'value' => $config->isProduction() ? 'Sí' : 'No requerido en pruebas', 'missing' => ''];
            $rows[] = ['label' => 'Última verificación', 'value' => $report['verified_at'], 'missing' => ''];
        }

        return [
            'state' => $report['state'],
            'label' => $report['label'],
            'hint' => $report['hint'],
            'toneClass' => self::ACTIVATION_TONES[$report['state']] ?? self::ACTIVATION_TONES[FiscalActivationService::STATE_PENDING_VERIFICATION],
            'checks' => $report['checks'],
            'ready' => $report['state'] === FiscalActivationService::STATE_READY,
            'verifiedAt' => $report['verified_at'],
            'rows' => $rows,
        ];
    }

    /**
     * Verificación de activación por empresa: se reutiliza el resultado de la
     * sesión si es reciente y, si no existe, MVS verifica automáticamente al
     * volver al paso. Sin llaves registradas no se llama a ningún servicio.
     *
     * @return array{state: string, label: string, hint: string, checks: array<int, array{key: string, label: string, status: string, detail: string}>, generated_at: int, verified_at: string}
     */
    private function activationReport(Company $company, CompanyFiscalConfig $config): array
    {
        $key = 'fiscal_activation_report_'.$company->id;
        $stored = session($key);

        if (is_array($stored)
            && isset($stored['state'], $stored['generated_at'], $stored['verified_at'])
            && (int) $stored['generated_at'] >= now()->getTimestamp() - self::ACTIVATION_REPORT_TTL) {
            return $stored;
        }

        $report = null;

        try {
            $report = $this->activation->report($company, $config);
        } catch (\Throwable $e) {
            Log::warning('fiscal.activation.verify_failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($report === null) {
            $report = [
                'state' => FiscalActivationService::STATE_PENDING_VERIFICATION,
                'label' => FiscalActivationService::LABELS[FiscalActivationService::STATE_PENDING_VERIFICATION],
                'hint' => FiscalActivationService::HINTS[FiscalActivationService::STATE_PENDING_VERIFICATION],
                'checks' => [],
                'generated_at' => now()->getTimestamp(),
                'verified_at' => now()->format('d/m/Y H:i'),
            ];
        }

        session([$key => $report]);

        return $report;
    }

    private function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function taxRegimeLabel(?string $regime): ?string
    {
        return match ($regime) {
            CompanyFiscalConfig::TAX_REGIME_GENERAL => 'Régimen general',
            CompanyFiscalConfig::TAX_REGIME_SIMPLIFIED => 'Régimen simplificado',
            CompanyFiscalConfig::TAX_REGIME_UNKNOWN => 'Sin confirmar',
            default => null,
        };
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
        $seriesCount = FiscalSeries::query()->where('company_id', $company->id)->count();

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
            'series' => FiscalSeries::query()
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
            app(FiscalSeriesService::class)->import(
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

        $custody = FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)
            ->first();

        $sale = $document->sale_id
            ? Sale::query()->with('branch')->find($document->sale_id)
            : null;

        $haciendaStatus = null;
        if ($custody && is_array($custody->response)) {
            $haciendaStatus = $custody->response['status'] ?? $custody->response['estado'] ?? null;
        }

        $deliveries = ElectronicDocumentMailDelivery::query()
            ->where('company_id', $company->id)
            ->where('electronic_document_id', $document->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $receiverEmail = null;
        if ($document->sale_id !== null) {
            $receiverEmail = Sale::query()
                ->where('company_id', $company->id)
                ->find($document->sale_id)?->customer?->email;
        }

        return view('fiscal.document', [
            'company' => $company,
            'document' => $document,
            'sale' => $sale,
            'branchName' => $sale?->branch?->name,
            'haciendaStatus' => $haciendaStatus,
            'xmlAvailable' => $this->findXmlInCustody($custody) !== null,
            'responseAvailable' => $custody && $custody->response !== null,
            'typeLabels' => self::TYPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
            'custody' => $custody,
            'deliveries' => $deliveries,
            'receiverEmail' => $receiverEmail,
            'deliveryStatusLabels' => self::DELIVERY_STATUS_LABELS,
            'deliveryTypeLabels' => self::DELIVERY_TYPE_LABELS,
        ]);
    }

    /**
     * Listado profesional de documentos fiscales con filtros, resumen y
     * soporte de scopes operativos (pendientes / rechazados). Todo consultado
     * SIEMPRE con aislamiento por company_id.
     */
    public function documents(Request $request): View
    {
        $company = $this->company();
        $filters = $this->documentFilters($request);
        $scope = $filters['scope'];

        $base = ElectronicDocument::query()->forCompany($company->id);
        $this->applyDocumentFilters($base, $filters);

        $summary = [
            'accepted' => (clone $base)->where('status', 'accepted')->count(),
            'rejected' => (clone $base)->whereIn('status', self::REJECTED_STATUSES)->count(),
            'pending' => (clone $base)->whereIn('status', self::PENDING_STATUSES)->count(),
            'iva' => (clone $base)->where('electronic_documents.status', 'accepted')
                ->selectRaw('COALESCE((SELECT SUM(sales.tax_total) FROM sales WHERE sales.id = electronic_documents.sale_id AND sales.company_id = ?), 0) as iva_sum', [$company->id])
                ->value('iva_sum'),
        ];

        $pendingSales = collect();
        $pendingReasons = [];
        $documents = collect();

        if ($scope === 'pendientes') {
            $pendingSales = $this->pendingSalesQuery($company, $request)->paginate(20)->withQueryString();
            foreach ($pendingSales->items() as $pendingSale) {
                $pendingReasons[$pendingSale->id] = $this->pendingReason($pendingSale, $company);
            }
        } else {
            $query = ElectronicDocument::query()->forCompany($company->id);
            $this->applyDocumentFilters($query, $filters);
            $this->applyDocumentScope($query, $filters);

            $documents = $query->with(['sale.customer', 'sale.branch'])->orderByDesc('id')->paginate(20)->withQueryString();
        }

        return view('fiscal.documents', [
            'company' => $company,
            'documents' => $documents,
            'pendingSales' => $pendingSales,
            'pendingReasons' => $pendingReasons,
            'summary' => $summary,
            'scope' => $scope,
            'filters' => $filters,
            'downloadParams' => $this->downloadParams($filters),
            'branches' => Branch::query()->where('company_id', $company->id)->orderBy('name')->get(),
            'typeLabels' => self::TYPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    /**
     * Filtros activos de la pantalla Documentos, validados y reutilizados por
     * el listado y por las descargas Excel/ZIP: mismos criterios para ambos.
     *
     * @return array{scope: ?string, type: ?string, status: ?string, environment: ?string, branchId: ?int, from: ?string, to: ?string, q: string}
     */
    private function documentFilters(Request $request): array
    {
        return [
            'scope' => in_array($request->query('scope'), ['pendientes', 'rechazados'], true)
                ? $request->query('scope') : null,
            'type' => in_array($request->query('type'), array_keys(self::TYPE_LABELS), true)
                ? $request->query('type') : null,
            'status' => in_array($request->query('status'), array_keys(self::STATUS_LABELS), true)
                ? $request->query('status') : null,
            'environment' => in_array($request->query('environment'), ['sandbox', 'production'], true)
                ? $request->query('environment') : null,
            'branchId' => $request->filled('branch') ? (int) $request->query('branch') : null,
            'from' => $request->query('from') ?: null,
            'to' => $request->query('to') ?: null,
            'q' => trim((string) $request->query('q')),
        ];
    }

    /**
     * Período por defecto de las descargas: mes en curso mientras el usuario
     * no indique fechas. No se inventan datos, sólo se delimita el rango.
     *
     * @return array<string, mixed>
     */
    private function periodFilters(Request $request): array
    {
        $filters = $this->documentFilters($request);
        $filters['from'] = $filters['from'] ?: now()->startOfMonth()->toDateString();
        $filters['to'] = $filters['to'] ?: now()->toDateString();

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDocumentFilters(Builder $query, array $filters): Builder
    {
        if ($filters['type'] !== null) {
            $query->where('document_type', $filters['type']);
        }
        if ($filters['environment'] !== null) {
            $query->where('environment', $filters['environment']);
        }
        if ($filters['from'] !== null) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if ($filters['to'] !== null) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }
        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $query->where(function ($inner) use ($q) {
                $inner->where('clave', 'like', "%{$q}%")
                    ->orWhere('consecutivo', 'like', "%{$q}%")
                    ->orWhereHas('sale', function ($sale) use ($q) {
                        $sale->whereHas('customer', function ($customer) use ($q) {
                            $customer->where('name', 'like', "%{$q}%")
                                ->orWhere('identification', 'like', "%{$q}%");
                        });
                    });
            });
        }
        if ($filters['branchId'] !== null) {
            $query->whereHas('sale', fn ($sale) => $sale->where('branch_id', $filters['branchId']));
        }

        return $query;
    }

    /**
     * Scope operativo / estado sobre la lista de documentos (no sobre el resumen).
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyDocumentScope(Builder $query, array $filters): Builder
    {
        if ($filters['scope'] === 'rechazados') {
            $query->whereIn('status', self::REJECTED_STATUSES);
        } elseif ($filters['scope'] === 'pendientes') {
            $query->whereIn('status', self::PENDING_STATUSES);
        } elseif ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    /**
     * Documentos del período con los filtros activos. Consulta SIEMPRE
     * acotada por company_id: nunca cruza empresas.
     *
     * @param  array<string, mixed>  $filters
     */
    private function periodDocuments(Company $company, array $filters): Builder
    {
        $query = ElectronicDocument::query()->forCompany($company->id);
        $this->applyDocumentFilters($query, $filters);
        $this->applyDocumentScope($query, $filters);

        return $query->with(['sale.branch', 'sale.customer'])->orderBy('id');
    }

    /**
     * Parámetros de las descargas: conservan los filtros activos de la vista.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function downloadParams(array $filters): array
    {
        return array_filter([
            'scope' => $filters['scope'] ?? null,
            'type' => $filters['type'] ?? null,
            'status' => $filters['status'] ?? null,
            'environment' => $filters['environment'] ?? null,
            'branch' => $filters['branchId'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'q' => $filters['q'] !== '' ? $filters['q'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Refrescar estado = ÚNICAMENTE consulta/polling al proveedor vía
     * FacturaencrEmissionService::syncStatus (GET). Nunca emite ni crea
     * documentos nuevos: solo actualiza estado del existente.
     */
    public function refreshDocument(ElectronicDocument $document): RedirectResponse
    {
        $company = $this->company();
        abort_unless((int) $document->company_id === (int) $company->id, 404);

        $final = in_array($document->status, ['accepted', 'rejected'], true);

        if ($final) {
            return redirect()
                ->route('fiscal.documents.show', $document)
                ->with('status', 'Documento en estado final: no requiere consulta (sin re-emisión).');
        }

        $synced = app(FacturaencrEmissionService::class)
            ->syncStatus($document->fresh());

        return redirect()
            ->route('fiscal.documents.show', $synced)
            ->with('status', 'Estado refrescado por consulta. No se emitió ningún comprobante nuevo.');
    }

    public function downloadResponse(ElectronicDocument $document)
    {
        $company = $this->company();
        abort_unless((int) $document->company_id === (int) $company->id, 404);

        $custody = FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)
            ->first();

        if (! $custody || $custody->response === null) {
            return redirect()
                ->route('fiscal.documents.show', $document)
                ->with('download_notice', 'No disponible: la respuesta de Hacienda aún no está persistida para este documento.');
        }

        return response(
            json_encode($custody->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            200,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="respuesta-'.($document->clave ?: $document->id).'.json"',
            ]
        );
    }

    public function downloadXml(ElectronicDocument $document)
    {
        $company = $this->company();
        abort_unless((int) $document->company_id === (int) $company->id, 404);

        $custody = FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)
            ->first();

        $xml = $this->findXmlInCustody($custody);

        if ($xml === null) {
            return redirect()
                ->route('fiscal.documents.show', $document)
                ->with('download_notice', 'No disponible: el XML firmado aún no está persistido para este documento.');
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="xml-'.($document->clave ?: $document->id).'.xml"',
        ]);
    }

    /**
     * ZIP de XML por período: SOLO con artefactos realmente persistidos en la
     * custodia (XML enviado, XML firmado y respuesta de Hacienda cuando existan).
     * Ningún documento sin custodia genera archivo: se cuentan los excluidos en
     * el manifiesto del ZIP y en el log.
     */
    public function downloadPeriodXml(Request $request)
    {
        $company = $this->company();
        $filters = $this->periodFilters($request);

        $docs = $this->periodDocuments($company, $filters)->get();

        $files = [];
        $included = 0;
        $excluded = [];

        foreach ($docs as $doc) {
            $custody = FiscalDocumentCustody::query()
                ->where('electronic_document_id', $doc->id)
                ->first();

            $xmls = $this->xmlArtifacts($custody);

            if ($xmls === []) {
                $excluded[] = $this->documentReference($doc);

                continue;
            }

            foreach ($xmls as $suffix => $xml) {
                $name = $this->uniqueFileName($files, $this->xmlBaseName($doc).$suffix.'.xml');
                $files[$name] = $xml;
            }

            if ($custody->response !== null && $custody->response !== []) {
                $name = $this->uniqueFileName($files, $this->xmlBaseName($doc).'_respuesta_hacienda.json');
                $files[$name] = json_encode($custody->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $included++;
        }

        if ($files === []) {
            Log::info('fiscal.documents.zip.vacio', [
                'company_id' => $company->id,
                'from' => $filters['from'],
                'to' => $filters['to'],
                'documentos' => $docs->count(),
            ]);

            return redirect()
                ->route('fiscal.documents', $this->downloadParams($filters))
                ->with('download_notice', 'No disponible: ningún documento del período con los filtros activos tiene XML persistido. No se genera ningún archivo inventado.');
        }

        Log::info('fiscal.documents.zip', [
            'company_id' => $company->id,
            'from' => $filters['from'],
            'to' => $filters['to'],
            'documentos' => $docs->count(),
            'incluidos' => $included,
            'excluidos_sin_custodia' => count($excluded),
        ]);

        $manifest = $this->zipManifest($company, $filters, $docs->count(), $included, $excluded);

        $tmp = tempnam(sys_get_temp_dir(), 'fxml');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('resumen_descarga.txt', $manifest);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return response($bytes, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="xml-'.$filters['from'].'_'.$filters['to'].'.zip"',
            'X-MVS-Documentos-Incluidos' => (string) $included,
            'X-MVS-Documentos-Excluidos' => (string) count($excluded),
        ]);
    }

    /**
     * Excel (.xlsx) por período con los filtros activos y las columnas
     * mínimas del portal. Los datos vacíos quedan vacíos: nunca se inventan.
     */
    public function downloadPeriodExcel(Request $request)
    {
        $company = $this->company();
        $filters = $this->periodFilters($request);

        $docs = $this->periodDocuments($company, $filters)->get();

        if ($docs->isEmpty()) {
            return redirect()
                ->route('fiscal.documents', $this->downloadParams($filters))
                ->with('download_notice', 'No disponible: no hay documentos en el período con los filtros activos.');
        }

        $headers = [
            'Fecha/hora',
            'Tipo',
            'Consecutivo',
            'Clave',
            'Receptor',
            'Identificación',
            'Sucursal',
            'Ambiente',
            'Estado',
            'Total',
            'IVA',
            'Provider document ID',
        ];

        $rows = [];
        foreach ($docs as $doc) {
            $sale = $doc->sale;
            $rows[] = [
                $doc->created_at->format('d/m/Y H:i'),
                $this->typeLabel($doc->document_type),
                $doc->consecutivo ?: '',
                $doc->clave ?: '',
                $sale?->customer?->name ?? '',
                $sale?->customer?->identification ?? '',
                $sale?->branch?->name ?? '',
                $doc->environment === 'production' ? 'Producción' : 'Pruebas',
                self::STATUS_LABELS[$doc->status] ?? $doc->status,
                $sale?->total !== null ? (float) $sale->total : '',
                $sale?->tax_total !== null ? (float) $sale->tax_total : '',
                $doc->provider_document_id ?: '',
            ];
        }

        Log::info('fiscal.documents.excel', [
            'company_id' => $company->id,
            'from' => $filters['from'],
            'to' => $filters['to'],
            'documentos' => $docs->count(),
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Documentos');
        $sheet->fromArray(array_merge([$headers], $rows), null, 'A1');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            'documentos-'.$filters['from'].'_'.$filters['to'].'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function typeLabel(string $documentType): string
    {
        $abbreviation = self::TYPE_ABBREVIATIONS[$documentType] ?? $documentType;
        $label = self::TYPE_LABELS[$documentType] ?? '';

        return $label !== '' ? "{$abbreviation} - {$label}" : $abbreviation;
    }

    private function documentReference(ElectronicDocument $document): string
    {
        return $document->consecutivo ?: ($document->clave ?: 'documento '.$document->id);
    }

    /**
     * Sufijos de archivo para los XML realmente custodiados, separando el XML
     * enviado (payload) del XML firmado (respuesta). Sin custodia: nada.
     *
     * @return array<string, string>
     */
    private function xmlArtifacts(?FiscalDocumentCustody $custody): array
    {
        if ($custody === null) {
            return [];
        }

        $artifacts = [];

        $sent = is_array($custody->payload) ? $this->walkForXml($custody->payload) : null;
        if ($sent !== null) {
            $artifacts['_enviado'] = $sent;
        }

        $signed = is_array($custody->response) ? $this->walkForXml($custody->response) : null;
        if ($signed !== null) {
            $artifacts['_firmado'] = $signed;
        }

        return $artifacts;
    }

    /**
     * TIPO_CONSECUTIVO_CLAVE, saneado a caracteres seguros para un ZIP
     * (sin separadores de ruta, sin puntos iniciales ni finales).
     */
    private function xmlBaseName(ElectronicDocument $document): string
    {
        $type = self::TYPE_ABBREVIATIONS[$document->document_type] ?? 'DOC';
        $reference = $document->consecutivo ?: ($document->clave ?: 'id-'.$document->id);
        $key = $document->clave ?: '';

        $base = $type.'_'.$reference.($key !== '' ? '_'.$key : '');
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? '';
        $safe = str_replace('..', '_', $safe);
        $safe = preg_replace('/_+/', '_', $safe) ?? '';
        $safe = trim($safe, '._-');
        $safe = substr($safe, 0, 120);
        $safe = $safe !== '' ? $safe : 'documento-'.$document->id;

        return $safe;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function uniqueFileName(array $files, string $name): string
    {
        if (! isset($files[$name])) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = substr($name, 0, strlen($name) - strlen($extension) - 1);
        $suffix = $extension !== '' ? '.'.$extension : '';
        $attempt = 2;

        while (isset($files[$stem.'-'.$attempt.$suffix])) {
            $attempt++;
        }

        return $stem.'-'.$attempt.$suffix;
    }

    /**
     * Manifiesto del ZIP: registra y muestra cuántos documentos quedaron
     * fuera por falta de custodia y cuáles son.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $excluded
     */
    private function zipManifest(Company $company, array $filters, int $total, int $included, array $excluded): string
    {
        $lines = [
            'MVS Commerce — Descarga de XML por período',
            'Empresa: '.$company->trade_name,
            'Período: '.$filters['from'].' al '.$filters['to'],
            'Documentos en el período con los filtros activos: '.$total,
            'Documentos incluidos con XML persistido: '.$included,
            'Documentos excluidos por falta de custodia/XML: '.count($excluded),
        ];

        if ($excluded !== []) {
            $lines[] = '';
            $lines[] = 'Excluidos (sin XML persistido, no se genera archivo):';
            foreach (array_slice($excluded, 0, 100) as $reference) {
                $lines[] = '- '.$reference;
            }
            if (count($excluded) > 100) {
                $lines[] = '- … y '.(count($excluded) - 100).' más';
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Ventas seleccionadas como FE/TE sin electronic_document, excluyendo
     * siempre las protegidas por fiscal_start_at (históricos) y con razón
     * determinable cuando los datos lo permiten.
     */
    private function pendingSalesQuery(Company $company, Request $request)
    {
        $cutoff = app(FiscalCutoffService::class)->cutoffFor($company->id);

        $query = Sale::query()
            ->where('company_id', $company->id)
            ->whereIn('document_type', [
                Sale::DOCUMENT_ELECTRONIC_INVOICE,
                Sale::DOCUMENT_ELECTRONIC_TICKET,
            ])
            ->where('status', Sale::STATUS_COMPLETED)
            ->where(function ($outer) use ($company) {
                // Sin electronic_document fiscal (01/04) para esta empresa...
                $outer->whereNotExists(function ($sub) use ($company) {
                    $sub->selectRaw('1')
                        ->from('electronic_documents as ed')
                        ->whereColumn('ed.sale_id', 'sales.id')
                        ->where('ed.company_id', $company->id)
                        ->whereIn('ed.document_type', ['01', '04']);
                })
                // ...o con fallo previo al proveedor (error sin acuse del proveedor).
                    ->orWhereExists(function ($sub) use ($company) {
                        $sub->selectRaw('1')
                            ->from('electronic_documents as ed')
                            ->whereColumn('ed.sale_id', 'sales.id')
                            ->where('ed.company_id', $company->id)
                            ->whereIn('ed.document_type', ['01', '04'])
                            ->where('ed.status', 'error')
                            ->whereNull('ed.provider_document_id');
                    });
            });

        if ($cutoff !== null) {
            $query->where(function ($q) use ($cutoff) {
                $q->where('completed_at', '>=', $cutoff)
                    ->orWhereNull('completed_at');
            });
        }

        if ($request->filled('branch')) {
            $query->where('branch_id', (int) $request->query('branch'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to').' 23:59:59');
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->query('q'));
            $query->whereHas('customer', function ($customer) use ($q) {
                $customer->where('name', 'like', "%{$q}%")
                    ->orWhere('identification', 'like', "%{$q}%");
            });
        }

        return $query->with(['customer', 'branch'])->orderByDesc('id');
    }

    private function pendingReason(Sale $sale, Company $company): string
    {
        $failed = ElectronicDocument::query()
            ->forCompany($company->id)
            ->where('sale_id', $sale->id)
            ->whereIn('document_type', ['01', '04'])
            ->where('status', 'error')
            ->whereNull('provider_document_id')
            ->orderByDesc('id')
            ->first();

        if ($failed !== null) {
            return 'Fallo previo al proveedor: '
                .($failed->last_error_message ?: $failed->last_error_code ?: 'firma/envío fallido');
        }

        $config = CompanyFiscalConfig::query()->where('company_id', $company->id)->first();

        if ($config === null || ! $config->hasCredentials()) {
            return 'Configuración incompleta: faltan credenciales del proveedor.';
        }

        if (! $config->auto_emit_enabled) {
            return 'auto_emit desactivado: la venta no entra al flujo automático.';
        }

        if ($sale->document_type === Sale::DOCUMENT_ELECTRONIC_INVOICE && ! $sale->customer_id) {
            return 'Preflight: receptor sin identificación.';
        }

        return 'Aún no procesado por el flujo de emisión.';
    }

    private function findXmlInCustody(?FiscalDocumentCustody $custody): ?string
    {
        if ($custody === null) {
            return null;
        }

        foreach ([$custody->payload, $custody->response] as $source) {
            if (! is_array($source)) {
                continue;
            }
            $found = $this->walkForXml($source);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function walkForXml(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && stripos((string) $key, 'xml') !== false && str_contains($value, '<')) {
                return $value;
            }
            if (is_array($value)) {
                $found = $this->walkForXml($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Envío/reenvío manual del comprobante al correo indicado (CE-MAIL 2).
     *
     * Solo registra la entrega y encola el job existente: no emite, no
     * reemite, no crea ElectronicDocument, no consume cuota y no toca
     * consecutivo/clave/estado. El correo alternativo es solo para ESTE
     * envío; el email maestro del cliente no se modifica.
     */
    public function sendDocumentMail(Request $request, ElectronicDocument $document): RedirectResponse
    {
        $company = $this->company();

        abort_unless((int) $document->company_id === (int) $company->id, 404);

        $validated = $request->validate([
            'recipient' => ['required', 'string', 'email', 'max:150'],
        ], [
            'recipient.required' => 'Indique el correo que recibirá el comprobante.',
            'recipient.email' => 'El correo indicado no es válido.',
            'recipient.max' => 'El correo no puede exceder 150 caracteres.',
        ]);

        if ($document->status !== 'accepted') {
            return back()->withErrors([
                'recipient' => 'Solo se pueden enviar comprobantes con estado Aceptado.',
            ]);
        }

        $delivery = app(ElectronicDocumentMailDispatcher::class)->dispatchManual(
            $document,
            (string) $validated['recipient'],
            auth()->id(),
        );

        if ($delivery === null) {
            return back()->withErrors([
                'recipient' => 'No se pudo registrar el envío. Verifique el correo indicado.',
            ]);
        }

        return redirect()
            ->route('fiscal.documents.show', $document)
            ->with('status', 'Envío del comprobante en cola para '.$delivery->recipient.'.');
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
            ? app(FiscalProviderSwitchService::class)->canSwitch($company, $target)
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
