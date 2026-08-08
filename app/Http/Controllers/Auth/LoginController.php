<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $key = Str::lower($request->input('email')) . '|' . $request->ip();

if (RateLimiter::tooManyAttempts($key, 5)) {
    $seconds = RateLimiter::availableIn($key);

    return back()
        ->withErrors([
            'email' => "Demasiados intentos de inicio de sesión. Inténtalo nuevamente en {$seconds} segundos.",
        ])
        ->onlyInput('email');
}
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            return back()
                ->withErrors([
                    'email' => 'Las credenciales son incorrectas o el usuario está inactivo.',
                ])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);
        
        $request->session()->regenerate();

        $request->user()->updateLastLogin();

        return redirect()->intended('/dashboard');
    }

    /**
     * Cerrar sesión.
     */
    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/login');
    }
}