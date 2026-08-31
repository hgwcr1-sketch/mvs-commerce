<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\CompanyProvisioner;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'trade_name' => ['required', 'string', 'max:150'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner.phone' => ['nullable', 'string', 'max:50'],
            'plan' => ['required', 'string', 'max:80'], 'branch_limit' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(CompanyLicense::STATUSES)], 'notes' => ['nullable', 'string', 'max:2000'],
            'modules' => ['required', 'array', 'min:1'], 'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))],
        ]);

        $company = $provisioner->commercialOnboard(
            $data['owner'], collect($data)->only(['trade_name', 'plan', 'branch_limit', 'status', 'notes'])->all(),
            $data['modules'], $request->user(),
        );

        return redirect()->route('platform.companies.show', $company)->with('success', 'Tenant y contrato creados. El propietario debe completar su activación y onboarding.');
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));
        $module = trim((string) $request->query('module'));
        $companies = Company::query()
            ->with(['license', 'modules', 'roles:id,company_id,name', 'users:id,name,email'])
            ->withCount(['branches', 'users'])
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('trade_name', 'like', "%{$search}%")
                ->orWhere('legal_name', 'like', "%{$search}%")
                ->orWhere('identification_number', 'like', "%{$search}%")
                ->orWhereHas('users', fn ($users) => $users
                    ->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%"))))
            ->when($status, fn ($query) => $query->whereHas('license', fn ($license) => $license->where('status', $status)))
            ->when($module, fn ($query) => $query->whereHas('modules', fn ($modules) => $modules
                ->where('module_key', $module)
                ->where('is_enabled', true)))
            ->orderBy('trade_name')->paginate(15)->withQueryString();

        return view('platform.index', [
            'companies' => $companies,
            'totals' => [
                'companies' => Company::query()->count(),
                'active_companies' => Company::query()->where('is_active', true)->count(),
                'branches' => Branch::query()->count(),
                'users' => User::query()->count(),
            ],
            'moduleCatalog' => ModuleRegistry::MODULES,
        ]);
    }

    public function show(Company $company, CompanyLicenseService $licenses): View
    {
        $company->load([
            'branches' => fn ($query) => $query->orderBy('name'),
            'users' => fn ($query) => $query->orderBy('name')->withPivot('role_id'),
            'roles:id,company_id,name,is_active',
            'modules',
        ]);

        $company->setRelation('license', $licenses->refresh($licenses->ensure($company)));
        $company->license->load(['events.actor']);

        return view('platform.show', ['company' => $company, 'moduleCatalog' => ModuleRegistry::MODULES]);
    }

    public function updateLicense(Request $request, Company $company, CompanyLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(CompanyLicense::STATUSES)], 'plan' => ['required', 'string', 'max:80'],
            'starts_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date'],
            'next_renewal_at' => ['nullable', 'date'], 'grace_until' => ['nullable', 'date', 'after_or_equal:expires_at'],
            'user_limit' => ['nullable', 'integer', 'min:1'], 'branch_limit' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $status = $data['status'];
        unset($data['status']);
        $licenses->updateContract($company, $request->user(), $status, $data['notes'] ?? null, $data);

        return back()->with('success', 'Licencia actualizada y registrada en el historial.');
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

    public function updateModules(Request $request, Company $company, CompanyLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate(['modules' => ['nullable', 'array'], 'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))]]);
        $enabled = $data['modules'] ?? [];

        $licenses->updateModules($company, $request->user(), $enabled);

        return back()->with('success', 'Módulos contratados actualizados.');
    }
}
