<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyBackupRecord;
use App\Models\CompanyBackupSetting;
use App\Models\CompanyLicense;
use App\Models\LicensePlan;
use App\Models\User;
use App\Services\Backups\CompanyBackupService;
use App\Services\Billing\CommercialPricingService;
use App\Services\CompanyLicenseService;
use App\Services\CompanyProvisioner;
use App\Services\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformAdminController extends Controller
{
    public function createCompany(CommercialPricingService $pricing): View
    {
        return view('platform.onboarding', [
            'moduleCatalog' => ModuleRegistry::MODULES,
            'licensePlans' => LicensePlan::query()->where('is_active', true)->orderBy('name')->get(),
            'fiscalPlans' => $pricing->fiscalCatalog(),
            'planCatalog' => $this->planCatalog(),
        ]);
    }

    public function storeCompany(Request $request, CompanyProvisioner $provisioner, CommercialPricingService $pricing): RedirectResponse
    {
        $data = $request->validate([
            'trade_name' => ['required', 'string', 'max:150'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner.phone' => ['nullable', 'string', 'max:50'],
            'license_plan_id' => ['nullable', Rule::exists('license_plans', 'id')->where('is_active', true)],
            'plan' => ['required_without:license_plan_id', 'nullable', 'string', 'max:80'],
            'branch_limit' => ['nullable', 'integer', 'min:1'], 'user_limit' => ['nullable', 'integer', 'min:1'],
            'branches' => ['nullable', 'integer', 'min:0', 'max:500'],
            'users' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'commerce_price_usd' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'fiscal_plan' => ['nullable', Rule::in(array_keys(CommercialPricingService::FISCAL_PLANS))],
            'fiscal_price_crc' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'fiscal_quota' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'status' => ['required', Rule::in(CompanyLicense::STATUSES)], 'notes' => ['nullable', 'string', 'max:2000'],
            'modules' => ['nullable', 'array'], 'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))],
        ]);

        $plan = isset($data['license_plan_id']) ? LicensePlan::findOrFail($data['license_plan_id']) : null;
        $branches = (int) ($data['branches'] ?? $data['branch_limit'] ?? $plan?->branch_limit ?? 1);
        $users = (int) ($data['users'] ?? $data['user_limit'] ?? $plan?->user_limit ?? 1);

        $quote = $pricing->quote(
            $plan,
            $branches,
            $users,
            $data['fiscal_plan'] ?? 'none',
            isset($data['commerce_price_usd']) ? (float) $data['commerce_price_usd'] : null,
            isset($data['fiscal_price_crc']) ? (float) $data['fiscal_price_crc'] : null,
            isset($data['fiscal_quota']) ? (int) $data['fiscal_quota'] : null,
        );

        $contract = collect($data)->only(['trade_name', 'plan', 'branch_limit', 'user_limit', 'status', 'notes'])->all();
        $contract['branch_limit'] = $branches;
        $contract['user_limit'] = $users;

        if ($plan) {
            $contract = array_merge([
                'license_plan_id' => $plan->id, 'plan' => $plan->name,
            ], array_filter($contract, fn ($value) => $value !== null));
        }

        $contract['contract_snapshot'] = $pricing->snapshot($quote, $plan);
        $contract += $pricing->licenseFiscalAttributes($quote['fiscal']);

        $company = $provisioner->commercialOnboard($data['owner'], $contract, $data['modules'] ?? $plan?->modules ?? [], $request->user());

        return redirect()->route('platform.companies.show', $company)->with('success', 'Tenant y contrato creados. El propietario debe completar su activación y onboarding.');
    }

    /**
     * Datos de precio de cada plantilla para el recálculo en vivo (el
     * navegador usa exactamente los mismos números que el servidor).
     *
     * @return array<int, array<string, mixed>>
     */
    private function planCatalog(): array
    {
        return LicensePlan::query()->where('is_active', true)->orderBy('name')->get()->map(fn (LicensePlan $plan): array => [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'base' => $plan->base_price_usd === null ? null : (float) $plan->base_price_usd,
            'extraBranch' => (float) ($plan->extra_branch_price_usd ?? 0),
            'extraUser' => (float) ($plan->extra_user_price_usd ?? 0),
            'includedBranches' => (int) ($plan->branch_limit ?? 0),
            'includedUsers' => (int) ($plan->user_limit ?? 0),
        ])->all();
    }

    public function index(Request $request, CommercialPricingService $pricing): View
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
            'contractSummaries' => $companies->getCollection()
                ->mapWithKeys(fn (Company $company): array => [$company->id => $pricing->summaryFor($company)]),
            'fiscalPlans' => $pricing->fiscalCatalog(),
            'pendingPriceLabel' => $pricing->label(),
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
        $fiscalUsage = app(\App\Services\Fiscal\FiscalConsumptionService::class)->monthlyBreakdown($company->id);

        $fiscalConfig = \App\Models\CompanyFiscalConfig::query()->where('company_id', $company->id)->first();
        $fiscalConnectionLabel = 'Sin configurar';
        if ($fiscalConfig !== null) {
            if ($fiscalConfig->last_verified_at !== null && ! $fiscalConfig->hasPending()) {
                $fiscalConnectionLabel = 'Verificada';
            } elseif ($fiscalConfig->hasCredentials() || $fiscalConfig->hasPending()) {
                $fiscalConnectionLabel = 'Pendiente';
            }
        }

        $fiscalQuota = $company->license->fiscal_monthly_quota;
        $fiscalUsed = (int) ($fiscalUsage['total'] ?? 0);
        $fiscalRemaining = $fiscalQuota !== null ? max(0, (int) $fiscalQuota - $fiscalUsed) : null;

        $backupService = app(CompanyBackupService::class);

        return view('platform.show', [
            'company' => $company,
            'moduleCatalog' => ModuleRegistry::MODULES,
            'licensePlans' => LicensePlan::query()->where('is_active', true)->orderBy('name')->get(),
            'backupSetting' => $backupService->settings($company),
            'backupRecords' => CompanyBackupRecord::query()->where('company_id', $company->id)->latest()->limit(10)->get(),
            'backupPlans' => CompanyBackupSetting::PLAN_LABELS,
            'backupFrequencies' => CompanyBackupSetting::FREQUENCY_LABELS,
            'backupExternalCopies' => CompanyBackupSetting::EXTERNAL_COPY_LABELS,
            'fiscalUsage' => $fiscalUsage,
            'fiscalConnectionLabel' => $fiscalConnectionLabel,
            'fiscalQuota' => $fiscalQuota,
            'fiscalUsed' => $fiscalUsed,
            'fiscalRemaining' => $fiscalRemaining,
        ]);
    }

    public function updateLicense(Request $request, Company $company, CompanyLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['nullable', Rule::in(['update', 'activate', 'renew', 'suspend', 'reactivate', 'cancel'])],
            'status' => ['required', Rule::in(CompanyLicense::STATUSES)], 'plan' => ['required', 'string', 'max:80'],
            'starts_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date'],
            'next_renewal_at' => ['nullable', 'date'], 'grace_until' => ['nullable', 'date', 'after_or_equal:expires_at'],
            'user_limit' => ['nullable', 'integer', 'min:1'], 'branch_limit' => ['nullable', 'integer', 'min:1'],
            'fiscal_enabled' => ['nullable', 'boolean'],
            'fiscal_monthly_quota' => ['nullable', 'integer', 'min:1'],
            'fiscal_overage_enabled' => ['nullable', 'boolean'],
            'fiscal_overage_unit_price' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,15}(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'license_plan_id' => ['nullable', Rule::exists('license_plans', 'id')->where('is_active', true)],
            'apply_plan' => ['nullable', 'boolean'],
        ]);
        $action = $data['action'] ?? 'update';
        $status = $data['status'];
        $planId = $data['license_plan_id'] ?? null;
        unset($data['action'], $data['status'], $data['apply_plan'], $data['license_plan_id']);
        if ($action === 'renew') {
            $request->validate(['expires_at' => ['required', 'date']]);
            $licenses->renew(
                $company,
                $request->user(),
                CarbonImmutable::parse($data['expires_at']),
                isset($data['next_renewal_at']) ? CarbonImmutable::parse($data['next_renewal_at']) : null,
                isset($data['grace_until']) ? CarbonImmutable::parse($data['grace_until']) : null,
                $data['notes'] ?? null,
            );
        } elseif (in_array($action, ['activate', 'suspend', 'reactivate', 'cancel'], true)) {
            $licenses->changeLifecycle($company, $request->user(), $action, $data['notes'] ?? null, $data);
        } elseif ($planId && $request->boolean('apply_plan')) {
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
            'base_price_usd' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'extra_branch_price_usd' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'extra_user_price_usd' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'fiscal_plan_code' => ['nullable', Rule::in(array_keys(CommercialPricingService::FISCAL_PLANS))],
            'fiscal_monthly_price_crc' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'fiscal_included_quota' => ['nullable', 'integer', 'min:0'],
            'is_custom' => ['nullable', 'boolean'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(ModuleRegistry::MODULES))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['is_custom'] = $request->boolean('is_custom');
        $data['fiscal_plan_code'] = $data['fiscal_plan_code'] ?? 'none';
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

    public function updateBackups(Request $request, Company $company, CompanyBackupService $backups): RedirectResponse
    {
        $data = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'plan' => ['required', Rule::in(CompanyBackupSetting::PLANS)],
            'frequency' => ['required', Rule::in(CompanyBackupSetting::FREQUENCIES)],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'manual_backup_allowed' => ['nullable', 'boolean'],
            'external_copy' => ['required', Rule::in(CompanyBackupSetting::EXTERNAL_COPIES)],
        ]);
        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['manual_backup_allowed'] = $request->boolean('manual_backup_allowed');

        $settings = $backups->updateSettings($company, $data, $request->user());
        $message = $settings->is_enabled
            ? 'Servicio de backups activado para esta empresa.'
            : 'Servicio de backups desactivado para esta empresa.';

        return back()->with('success', $message);
    }

    public function runBackupNow(Request $request, Company $company, CompanyBackupService $backups): RedirectResponse
    {
        $record = $backups->runBackup($company, 'manual', $request->user());

        if ($record->status === 'success') {
            return back()->with('success', 'Backup manual creado y verificado.');
        }

        return back()->with('error', 'Backup manual falló: '.$record->message);
    }

    public function runRestoreTest(Request $request, Company $company, CompanyBackupService $backups): RedirectResponse
    {
        $record = $backups->runRestoreTest($company, $request->user());

        if ($record->status === 'success') {
            return back()->with('success', $record->message);
        }

        return back()->with('error', 'Prueba de restauración falló: '.$record->message);
    }
}
