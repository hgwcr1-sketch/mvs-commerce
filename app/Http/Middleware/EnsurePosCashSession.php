<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Cash\CashSessionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosCashSession
{
    public function __construct(private readonly CashSessionResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        if (! $user || ! $companyId || ! $branchId) {
            return $next($request);
        }

        $company = Company::findOrFail($companyId);
        if ($mode === 'after-login' && ! $user->hasPermission('pos.acceder', $company)) {
            return $next($request);
        }

        if ($this->resolver->applicable($user, $companyId, $branchId)->isNotEmpty()) {
            return $next($request);
        }

        // El checkout conserva la validación transaccional y mensajes detallados
        // de CashSessionResolver bajo lock; nunca depende solo de esta redirección.
        if ($request->routeIs('pos.checkout')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Debe abrir una sesión de caja válida para operar el POS.',
                'errors' => ['cash_session_id' => ['No existe una sesión abierta para este usuario y sucursal.']],
            ], 409);
        }

        if ($user->hasPermission('caja.abrir', $company)) {
            session(['cash_open_return_to_pos' => true]);

            return redirect()->route('cash.open.create')
                ->with('warning', 'Abra una caja para comenzar a operar el POS.');
        }

        return redirect()->route('cash.required')
            ->with('warning', 'Necesita una caja abierta, pero no tiene permiso para abrirla.');
    }
}
