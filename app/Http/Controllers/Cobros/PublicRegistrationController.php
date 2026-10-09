<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Services\Cobros\CobrosModalityCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicRegistrationController extends Controller
{
    /**
     * Banda de progreso del registro. Los pasos 1-4 están activos en esta
     * fase; el pago (5) queda pendiente y no crea cuenta, licencia ni acceso.
     */
    public const PROGRESS_STEPS = [
        1 => 'País y modalidad',
        2 => 'Datos personales',
        3 => 'Rutas',
        4 => 'Revisión',
        5 => 'Pago',
    ];

    public const REVIEW_STEP = 4;

    public const ROUTES_STEP = 3;

    public const MAX_DRAFT_ROUTES = 20;

    /** Países habilitados inicialmente. Los demás se habilitarán después. */
    public const AVAILABLE_COUNTRIES = [
        'CR' => 'Costa Rica',
        'CO' => 'Colombia',
    ];

    /** Opciones de origen de cartera, solo como borrador (sin importar ahora). */
    public const PORTFOLIO_PLANS = [
        'manual' => 'Registro manual de clientes y créditos',
        'import' => 'Importación con plantillas Excel/CSV',
        'none' => 'Comenzar sin cartera por ahora',
    ];

    /** Precios de referencia por país: administración = 1 ruta del país. */
    public const PRICING = [
        'CR' => ['unit' => 10000, 'decimals' => 2, 'suffix' => '', 'prefix' => '₡'],
        'CO' => ['unit' => 60000, 'decimals' => 0, 'suffix' => ' COP', 'prefix' => '$'],
    ];

    public const SESSION_KEY = 'cobros.registration';

    public function start(): RedirectResponse
    {
        return redirect()->route('cobros.register.step', ['paso' => $this->currentStep()]);
    }

    public function show(Request $request): View|RedirectResponse
    {
        PublicLandingController::captureReferral($request);

        $step = (int) $request->query('paso', 0);

        if ($step < 1 || $step > count(self::PROGRESS_STEPS)) {
            abort(422, 'Paso de registro inválido.');
        }

        $allowed = $this->currentStep();

        if ($step > $allowed) {
            return redirect()->route('cobros.register.step', ['paso' => $allowed]);
        }

        $registration = session(self::SESSION_KEY, []);
        $routes = $registration['routes'] ?? [];
        $editRouteId = null;

        if ($step === self::ROUTES_STEP) {
            $requested = (string) $request->query('editar', '');

            foreach ($routes as $route) {
                if ($route['id'] === $requested) {
                    $editRouteId = $requested;
                    break;
                }
            }
        }

        return view("cobros.register.step{$step}", [
            'step' => $step,
            'progressSteps' => self::PROGRESS_STEPS,
            'countries' => self::AVAILABLE_COUNTRIES,
            'modalities' => CobrosModalityCatalog::all(),
            'portfolioPlans' => self::PORTFOLIO_PLANS,
            'registration' => $registration,
            'editRouteId' => $editRouteId,
            'allowsLenderField' => isset($registration['modality'])
                && CobrosModalityCatalog::allowsMultipleLenders($registration['modality']),
            // Contexto anónimo: nunca autorizado para descargar plantillas operativas.
            'canUseTemplates' => false,
            'priceSummary' => $this->priceSummary($registration),
            'hasReferral' => session(PublicLandingController::REFERRAL_SESSION_KEY) !== null,
        ]);
    }

    public function storeCountryModality(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country_iso' => ['required', 'string', Rule::in(array_keys(self::AVAILABLE_COUNTRIES))],
            'modality' => ['required', 'string', Rule::in(CobrosModalityCatalog::keys())],
        ], [
            'country_iso.in' => 'Seleccione uno de los países habilitados: Costa Rica o Colombia.',
            'modality.in' => 'Seleccione una de las cinco modalidades disponibles.',
        ]);

        $request->session()->put(self::SESSION_KEY, array_merge(session(self::SESSION_KEY, []), [
            'country_iso' => $data['country_iso'],
            'modality' => $data['modality'],
            'updated_at' => now()->toIso8601String(),
        ]));

        return redirect()->route('cobros.register.step', ['paso' => 2]);
    }

    public function storePersonalData(Request $request): RedirectResponse
    {
        if ($this->currentStep() < 2) {
            return redirect()->route('cobros.register.step', ['paso' => 1]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
        ], [
            'email.unique' => 'Ya existe una cuenta con ese correo electrónico.',
        ]);

        $request->session()->put(self::SESSION_KEY, array_merge(session(self::SESSION_KEY, []), [
            'personal' => [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ],
            'updated_at' => now()->toIso8601String(),
        ]));

        return redirect()->route('cobros.register.step', ['paso' => self::ROUTES_STEP]);
    }

    /**
     * Agrega o actualiza (idempotente por id) un borrador de ruta en sesión.
     * No escribe en base de datos: no existen cuentas ni licencias activas.
     */
    public function saveRoute(Request $request): RedirectResponse
    {
        if ($this->currentStep() < self::ROUTES_STEP) {
            return redirect()->route('cobros.register.step', ['paso' => $this->currentStep()]);
        }

        $data = $request->validate([
            'route_id' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'code' => ['nullable', 'string', 'alpha_dash', 'max:12'],
            'lender' => ['nullable', 'string', 'max:150'],
        ]);

        $registration = session(self::SESSION_KEY, []);
        $routes = $registration['routes'] ?? [];
        $routeId = (string) ($data['route_id'] ?? '');

        $entry = [
            'name' => $data['name'],
            'code' => ($data['code'] ?? '') !== '' ? $data['code'] : null,
            'lender' => ($data['lender'] ?? '') !== '' ? $data['lender'] : null,
        ];

        if ($routeId !== '') {
            $updated = false;

            foreach ($routes as $index => $route) {
                if (($route['id'] ?? null) === $routeId) {
                    $routes[$index] = array_merge($route, $entry);
                    $updated = true;
                    break;
                }
            }

            if (! $updated) {
                return back()->withErrors(['route_id' => 'La ruta indicada ya no existe en el borrador.']);
            }
        } else {
            if (count($routes) >= self::MAX_DRAFT_ROUTES) {
                return back()->withErrors(['name' => 'Máximo '.self::MAX_DRAFT_ROUTES.' rutas en borrador.']);
            }

            $routes[] = array_merge($entry, ['id' => Str::lower(Str::uuid()->toString())]);
        }

        $registration['routes'] = $routes;
        $registration['updated_at'] = now()->toIso8601String();
        $request->session()->put(self::SESSION_KEY, $registration);

        return redirect()->route('cobros.register.step', ['paso' => self::ROUTES_STEP]);
    }

    public function removeRoute(Request $request): RedirectResponse
    {
        if ($this->currentStep() < self::ROUTES_STEP) {
            return redirect()->route('cobros.register.step', ['paso' => $this->currentStep()]);
        }

        $data = $request->validate([
            'route_id' => ['required', 'string', 'max:40'],
        ]);

        $registration = session(self::SESSION_KEY, []);
        $routes = collect($registration['routes'] ?? [])->where('id', '!=', $data['route_id'])->values()->all();

        $registration['routes'] = $routes;
        $registration['updated_at'] = now()->toIso8601String();
        $request->session()->put(self::SESSION_KEY, $registration);

        return redirect()->route('cobros.register.step', ['paso' => self::ROUTES_STEP]);
    }

    public function storePortfolioPlan(Request $request): RedirectResponse
    {
        if ($this->currentStep() < self::ROUTES_STEP) {
            return redirect()->route('cobros.register.step', ['paso' => $this->currentStep()]);
        }

        $data = $request->validate([
            'portfolio_plan' => ['required', 'string', Rule::in(array_keys(self::PORTFOLIO_PLANS))],
        ], [
            'portfolio_plan.in' => 'Seleccione cómo iniciará su cartera.',
        ]);

        $registration = session(self::SESSION_KEY, []);
        $registration['portfolio_plan'] = $data['portfolio_plan'];
        $registration['updated_at'] = now()->toIso8601String();
        $request->session()->put(self::SESSION_KEY, $registration);

        return redirect()->route('cobros.register.step', ['paso' => self::REVIEW_STEP]);
    }

    /**
     * Paso alcanzable con los datos guardados en sesión. Permite regresar
     * y corregir en cualquier momento dentro de la misma sesión.
     */
    private function currentStep(): int
    {
        $data = session(self::SESSION_KEY, []);

        $personalDone = ! empty($data['personal']['name']) && ! empty($data['personal']['email']);

        if ($personalDone && ! empty($data['portfolio_plan'])) {
            return self::REVIEW_STEP;
        }

        if ($personalDone) {
            return self::ROUTES_STEP;
        }

        if (! empty($data['country_iso']) && ! empty($data['modality'])) {
            return 2;
        }

        return 1;
    }

    /**
     * Resumen de precios de referencia del país elegido: la administración
     * cuesta siempre lo mismo que una ruta del país. Solo informativo:
     * esta fase no cobra ni acredita nada.
     *
     * @return array{administration: string, route: string, total: string, routeCount: int}|null
     */
    private function priceSummary(array $registration): ?array
    {
        $iso = $registration['country_iso'] ?? '';

        if (! isset(self::PRICING[$iso])) {
            return null;
        }

        $price = self::PRICING[$iso];
        $routeCount = count($registration['routes'] ?? []);

        $format = fn (int $amount): string => $price['prefix']
            .number_format($amount, $price['decimals'], ',', '.')
            .$price['suffix'];

        return [
            'administration' => $format($price['unit']),
            'route' => $format($price['unit']),
            'total' => $format($price['unit'] * ($routeCount + 1)),
            'routeCount' => $routeCount,
        ];
    }
}
