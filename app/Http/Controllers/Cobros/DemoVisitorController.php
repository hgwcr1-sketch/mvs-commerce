<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosDemoSession;
use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BLOQUE 21D-4B — entrada pública de la sesión DEMO individual por visitante.
 *
 * Autorización SIEMPRE en servidor: la vista jamás confía en el token; cada
 * petición resuelve el token de sesión contra BD (hash + vencimiento). Un
 * token ajeno o vencido => se ignora y se redirige a la entrada. Sin registro,
 * pagos ni activaciones.
 */
class DemoVisitorController extends Controller
{
    public const SESSION_TOKEN_KEY = 'cobros.demo_token';
    public const SESSION_MODE_KEY = 'cobros.demo_mode';

    public const MODES = ['guiado', 'libre'];

    public function __construct(
        private readonly CobrosDemoSessionService $sessions,
    ) {}

    /** GET /demo-cobros — inicia (nuevo visitante) o retoma (token válido) el DEMO. */
    public function entry(Request $request): RedirectResponse
    {
        $token = $request->session()->get(self::SESSION_TOKEN_KEY);
        $session = is_string($token) ? $this->sessions->resolve($token) : null;

        if ($session === null) {
            // Visitante nuevo o sesión vencida: crea (o reemplaza) su espacio propio.
            $started = $this->sessions->start();
            $request->session()->put(self::SESSION_TOKEN_KEY, $started['token']);
        }

        return redirect()->route('cobros.demo.home');
    }

    /** GET /demo-cobros/inicio — pantalla del visitante (opciones de recorrido). */
    public function home(Request $request)
    {
        $session = $this->currentSession($request);

        if ($session === null) {
            return redirect()->route('cobros.demo.entry');
        }

        return view('cobros.demo.home', [
            'mode' => $request->session()->get(self::SESSION_MODE_KEY),
            'expiresAt' => $session->expires_at,
        ]);
    }

    /** POST /demo-cobros/modo — elige «Recorrido guiado» o «Exploración libre». */
    public function chooseMode(Request $request): RedirectResponse
    {
        if ($this->currentSession($request) === null) {
            return redirect()->route('cobros.demo.entry');
        }

        $data = $request->validate([
            'mode' => ['required', Rule::in(self::MODES)],
        ]);

        $request->session()->put(self::SESSION_MODE_KEY, $data['mode']);

        // 21D-FIX-1: «Comenzar recorrido» (guiado) entra al primer paso del tour
        // en la MISMA sesión del visitante, sin reiniciar su progreso. La
        // exploración libre conserva la home como base de operaciones.
        return redirect()->route($data['mode'] === 'guiado' ? 'cobros.demo.tour' : 'cobros.demo.home');
    }

    /**
     * Resuelve la sesión del visitante SOLO en servidor (hash + vencimiento).
     * Un token desconocido o de otro visitante nunca resuelve.
     */
    private function currentSession(Request $request): ?CobrosDemoSession
    {
        $token = $request->session()->get(self::SESSION_TOKEN_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $session = $this->sessions->resolve($token);

        if ($session === null) {
            $request->session()->forget(self::SESSION_TOKEN_KEY);
            $request->session()->forget(self::SESSION_MODE_KEY);
        }

        return $session;
    }
}
