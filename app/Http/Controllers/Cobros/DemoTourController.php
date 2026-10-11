<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosDemoSession;
use App\Services\Cobros\CobrosDemoSessionService;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * BLOQUE 21D-4C.1 — recorrido guiado del DEMO individual del visitante.
 *
 * Pasos navegables (bienvenida → datos ficticios → modalidad) guardados en la
 * MISMA fila cobros_demo_sessions del visitante (tour_step / tour_answers).
 * Autorización y vencimiento SIEMPRE en servidor. Datos 100% sintéticos; sin
 * registro, pago, licencia ni activación reales (21D-4C.2).
 */
class DemoTourController extends Controller
{
    /** Orden del recorrido. */
    public const STEPS = ['bienvenida', 'datos', 'modalidad'];

    /** Modalidades ofrecidas en el DEMO guiado (las tres del objetivo). */
    public const MODALITIES = [
        'prestamista' => 'Prestamista',
        'cobrador_independiente' => 'Cobrador independiente',
        'cobrador_vinculado' => 'Cobrador vinculado',
    ];

    /** Países ofrecidos (coherentes con el registro público real). */
    public const COUNTRIES = [
        'CR' => 'Costa Rica',
        'CO' => 'Colombia',
    ];

    public function __construct(
        private readonly CobrosDemoSessionService $sessions,
    ) {}

    /** GET /demo-cobros/tour — muestra el paso actual o el primero. */
    public function show(Request $request, ?string $step = null)
    {
        $session = $this->currentSession($request);
        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        // Solo se puede ver el recorrido si eligió «Recorrido guiado».
        if ($request->session()->get(DemoVisitorController::SESSION_MODE_KEY) !== 'guiado') {
            return redirect()->route('cobros.demo.home');
        }

        $current = $step ?? ($session->tour_step ?? 'bienvenida');
        if (! in_array($current, [...self::STEPS, 'resumen', 'cotizacion', 'activacion'], true)) {
            return redirect()->route('cobros.demo.tour');
        }

        $viewData = [
            'step' => $current,
            'stepIndex' => in_array($current, self::STEPS, true)
                ? (int) array_search($current, self::STEPS, true)
                : count(self::STEPS) - 1,
            'steps' => self::STEPS,
            'answers' => $session->tour_answers ?? [],
            'modalities' => self::MODALITIES,
            'countries' => self::COUNTRIES,
            'quote' => null,
            'payment' => $session->demo_payment,
        ];

        // Cotización DEMO: tarifas VIGENTES vía CobrosPricingService (sin cobrar).
        if (in_array($current, ['cotizacion', 'activacion'], true)) {
            $answers = $session->tour_answers ?? [];
            $country = $answers['country_iso'] ?? null;
            $modality = $answers['modality'] ?? null;

            if ($country === null || $modality === null) {
                // No se puede saltar a cotización sin completar el registro ficticio.
                return redirect()->route('cobros.demo.tour');
            }

            try {
                $viewData['quote'] = app(CobrosPricingService::class)->quote($country, $modality);
            } catch (\Illuminate\Validation\ValidationException) {
                return redirect()->route('cobros.demo.tour');
            }
        }

        return view('cobros.demo.tour', $viewData);
    }

    /**
     * POST /demo-cobros/tour/pago — SIMULA la confirmación de pago.
     *
     * Idempotente: si la sesión ya tiene pago ficticio registrado, no lo
     * duplica ni cambia la operación existente. No llama Stripe, SINPE,
     * webhooks, notificaciones ni crea contratos ni licencias.
     */
    public function pay(Request $request): RedirectResponse
    {
        $session = $this->currentSession($request);
        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        if ($request->session()->get(DemoVisitorController::SESSION_MODE_KEY) !== 'guiado') {
            return redirect()->route('cobros.demo.home');
        }

        $answers = $session->tour_answers ?? [];
        if (($answers['country_iso'] ?? null) === null || ($answers['modality'] ?? null) === null) {
            return redirect()->route('cobros.demo.tour');
        }

        // Idempotencia: segundo confirmar no duplica ni modifica el pago ficticio.
        if (($session->demo_payment ?? null) !== null) {
            return redirect()->route('cobros.demo.tour.step', ['step' => 'activacion']);
        }

        try {
            $quote = app(CobrosPricingService::class)->quote($answers['country_iso'], $answers['modality']);
        } catch (\Illuminate\Validation\ValidationException) {
            return redirect()->route('cobros.demo.tour');
        }

        // exclusivamente en el ESTADO de la sesión DEMO del visitante.
        $session->update([
            'tour_step' => 'activacion',
            'demo_payment' => [
                'operation_id' => 'DEMO-'.Str::upper(Str::random(10)),
                'amount' => $quote->monthlyTotal,
                'currency' => $quote->currency,
                'modality' => $answers['modality'],
                'country_iso' => $answers['country_iso'],
                'confirmed_at' => now()->toIso8601String(),
                'simulated' => true,
            ],
        ]);

        return redirect()->route('cobros.demo.tour.step', ['step' => 'activacion']);
    }

