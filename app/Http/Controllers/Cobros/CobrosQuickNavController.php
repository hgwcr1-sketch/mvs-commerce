<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Cobros\CobrosQuickNavService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CobrosQuickNavController extends Controller
{
    public function edit(Request $request, CobrosQuickNavService $nav): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $user = $request->user();
        $allowed = $nav->allowedRoutes($user, $company);
        $catalog = $nav->catalog();
        $options = [];
        foreach ($allowed as $route) {
            $options[$route] = $catalog[$route]['label'] ?? $route;
        }

        return view('cobros.quick-nav.edit', [
            'company' => $company,
            'options' => $options,
            'selected' => $nav->barRoutes($user, $allowed),
            'needsFour' => count($allowed) >= CobrosQuickNavService::MAX,
        ]);
    }

    public function update(Request $request, CobrosQuickNavService $nav): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $user = $request->user();
        $allowed = $nav->allowedRoutes($user, $company);
        $catalog = array_keys($nav->catalog());

        $data = $request->validate([
            'shortcuts' => ['required', 'array', 'min:1', 'max:'.CobrosQuickNavService::MAX],
            'shortcuts.*' => ['required', 'string', 'in:'.implode(',', $catalog)],
        ]);

        $shortcuts = array_values(array_unique(array_map('strval', $data['shortcuts'])));
        if (count($shortcuts) !== count($data['shortcuts'])) {
            throw ValidationException::withMessages(['shortcuts' => 'Sin duplicados: elija opciones distintas.']);
        }
        // Nunca conceder permisos: todo lo guardado debe estar autorizado.
        foreach ($shortcuts as $route) {
            if (! in_array($route, $allowed, true)) {
                throw ValidationException::withMessages(['shortcuts' => 'Opción no autorizada.']);
            }
        }
        if (count($allowed) >= CobrosQuickNavService::MAX && count($shortcuts) !== CobrosQuickNavService::MAX) {
            throw ValidationException::withMessages(['shortcuts' => 'Seleccione exactamente 4 accesos rápidos.']);
        }

        // Solo el usuario autenticado: nunca se acepta user_id del request.
        $nav->save($user, $shortcuts);

        return redirect()->route('cobros.quick-nav.edit')->with('success', 'Accesos rápidos guardados.');
    }
}
