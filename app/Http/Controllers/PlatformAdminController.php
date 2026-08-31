<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\LicensePlan;
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
        return view('platform.onboarding', [
            'moduleCatalog' => ModuleRegistry::MODULES,
            'licensePlans' => LicensePlan::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeCompany(Request $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'trade_name' => ['required', 'string', 'max:150'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner.phone' => ['nullable', 'string', 'max:50'],
            'license_plan_id' => ['nullable', Rule::exists('license_plans', 'id')->where('is_active', true)],
            'plan' => ['required_without:license_plan_id', 'nullable', 'string', 'max:80'],
            'branch_limit' => ['nullable', 'integer', 'min:1'], 'user_limit' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(CompanyLicense::STATUSES)], 'notes' => ['nullable', 'string', 'max:2000'],
            'modules' => ['nullable', 'array'], 'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))],
        ]);

        $plan = isset($data['license_plan_id']) ? LicensePlan::findOrFail($data['license_plan_id']) : null;
        $contract = collect($data)->only(['trade_name', 'plan', 'branch_limit', 'user_limit', 'status', 'notes'])->all();
        if ($plan) {
            $contract = array_merge([
                'license_plan_id' => $plan->id, 'plan' => $plan->name,
                'branch_limit' => $plan->branch_limit, 'user_limit' => $plan->user_limit,
            ], array_filter($contract, fn ($value) => $value !== null));
        }
        $company = $provisioner->commercialOnboard($data['owner'], $contract, $data['modules'] ?? $plan?->modules ?? [], $request->user());

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
            'licensePlans' => LicensePlan::query()->orderBy('name')->get(),
        ]);
    }

    public function show(Company $company, CompanyLicenseService $licenses): View
    {
        $company->load([
            'branches' => fn ($query) => $query->orderBy('name'),
            'users' => fn ($query) => $query->orderBy('name')->withPivot('role_id'),
            'roles:id,company_id,name,is_active',
            'modules',
            'owner',
        ]);

        $company->setRelation('license', $licenses->refresh($licenses->ensure($company)));
        $company->license->load(['events.actor']);

        return view('platform.show', [
            'company' => $company,
            'moduleCatalog' => ModuleRegistry::MODULES,
            'licensePlans' => LicensePlan::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function updateLicense(Request $request, Company $company, CompanyLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(CompanyLicense::STATUSES)], 'plan' => ['required', 'string', 'max:80'],
            'starts_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date'],
            'next_renewal_at' => ['nullable', 'date'], 'grace_until' => ['nullable', 'date', 'after_or_equal:expires_at'],
            'user_limit' => ['nullable', 'integer', 'min:1'], 'branch_limit' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'license_plan_id' => ['nullable', Rule::exists('license_plans', 'id')->where('is_active', true)],
            'apply_plan' => ['nullable', 'boolean'],
        ]);
        $status = $data['status'];
        $planId = $data['license_plan_id'] ?? null;
        unset($data['status'], $data['apply_plan'], $data['license_plan_id']);
        if ($planId && $request->boolean('apply_plan')) {
            $licenses->applyPlan($company, LicensePlan::findOrFail($planId), $request->user(), [...$data, 'status' => $status]);
        } else {
            $licenses->updateContract($company, $request->user(), $status, $data['notes'] ?? null, [...$data, 'license_plan_id' => $planId]);
        }

        return back()->with('success', 'Licencia actualizada y registrada en el historial.');
    }

    public function storePlan(Request $request, CompanyLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('license_plans', 'code')],
            'name' => ['required', 'string', 'max:80'],
            'branch_limit' => ['nullable', 'integer', 'min:1'],
            'user_limit' => ['nullable', 'integer', 'min:1'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $licenses->savePlan(null, $request->user(), $data);

        return back()->with('success', 'Plantilla comercial creada.');
    }

    public function updateModules(Request $request, Company $company, CompanyLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate(['modules' => ['nullable', 'array'], 'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))]]);
        $enabled = $data['modules'] ?? [];

        $licenses->updateModules($company, $request->user(), $enabled);

        return back()->with('success', 'Módulos contratados actualizados.');
    }
}