    /**
     * POST /demo-cobros/tour/entrar — ingresa al entorno DEMO operativo.
     *
     * Reutiliza el Core de autenticación multi-tenant EXISTENTE (guard por
     * defecto + active_company_id + middleware de permisos). El owner sintético
     * del visitante pertenece SOLO a su propia empresa aislada, por lo que el
     * aislamiento entre visitantes y contra empresas reales queda garantizado
     * por el mismo mecanismo que separa tenants hoy. No se toma company_id,
     * user_id ni rol del navegador: todo se resuelve en servidor desde la fila
     * de la sesión DEMO.
     */
    public function enter(Request $request): RedirectResponse
    {
        // 21D-4C.2B-2 / INTEGRACIÓN-05: el ingreso operativo DEMO exige
        // habilitación explícita (config demo.enabled + entorno permitido).
        abort_unless(\App\Services\Cobros\CobrosDemoAccess::enabled(), 404);

        $session = $this->currentSession($request);
        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        // Requiere la activación SIMULADA completada (pago ficticio registrado).
        if (($session->demo_payment ?? null) === null) {
            return redirect()->route('cobros.demo.tour.step', ['step' => 'cotizacion']);
        }

        return $this->startOperational($request, $session);
    }

    /**
     * POST /demo-cobros/libre/entrar — EXPLORACIÓN LIBRE (21D-4D-1).
     *
     * Ingresa al entorno operativo ficticio SIN recorrido guiado ni pago
     * simulado. Requiere modo «libre» en la sesión del visitante y una de las
     * 3 modalidades públicas, validadas en servidor y guardadas SOLO en la
     * fila de la sesión DEMO. No confía en IDs, roles ni permisos del
     * navegador; el login usa el owner sintético de la empresa-demo aislada.
     */
    public function enterFree(Request $request): RedirectResponse
    {
        abort_unless(\App\Services\Cobros\CobrosDemoAccess::enabled(), 404);

        $session = $this->currentSession($request);
        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        if ($request->session()->get(DemoVisitorController::SESSION_MODE_KEY) !== 'libre') {
            return redirect()->route('cobros.demo.home');
        }

        $data = $request->validate([
            'modality' => ['required', Rule::in(array_keys(self::MODALITIES))],
        ], [
            'modality.required' => 'Elija una modalidad para explorar.',
            'modality.in' => 'Modalidad de exploración no válida.',
        ]);

        $answers = $session->tour_answers ?? [];
        $answers['modality'] = $data['modality'];
        $session->update(['tour_answers' => $answers]);

        return $this->startOperational($request, $session);
    }

    /** Login DEMO + contexto de empresa a partir de la sesión del visitante. */
    private function startOperational(Request $request, CobrosDemoSession $session): RedirectResponse
    {
        $company = $session->company()->with('owner')->first();
        $owner = $company?->owner;

        if ($company === null || $owner === null || ! $company->is_active || ! $owner->is_active) {
            return redirect()->route('cobros.demo.entry');
        }

        // 21D-FIX-3I: registrar la modalidad en la membresía del usuario
        // autenticado (Core CobrosModalityService) para que
        // EnsureModalityAuthorization aplique la matriz en servidor. El
        // prestamista conserva el owner sintético (administración intacta)
        // pero su membresía queda marcada: sin cobros operativos.
        $modality = $session->tour_answers['modality'] ?? null;
        $user = $owner;
        if (in_array($modality, [
            CobrosModalityCatalog::COBRADOR_INDEPENDIENTE,
            CobrosModalityCatalog::COBRADOR_VINCULADO,
        ], true)) {
            $user = $this->demoCollector($company, $modality, $session);
        } elseif ($modality === CobrosModalityCatalog::PRESTAMISTA) {
            app(\App\Services\Cobros\CobrosModalityService::class)->assign($company, $user, $modality);
        }

        // Contexto DEMO temporal: login + empresa limitada a la del visitante.
        // Sin cuentas operativas ni usuarios reales.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('active_company_id', $company->id);

        return redirect()->to($this->operationalLanding($modality));
    }

