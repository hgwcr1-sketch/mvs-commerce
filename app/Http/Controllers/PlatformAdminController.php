<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\CobrosBillingContract;
use App\Models\CobrosCollectorLicense;
use App\Models\CobrosPaymentConfirmation;
use App\Models\LicensePlan;
use App\Models\User;
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
