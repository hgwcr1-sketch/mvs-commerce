<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Services\Cobros\CobrosModalityCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicRegistrationController extends Controller
{
    /**
     * Banda de progreso del registro. Los pasos 1-3 están activos en esta
     * fase; el pago (4) queda pendiente y no crea cuenta, licencia ni acceso.
     */
    public const PROGRESS_STEPS = [
        1 => 'País y modalidad',
        2 => 'Datos personales',
        3 => 'Revisión',
        4 => 'Pago',
    ];

    public const REVIEW_STEP = 3;

    /** Países habilitados inicialmente. Los demás se habilitarán después. */
    public const AVAILABLE_COUNTRIES = [
        'CR' => 'Costa Rica',
        'CO' => 'Colombia',
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

        return view("cobros.register.step{$step}", [
            'step' => $step,
            'progressSteps' => self::PROGRESS_STEPS,
            'countries' => self::AVAILABLE_COUNTRIES,
            'modalities' => CobrosModalityCatalog::all(),
            'registration' => session(self::SESSION_KEY, []),
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

        return redirect()->route('cobros.register.step', ['paso' => self::REVIEW_STEP]);
    }

    /**
     * Paso alcanzable con los datos guardados en sesión: 1 sin datos,
     * 2 con país+modalidad, 3 con datos personales. Permite regresar
     * y corregir en cualquier momento dentro de la misma sesión.
     */
    private function currentStep(): int
    {
        $data = session(self::SESSION_KEY, []);

        if (! empty($data['personal']['name']) && ! empty($data['personal']['email'])) {
            return self::REVIEW_STEP;
        }

        if (! empty($data['country_iso']) && ! empty($data['modality'])) {
            return 2;
        }

        return 1;
    }
}
