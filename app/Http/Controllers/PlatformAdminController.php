<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PlatformAdminController extends Controller
{
    public function createCompany(): View
    {
        return view('platform.onboarding', ['moduleCatalog' => ModuleRegistry::MODULES]);
    }

    public function storeCompany(Request $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'trade_name' => ['required', 'string', 'max:150'], 'legal_name' => ['nullable', 'string', 'max:200'],
            'identification_type' => ['nullable', Rule::in(['01', '02', '03', '04', '05'])],
            'identification_number' => ['nullable', 'string', 'max:50', Rule::unique('companies', 'identification_number')],
            'email' => ['nullable', 'email', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'], 'logo' => ['nullable', 'image', 'max:2048'],
            'currency' => ['required', Rule::in(['CRC', 'USD'])], 'timezone' => ['required', 'timezone'],
            'branches' => ['required', 'array', 'min:1'], 'branches.*.name' => ['required', 'string', 'max:255'],
            'branches.*.code' => ['required', 'string', 'max:50', 'distinct'], 'branches.*.phone' => ['nullable', 'string', 'max:50'],
            'branches.*.address' => ['nullable', 'string', 'max:500'],
            'administrator.name' => ['required', 'string', 'max:255'],
            'administrator.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'administrator.phone' => ['nullable', 'string', 'max:50'],
            'administrator.password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
            'modules' => ['required', 'array', 'min:1'], 'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))],
        ]);

        $logoPath = $request->file('logo')?->store('companies', 'public');
        try {
            $company = $provisioner->onboard(
                $data['administrator'],
                collect($data)->only(['trade_name', 'legal_name', 'identification_type', 'identification_number', 'email', 'phone', 'address', 'currency', 'timezone'])->when($logoPath, fn ($values) => $values->put('logo', $logoPath))->all(),
                $data['branches'],
                $data['modules'],
            );
        } catch (\Throwable $exception) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }
            throw $exception;
        }

        return redirect()->route('platform.companies.show', $company)->with('success', 'Empresa creada y lista para iniciar operaciones.');
    }

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
