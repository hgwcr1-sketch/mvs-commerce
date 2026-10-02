<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Cobros\MarkEntry;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /**
     * Mostrar formulario de inicio de sesión.
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Procesar inicio de sesión.
     */
    public function store(Request $request)
    {
        $key = Str::lower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->failedLoginRedirect()
                ->withErrors([
                    'email' => "Demasiados intentos de inicio de sesión. Inténtalo nuevamente en {$seconds} segundos.",
                ])
                ->onlyInput('email');
        }
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return $this->failedLoginRedirect()
                ->withErrors([
                    'email' => 'Las credenciales son incorrectas o el usuario está inactivo.',
                ])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();

        $request->user()->updateLastLogin();

        return $this->authenticated($request);
    }

    protected function authenticated(Request $request)
    {
        if ($request->user()->isPlatformAdmin()) {
            return redirect()->route('platform.index');
        }

        if (! $request->user()->companies()->where('companies.is_active', true)->exists()) {
            return redirect()->route('empresa.create');
        }

        // Entrada explícita a CobrOS (cookie dejada solo por rutas CobrOS):
        // se consume en este login para no condicionar los siguientes.
        $cobrosEntry = $request->cookie(MarkEntry::COOKIE);
        if ($cobrosEntry !== null) {
            Cookie::queue(cookie()->forget(MarkEntry::COOKIE));
        }

        if ($cobrosEntry === MarkEntry::VALUE
            && $this->hasNoSpecificDestination($request)
            && $this->canOperateCobros($request)) {
            $request->session()->forget('url.intended');

            return redirect()->route('cobros.index');
        }

        return redirect()->intended('/dashboard');
    }

    /**
     * Sin destino concreto: sin intención previa o intención genérica de
     * Commerce (`/` o `/dashboard`). Cualquier otra ruta profunda (POS, caja,
     * fidelización, etc.) se respeta sin cambios.
     */
    protected function hasNoSpecificDestination(Request $request): bool
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended) || $intended === '') {
            return true;
        }

        $path = (string) parse_url($intended, PHP_URL_PATH);

        return $path === '/' || $path === '/dashboard';
    }

    /**
     * Vuelve a CobrOS solo si el usuario realmente puede operarlo (empresa
     * activa con módulo contratado y permiso `cobros.acceder`); si no, la
     * puerta Commerce conserva su destino habitual y no se crea un muro 403.
     */
    protected function canOperateCobros(Request $request): bool
    {
        $user = $request->user();
        $companyId = (int) $request->session()->get('active_company_id');

        return $user->companies()
            ->where('companies.is_active', true)
            ->when($companyId !== 0, fn ($query) => $query->where('companies.id', $companyId))
            ->orderBy('companies.id')
            ->get()
            ->contains(fn (Company $company) => $company->isModuleEnabled('cobros')
                && $user->hasPermission('cobros.acceder', $company));
    }

    /**
     * Cerrar sesión.
     */
    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return $this->loggedOut();
    }

    protected function failedLoginRedirect()
    {
        return back();
    }

    protected function loggedOut()
    {
        return redirect('/login');
    }
}
