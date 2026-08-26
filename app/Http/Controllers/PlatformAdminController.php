<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformAdminController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $companies = Company::query()->withCount(['branches', 'users'])
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('trade_name', 'like', "%{$search}%")
                ->orWhere('legal_name', 'like', "%{$search}%")
                ->orWhere('identification_number', 'like', "%{$search}%")))
            ->orderBy('trade_name')->paginate(15)->withQueryString();

        return view('platform.index', [
            'companies' => $companies,
            'totals' => [
                'companies' => Company::query()->count(),
                'active_companies' => Company::query()->where('is_active', true)->count(),
                'branches' => Branch::query()->count(),
                'users' => User::query()->count(),
            ],
        ]);
    }

    public function show(Company $company): View
    {
        $company->load([
            'branches' => fn ($query) => $query->orderBy('name'),
            'users' => fn ($query) => $query->orderBy('name')->withPivot('role_id'),
            'roles:id,company_id,name,is_active',
            'modules',
        ]);

        return view('platform.show', ['company' => $company, 'moduleCatalog' => ModuleRegistry::MODULES]);
    }

    public function updateCompany(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'trade_name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'currency' => ['required', Rule::in(['CRC', 'USD'])],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $company->update($data);

        return back()->with('success', 'Configuración de empresa actualizada.');
    }

    public function updateBranch(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        abort_unless($branch->company_id === $company->id, 404);
        $branch->update($request->validate(['is_active' => ['required', 'boolean']]));

        return back()->with('success', 'Estado de sucursal actualizado.');
    }

    public function updateUser(Request $request, Company $company, User $user): RedirectResponse
    {
        abort_unless($user->companies()->whereKey($company->id)->exists(), 404);
        abort_if($user->is($request->user()) && ! $request->boolean('is_active'), 422, 'No puede desactivar su propia cuenta maestra.');
        $user->update($request->validate(['is_active' => ['required', 'boolean']]));

        return back()->with('success', 'Estado de usuario actualizado.');
    }

    public function updateModules(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['modules' => ['nullable', 'array'], 'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))]]);
        $enabled = $data['modules'] ?? [];

        foreach (array_keys(ModuleRegistry::MODULES) as $moduleKey) {
            $company->modules()->updateOrCreate(['module_key' => $moduleKey], ['is_enabled' => in_array($moduleKey, $enabled, true)]);
        }

        return back()->with('success', 'Módulos contratados actualizados.');
    }
}
