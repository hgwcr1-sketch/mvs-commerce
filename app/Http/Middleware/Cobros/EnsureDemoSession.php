<?php

namespace App\Http\Middleware\Cobros;

use App\Services\Cobros\CobrosDemoSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Persisted synthetic identity prevents bypass by removing the browser token.
        // Real users visiting the public demo are not restricted by its token.
        if ($user === null || ! str_ends_with((string) $user->email, '@demo-visitante.test')) {
            return $next($request);
        }

        $token = $request->session()->get('cobros.demo_token');
        if (! is_string($token)
            || ! app(CobrosDemoSessionService::class)->allowsOperationalAccess(
                $user, $token, (int) $request->session()->get('active_company_id', 0)
            )) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'La sesión DEMO no está vigente o no pertenece a esta empresa.');
        }

        return $next($request);
    }
}