    /**
     * Usuario sintético de cobrador DENTRO de la empresa-demo del visitante
     * (email único por empresa: nunca compartido entre visitantes). Rol y
     * permisos salen del Core vía CobrosModalityService (catálogo), jamás
     * del rol administrativo del owner.
     */
    private function demoCollector(\App\Models\Company $company, string $modality, ?CobrosDemoSession $session = null): \App\Models\User
    {
        // FIX DEMO-CARTERA-01: autenticar el cobrador SINTÉTICO del escenario
        // (cobrador.independiente/vinculado.c{N}@demo-cobros.test), que es quien
        // tiene las asignaciones de clientes y créditos en CobrosDemoScenario.
        // El usuario genérico cobrador.demo.c{N}@demo-visitante.test no tiene
        // asignaciones y por eso la cartera mostraba vacía.
        $suffix = 'c'.$company->id;
        $domain = \App\Services\Cobros\CobrosDemoScenario::USER_DOMAIN;
        $localPart = $modality === CobrosModalityCatalog::COBRADOR_VINCULADO
            ? "cobrador.vinculado.{$suffix}"
            : "cobrador.independiente.{$suffix}";

        $scenarioCollector = \App\Models\User::query()
            ->where('email', $localPart.'@'.$domain)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->first();

        if ($scenarioCollector !== null) {
            app(\App\Services\Cobros\CobrosModalityService::class)->assign($company, $scenarioCollector, $modality);

            return $scenarioCollector;
        }

        // Fallback: replicar la creación del escenario si no existe aún.
        $collector = \App\Models\User::query()->firstOrCreate(
            ['email' => $localPart.'@'.$domain],
            [
                'name' => 'Cobrador DEMO',
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                'is_active' => true,
            ]
        );

        if (! $company->users()->where('users.id', $collector->id)->exists()) {
            $company->users()->attach($collector->id, ['role_id' => null]);
        }

        app(\App\Services\Cobros\CobrosModalityService::class)->assign($company, $collector, $modality);

        return $collector;
    }

    /** Pantalla operativa existente que corresponde a la modalidad elegida. */
    private function operationalLanding(?string $modality): string
    {
        $administrative = [
            CobrosModalityCatalog::PRESTAMISTA,
            CobrosModalityCatalog::PRESTAMISTA_COBRADOR,
        ];

        if (in_array($modality, $administrative, true)) {
            return route('cobros.owner.dashboard');
        }

        return route('cobros.collector.index');
    }

    /** POST /demo-cobros/tour/guardar — guarda respuestas del paso y avanza. */
    public function save(Request $request, string $step): RedirectResponse
    {
        $session = $this->currentSession($request);
        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        if ($request->session()->get(DemoVisitorController::SESSION_MODE_KEY) !== 'guiado') {
            return redirect()->route('cobros.demo.home');
        }

        if (! in_array($step, self::STEPS, true)) {
            return redirect()->route('cobros.demo.tour');
        }

        $answers = $session->tour_answers ?? [];

        if ($step === 'datos') {
            $data = $request->validate([
                'name' => ['required', 'string', 'min:3', 'max:150'],
                'email' => ['required', 'email:rfc', 'max:150'],
                'phone' => ['nullable', 'string', 'max:50'],
            ]);
            $answers['personal'] = [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ];
        }

        if ($step === 'modalidad') {
            $data = $request->validate([
                'country_iso' => ['required', 'string', Rule::in(array_keys(self::COUNTRIES))],
                'modality' => ['required', 'string', Rule::in(array_keys(self::MODALITIES))],
            ]);
            $answers['country_iso'] = $data['country_iso'];
            $answers['modality'] = $data['modality'];
        }

        $index = array_search($step, self::STEPS, true);
        $next = self::STEPS[$index + 1] ?? 'resumen';

        // Se persiste el paso DESTINO: al reingresar continúa donde quedó.
        $session->update([
            'tour_step' => $next,
            'tour_answers' => $answers,
        ]);

        return redirect()->route('cobros.demo.tour.step', ['step' => $next]);
    }

    /** GET /demo-cobros/tour/anterior?desde=paso — retroceso sin validar. */
    public function back(Request $request): RedirectResponse
    {
        $desde = $request->query('desde');
        $index = is_string($desde) ? array_search($desde, self::STEPS, true) : false;
        $prev = ($index !== false && $index > 0) ? self::STEPS[$index - 1] : 'bienvenida';

        return redirect()->route('cobros.demo.tour.step', ['step' => $prev]);
    }

    /** POST /demo-cobros/tour/reiniciar — reinicia SOLO el recorrido propio. */
    public function reset(Request $request): RedirectResponse
    {
        $session = $this->currentSession($request);
        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        // SOLO toca la fila de la sesión del propio visitante; nunca la empresa
        // ni datos de otros (no se borra company_id ni owner_user_id).
        $session->update([
            'tour_step' => 'bienvenida',
            'tour_answers' => [],
            'demo_payment' => null,
        ]);

        return redirect()->route('cobros.demo.tour');
    }

    /** Rellena un paso con datos 100% ficticios (autocompletado de demo). */
    public function autofill(Request $request, string $step): RedirectResponse
    {
        $session = $this->currentSession($request);
        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        if ($step === 'datos') {
            $answers = $session->tour_answers ?? [];
            $answers['personal'] = [
                'name' => 'María Demo',
                'email' => 'maria.demo@demo-visitante.test',
                'phone' => '+506 7000-0000',
            ];
            $session->update(['tour_answers' => $answers]);
        }

        return redirect()->route('cobros.demo.tour.step', ['step' => $step]);
    }

    private function currentSession(Request $request): ?CobrosDemoSession
    {
        $token = $request->session()->get(DemoVisitorController::SESSION_TOKEN_KEY);
        if (! is_string($token) || $token === '') {
            return null;
        }

        $session = $this->sessions->resolve($token);
        if ($session === null) {
            $request->session()->forget(DemoVisitorController::SESSION_TOKEN_KEY);
        }

        return $session;
    }
}
