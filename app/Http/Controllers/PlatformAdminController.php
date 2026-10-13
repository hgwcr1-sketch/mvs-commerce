<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyBackupRecord;
use App\Models\CompanyBackupSetting;
use App\Models\CompanyLicense;
use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\LicensePlan;
use App\Models\User;
use App\Services\Backups\CompanyBackupService;
use App\Services\Billing\CommercialPricingService;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CollectorManagementService;
use App\Services\Cobros\CobrosMembershipLifecycleService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
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

        // 18E-1B: elecciones explícitas de proveedor de pago y presencia de
        // credenciales por modo (solo "configurado/no configurado"; jamás
        // valores) para la sección del Panel Maestro.
        $billingProviderKeys = array_keys((array) config('billing.providers', []));
        $billingCredentials = [];
        foreach ($billingProviderKeys as $billingKey) {
            $billingCredentials[$billingKey] = [
                'test' => PlatformBillingProviderController::credentialsConfigured($billingKey, 'test'),
                'live' => PlatformBillingProviderController::credentialsConfigured($billingKey, 'live'),
            ];
        }

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
            'billingProviderSettings' => \App\Models\BillingProviderSetting::query()
                ->orderBy('product')->orderBy('country_iso')->get(),
            'billingProviderKeys' => $billingProviderKeys,
            'billingCredentials' => $billingCredentials,
        ]);
    }

    public function show(Company $company, CompanyLicenseService $licenses, CollectorManagementService $collectorService): View
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

        $cobrosContract = $licenses->getCobrosContract($company);
        $collectors = $cobrosContract ? $collectorService->listCollectors($company) : [];
        $collectorCount = $cobrosContract ? $collectorService->getCollectorCount($company) : 0;

        // Licencias individuales por cobrador (solo modalidad individual).
        $licenseGate = app(\App\Services\Cobros\CollectorLicenseService::class);
        $collectorLicenses = [];
        foreach ($licenseGate->licensesByUser($company) as $userId => $license) {
            $collectorLicenses[$userId] = [
                'license' => $license,
                'state' => $licenseGate->stateOf($license, $company),
            ];
        }

        // 18B-1: solicitudes SINPE pendientes de ESTA empresa (aislamiento
        // estricto por company_id vía scope sinpePending; sin consultas globales).
        $sinpePending = CobrosPaymentConfirmation::query()
            ->sinpePending($company->id)
            ->with('contract.payer:id,name,email')
            ->orderByDesc('created_at')
            ->get();

        // 18C-1: estado de membresía CobrOS del contrato abierto más reciente
        // de ESTA empresa. evaluate() considera vigencia contractual Y
        // vigencias de licencias individuales (no solo la fecha del contrato).
        $membershipContract = CobrosBillingContract::query()
            ->where('company_id', $company->id)
            ->whereIn('status', CobrosBillingContract::OPEN_STATUSES)
            ->orderByDesc('id')
            ->first();
        $membershipState = $membershipContract !== null
            ? app(CobrosMembershipLifecycleService::class)->evaluate($membershipContract)
            : null;

        // 18D-1: avisos de renovación y vencimiento (solo lectura; no cambia
        // pagos ni licencias). Distingue licencia inicial pendiente (sin
        // primer pago confirmado ni licencia activada) de licencia vencida
        // (ya operó y dejó de estar vigente).
        $membershipLicense = ($membershipContract !== null && $membershipContract->payer !== null)
            ? $licenseGate->licenseFor($membershipContract->payer, $company)
            : null;
        $membershipNotices = $this->membershipNotices($membershipContract, $membershipState, $membershipLicense);

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
            'cobrosContract' => $cobrosContract,
            'collectors' => $collectors,
            'collectorCount' => $collectorCount,
            'potentialLicenses' => $collectorService->getPotentialLicenses($company),
            'seatUsage' => $collectorService->seatUsage($company),
            'collectorLicenses' => $collectorLicenses,
            'sinpePending' => $sinpePending,
            'membershipContract' => $membershipContract,
            'membershipState' => $membershipState,
            'membershipNotices' => $membershipNotices,
        ]);
    }

    /**
     * 18D-1 — Avisos de renovación y vencimiento de la membresía CobrOS.
     *
     * Solo lectura sobre el estado ya evaluado (18C-1) y la licencia
     * individual existente. Distingue explícitamente:
     *  - «Licencia inicial pendiente»: nunca hubo primer pago confirmado ni
     *    licencia activada (no es una licencia vencida);
     *  - «Renovación pendiente»: ya operó y el período actual no está
     *    confirmado;
     *  - «Aviso de renovación»: próxima a vencer (ventana de 7 días);
     *  - «Aviso de vencimiento»: licencia/contrato que dejó de vigencia
     *    después de haber estado activo.
     *
     * No modifica pagos, licencias ni el contrato.
     *
     * @return array<int, array{tone: string, title: string, detail: string}>
     */
    private function membershipNotices(
        ?CobrosBillingContract $contract,
        ?array $state,
        ?CobrosCollectorLicense $license,
    ): array {
        if ($contract === null || $state === null) {
            return [];
        }

        // Licencia ya activada: existe registro de licencia individual o al
        // menos un pago confirmado legítimo para este contrato.
        $activated = $license !== null || CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_CONFIRMED)
            ->exists();

        $expiresOn = $state['expires_on'] ?? null;

        if ($state['status'] === CobrosMembershipLifecycleService::STATUS_VENCIDA) {
            if (! $activated) {
                return [[
                    'tone' => 'warning',
                    'title' => 'Licencia inicial pendiente',
                    'detail' => 'El contrato venció sin un primer pago confirmado: nunca se activó una licencia. Esto no es una licencia vencida; renovar el contrato y confirmar el pago para activarla.',
                ]];
            }

            return [[
                'tone' => 'danger',
                'title' => 'Aviso de vencimiento',
                'detail' => 'La membresía CobrOS venció el '.$expiresOn.' y la licencia dejó de operar. Renovar con un pago confirmado para reactivarla.',
            ]];
        }

        if ($state['status'] === CobrosMembershipLifecycleService::STATUS_PENDIENTE_DE_PAGO) {
            if (! $activated) {
                return [[
                    'tone' => 'warning',
                    'title' => 'Licencia inicial pendiente',
                    'detail' => 'Aún no hay un primer pago confirmado para este contrato: la licencia se activa al aprobarlo. No es una licencia vencida.',
                ]];
            }

            return [[
                'tone' => 'warning',
                'title' => 'Renovación pendiente de pago',
                'detail' => 'El período vigente no está confirmado. Prepare la renovación para mantener la licencia en operación.',
            ]];
        }

        if ($state['status'] === CobrosMembershipLifecycleService::STATUS_PROXIMA_A_VENCER && $expiresOn !== null) {
            return [[
                'tone' => 'warning',
                'title' => 'Aviso de renovación',
                'detail' => 'La membresía vence el '.$expiresOn.'. Prepare la renovación antes de la fecha para no interrumpir la operación.',
            ]];
        }

        return [];
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
    public function updateCobrosContract(Request $request, Company $company, CompanyLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate([
            'license_mode' => ['required', 'string', Rule::in(['individual', 'company_cupo'])],
            'collector_limit' => ['nullable', 'integer', 'min:1', 'max:999'],
            'admin_license_charged' => ['required', 'boolean'],
        ]);

        $licenses->updateCobrosContract($company, $request->user(), $data);

        return back()->with('success', 'Contrato CobrOS actualizado.');
    }

    public function storeCollector(Request $request, Company $company, CollectorManagementService $collectorService): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        $contract = $collectorService->getContract($company);
        abort_unless($contract && $contract['license_mode'] === 'individual', 422, 'Solo disponible en modalidad de licencias individuales.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $collectorService->createCollector($company, $data, $request->user(), true);

        return back()
            ->with('success', "Cobrador {$data['name']} registrado exitosamente.")
            ->with('collector_plain_password', $result['plain_password'])
            ->with('collector_email', $result['user']->email)
            ->with('collector_name', $result['user']->name)
            ->with('collector_phone', $result['user']->phone);
    }

    public function regenerateCollectorAccess(Request $request, Company $company, User $user, CollectorManagementService $collectorService): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        $contract = $collectorService->getContract($company);
        abort_unless($contract && $contract['license_mode'] === 'individual', 422, 'Solo disponible en modalidad de licencias individuales.');

        $plainPassword = $collectorService->regenerateAccess($user, $company);

        return back()
            ->with('success', "Nuevo acceso generado para {$user->name}.")
            ->with('collector_plain_password', $plainPassword)
            ->with('collector_email', $user->email)
            ->with('collector_name', $user->name)
            ->with('collector_phone', $user->phone);
    }

    public function toggleCollector(Request $request, Company $company, User $user, CollectorManagementService $collectorService): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        $contract = $collectorService->getContract($company);
        abort_unless($contract && $contract['license_mode'] === 'individual', 422, 'Solo disponible en modalidad de licencias individuales.');

        $collectorService->toggleCollector($user, $company, ! $user->is_active);

        $status = $user->fresh()->is_active ? 'activado' : 'desactivado';

        return back()->with('success', "Cobrador {$user->name} {$status}.");
    }

    public function updateCollectorBranches(Request $request, Company $company, User $user, CollectorManagementService $collectorService): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        $contract = $collectorService->getContract($company);
        abort_unless($contract && $contract['license_mode'] === 'individual', 422, 'Solo disponible en modalidad de licencias individuales.');

        $data = $request->validate([
            'branch_ids' => ['required', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $collectorService->replaceBranches($user, $company, $data['branch_ids']);

        return back()->with('success', "Sucursales de {$user->name} actualizadas.");
    }

    /** Renueva la licencia individual de un cobrador (por defecto +1 año). */
    public function renewCollectorLicense(Request $request, Company $company, User $user, CollectorLicenseService $licenses): RedirectResponse
    {
        $this->assertPlatformAdminAndIndividual($request, $company, $licenses);

        $data = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $timezone = $company->timezone ?: config('app.timezone');
        $expiresAt = isset($data['expires_at'])
            ? CarbonImmutable::parse($data['expires_at'], $timezone)->endOfDay()
            : CarbonImmutable::now($timezone)->addDays(CollectorLicenseService::DEFAULT_TERM_DAYS)->endOfDay();

        $licenses->renew($user, $company, $expiresAt);

        return back()->with('success', "Licencia de {$user->name} renovada hasta {$expiresAt->format('d/m/Y')}.");
    }

    /** Suspende la licencia individual: el cobrador deja de operar CobrOS. */
    public function suspendCollectorLicense(Request $request, Company $company, User $user, CollectorLicenseService $licenses): RedirectResponse
    {
        $this->assertPlatformAdminAndIndividual($request, $company, $licenses);

        $licenses->suspend($user, $company, $request->user());

        return back()->with('success', "Licencia de {$user->name} suspendida.");
    }

    /** Reactiva una licencia individual suspendida (la vigencia debe seguir abierta). */
    public function resumeCollectorLicense(Request $request, Company $company, User $user, CollectorLicenseService $licenses): RedirectResponse
    {
        $this->assertPlatformAdminAndIndividual($request, $company, $licenses);

        $licenses->resume($user, $company);

        return back()->with('success', "Licencia de {$user->name} reactivada.");
    }

    /**
     * 18B-2 — Aprueba una solicitud SINPE tras verificación EXTERNA del operador.
     *
     * En servidor se exige la confirmación explícita (checkbox): una captura o
     * un mensaje de WhatsApp nunca es verificación suficiente. Reutiliza
     * approveSinpe (auditoría actor+timestamp y activación idempotente de la
     * licencia; valida contrato/pagador/importe/moneda/período y pendiente).
     */
    public function approveSinpePayment(Request $request, Company $company, CobrosPaymentConfirmation $confirmation): RedirectResponse
    {
        abort_unless((int) $confirmation->company_id === (int) $company->id, 404);

        $request->validate(
            ['verified_transfer' => ['accepted']],
            [
                'verified_transfer.accepted' => 'Debe confirmar explícitamente que verificó la transferencia en su banco. Una captura o mensaje de WhatsApp no es verificación suficiente.',
            ],
            ['verified_transfer' => 'la verificación de la transferencia']
        );

        try {
            app(CobrosPaymentConfirmationService::class)->approveSinpe($confirmation, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Solicitud SINPE {$confirmation->transaction_id} aprobada. Licencia activada sin duplicados.");
    }

    /**
     * 18B-2 — Rechaza una solicitud SINPE con motivo obligatorio (auditable).
     * Conserva el registro; nunca activa licencias.
     */
    public function rejectSinpePayment(Request $request, Company $company, CobrosPaymentConfirmation $confirmation): RedirectResponse
    {
        abort_unless((int) $confirmation->company_id === (int) $company->id, 404);

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:500'],
        ], ['notes.required' => 'El motivo del rechazo es obligatorio.']);

        try {
            app(CobrosPaymentConfirmationService::class)->rejectSinpe($confirmation, $request->user(), $data['notes']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Solicitud SINPE {$confirmation->transaction_id} rechazada. Registro conservado, sin activación.");
    }

    private function assertPlatformAdminAndIndividual(Request $request, Company $company, CollectorLicenseService $licenses): void
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        abort_unless(($licenses->contract($company)['license_mode'] ?? null) === 'individual', 422, 'Solo disponible en modalidad de licencias individuales.');
    }
}
