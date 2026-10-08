<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Services\Cobros\CollectorLicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OwnerRouteController extends Controller
{
    public function index(CollectorLicenseService $licenses): View
    {
        $company = $this->company();
        $routes = CobrosRoute::forCompany($company->id)
            ->with(['users' => fn ($query) => $query->wherePivot('active', true), 'license'])
            ->withCount('assignments')->orderBy('name')->paginate(20);

        return view('cobros.owner.routes', compact('company', 'routes', 'licenses'));
    }

    public function create(): View
    {
        $company = $this->company();

        return view('cobros.owner.route-form', [
            'company' => $company, 'routeModel' => new CobrosRoute(['active' => true]),
            'collectors' => $this->collectors($company), 'selected' => [],
        ]);
    }

    public function edit(int $route): View
    {
        $company = $this->company();
        $routeModel = CobrosRoute::forCompany($company->id)->findOrFail($route);

        return view('cobros.owner.route-form', [
            'company' => $company, 'routeModel' => $routeModel,
            'collectors' => $this->collectors($company),
            'selected' => $routeModel->users()->wherePivot('active', true)->pluck('users.id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, int $route): RedirectResponse
    {
        return $this->save($request, $route);
    }

    private function save(Request $request, ?int $routeId = null): RedirectResponse
    {
        $company = $this->company();
        DB::transaction(function () use ($request, $routeId, $company) {
            $route = $routeId === null ? new CobrosRoute(['company_id' => $company->id])
                : CobrosRoute::forCompany($company->id)->lockForUpdate()->findOrFail($routeId);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255', Rule::unique('cobros_routes', 'name')->where('company_id', $company->id)->ignore($route->id)],
                'active' => ['required', 'boolean'],
                'collector_ids' => ['sometimes', 'array'],
                'collector_ids.*' => ['required', 'integer', 'distinct'],
            ], [
                'name.required' => 'Escriba el nombre de la ruta.',
                'name.unique' => 'Ya existe una ruta con este nombre.',
                'name.max' => 'El nombre debe tener hasta 255 caracteres.',
                'active.required' => 'Seleccione el estado de la ruta.',
                'active.boolean' => 'Seleccione Activa o Inactiva.',
                'collector_ids.*' => 'Seleccione cobradores válidos de su empresa.',
            ]);
            $ids = array_map('intval', $data['collector_ids'] ?? []);
            $allowed = $this->collectors($company)->modelKeys();
            if (array_diff($ids, $allowed)) {
                throw ValidationException::withMessages(['collector_ids' => 'Seleccione cobradores activos de su empresa.']);
            }
            $route->fill(['name' => $data['name'], 'active' => $data['active']])->save();
            // Only this route changes; other route memberships and licenses stay intact.
            $route->users()->sync(array_fill_keys($ids, ['active' => true]));
        });

        return redirect()->route('cobros.owner.routes')->with('success', 'Ruta guardada.');
    }

    private function company(): Company
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        abort_unless(auth()->user()->isActive() && auth()->user()->hasPermission('cobros.portal_dueno', $company), 403);

        return $company;
    }

    private function collectors(Company $company): Collection
    {
        return $company->users()->where('users.is_active', true)->orderBy('users.name')->get()
            ->filter(fn ($user) => $user->hasPermission('cobros.cobrar', $company));
    }
}
