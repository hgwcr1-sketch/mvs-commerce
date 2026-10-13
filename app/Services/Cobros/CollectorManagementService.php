<?php

namespace App\Services\Cobros;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectorManagementService
{
    public function __construct(
        private CompanyLicenseService $licenseService,
        private CollectorPasswordService $passwordService,
        private CollectorLicenseService $collectorLicenses,
    ) {}

    public function getContract(Company $company): ?array
    {
        return $this->licenseService->getCobrosContract($company);
    }

    public function isCupoMode(Company $company): bool
    {
        return ($this->getContract($company)['license_mode'] ?? null) === 'company_cupo';
    }

    public function isOwnerReadOnly(Company $company): bool
    {
        return ! $this->isCupoMode($company);
    }

    public function getCollectorCount(Company $company): int
    {
        // Solo cobradores reales: el dueño/administrador NO consume cupo
        // ("3 cobradores + 1 dueño/admin = 4 licencias potenciales").
        // CompanyProvisioner sincroniza todos los permisos activos al rol
        // Administrador (incluye cobros.cobrar), por lo que sin excluir al
        // portador de cobros.portal_dueno el propio dueño se contaría como
        // cobrador y bloquearía el alta de nuevos cobradores.
        $roleIds = Role::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('permissions', fn ($q) => $q->where('permissions.name', 'cobros.cobrar'))
            ->whereDoesntHave('permissions', fn ($q) => $q->where('permissions.name', 'cobros.portal_dueno'))
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return 0;
        }

        $userIds = DB::table('company_user')
            ->where('company_id', $company->id)
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return 0;
        }

        return User::whereIn('id', $userIds)->where('is_active', true)->count();
    }

    /**
     * Cupos efectivamente consumidos en modalidad company_cupo: cobradores
     * reales + el dueño/admin SOLO cuando su licencia está configurada como
     * cobrada (`admin_license_charged`); si es gratis no consume cupo.
     */
    public function seatUsage(Company $company): int
    {
        $usage = $this->getCollectorCount($company);

        $contract = $this->getContract($company);
        if (($contract['license_mode'] ?? null) !== 'company_cupo'
            || ! ($contract['admin_license_charged'] ?? false)) {
            return $usage;
        }

        return $usage + ($this->hasActiveOwner($company) ? 1 : 0);
    }

    /** Existe al menos un dueño/administrador activo (portador de portal_dueno). */
    private function hasActiveOwner(Company $company): bool
    {
        $roleIds = Role::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('permissions', fn ($q) => $q->where('permissions.name', 'cobros.portal_dueno'))
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return false;
        }

        $userIds = DB::table('company_user')
            ->where('company_id', $company->id)
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return false;
        }

        return User::whereIn('id', $userIds)->where('is_active', true)->exists();
    }

    public function canCreateCollector(Company $company): bool
    {
        $contract = $this->getContract($company);
        if (! $contract || $contract['license_mode'] !== 'company_cupo') {
            return false;
        }
        $limit = $contract['collector_limit'];
        if ($limit === null) {
            return true;
        }

        return $this->seatUsage($company) < $limit;
    }

    public function getRemainingSlots(Company $company): ?int
    {
        $contract = $this->getContract($company);
        if (! $contract || $contract['license_mode'] !== 'company_cupo') {
            return null;
        }
        $limit = $contract['collector_limit'];
        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->seatUsage($company));
    }

    /**
     * Licencias potenciales de la empresa = cobradores + administrador/dueño.
     *
     * Regla documentada en docs/PROGRESO_COBROS.md (1E-A): "3 cobradores +
     * 1 dueño/admin = 4 licencias potenciales". `admin_license_charged` decide
     * si la licencia del administrador se cobra, pero no altera el recuento
     * potencial. Devuelve null cuando la empresa no tiene contrato CobrOS.
     */
    public function getPotentialLicenses(Company $company): ?int
    {
        if (! $this->getContract($company)) {
            return null;
        }

        return $this->getCollectorCount($company) + 1;
    }

    public function listCollectors(Company $company): array
    {
        $roleIds = Role::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('permissions', fn ($q) => $q->where('permissions.name', 'cobros.cobrar'))
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $userIds = DB::table('company_user')
            ->where('company_id', $company->id)
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return [];
        }

        $users = User::whereIn('id', $userIds)->with('roles')->get();

        return $users->map(function (User $user) use ($company) {
            $companyRole = $user->roles->first(fn ($r) => $r->pivot->company_id === $company->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $companyRole?->name,
                'branches' => $user->branches->where('company_id', $company->id)->pluck('name', 'id'),
                'created_at' => $user->created_at,
            ];
        })->toArray();
    }

    /**
     * Create a collector and return ['user' => User, 'plain_password' => string].
     *
     * The plain password is only available at creation time and must be
     * delivered to the admin (WhatsApp / clipboard) immediately.
     */
    public function createCollector(Company $company, array $data, User $actor, bool $skipLimit = false): array
    {
        if (! $skipLimit && ! $this->canCreateCollector($company)) {
            $contract = $this->getContract($company);
            throw ValidationException::withMessages([
                'collector' => "Límite de cobradores alcanzado ({$contract['collector_limit']}).",
            ]);
        }

        $plainPassword = $data['password'] ?? $this->passwordService->generate();
        $existingUser = User::where('email', $data['email'])->first();

        if ($existingUser) {
            $this->validateUserNotAlreadyCollector($existingUser, $company);

            DB::transaction(function () use ($company, $data, $existingUser, $plainPassword, $skipLimit) {
                $this->lockContractAndAssertSlot($company, $skipLimit);
                $role = $this->ensureCollectorRole($company);
                $existingUser->companies()->syncWithoutDetaching([
                    $company->id => ['role_id' => $role->id],
                ]);

                $existingUser->update([
                    'password' => $plainPassword,
                    'must_change_password' => true,
                    'is_active' => true,
                ]);

                if (! empty($data['branch_ids'])) {
                    $branchIds = Branch::where('company_id', $company->id)
                        ->whereIn('id', $data['branch_ids'])
                        ->pluck('id');
                    $existingUser->branches()->syncWithoutDetaching($branchIds);
                }
            });

            $created = $existingUser->fresh();
            // Modalidad individual: el alta otorga la licencia propia si falta.
            $this->collectorLicenses->grantIfMissing($created, $company);

            return ['user' => $created, 'plain_password' => $plainPassword];
        }

        return DB::transaction(function () use ($company, $data, $plainPassword, $skipLimit) {
            $this->lockContractAndAssertSlot($company, $skipLimit);
            $role = $this->ensureCollectorRole($company);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $plainPassword,
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $user->companies()->attach($company->id, ['role_id' => $role->id]);

            if (! empty($data['branch_ids'])) {
                $branchIds = Branch::where('company_id', $company->id)
                    ->whereIn('id', $data['branch_ids'])
                    ->pluck('id');
                $user->branches()->syncWithoutDetaching($branchIds);
            }

            // Modalidad individual: licencia propia desde el primer ingreso.
            $this->collectorLicenses->grantIfMissing($user, $company);

            return ['user' => $user, 'plain_password' => $plainPassword];
        });
    }

    public function toggleCollector(User $user, Company $company, bool $active): void
    {
        $this->validateUserIsCollector($user, $company);

        // Modalidad individual: reactivar exige licencia propia vigente
        // (vencida o suspendida se atiende con renovar/reactivar licencia).
        if ($active && ! $user->is_active && $this->collectorLicenses->isIndividual($company)) {
            $this->collectorLicenses->assertCanOperate($user, $company);
        }

        // Reactivar un cobrador inactivo vuelve a consumir un cupo: en modalidad
        // company_cupo el cupo se revalida DENTRO de la transacción y después
        // del lock de la fila de contrato (los inactivos no cuentan en
        // getCollectorCount, por eso el chequeo es solo al activar).
        $requiresSlot = $active && ! $user->is_active && $this->isCupoMode($company);

        if (! $requiresSlot) {
            $user->update(['is_active' => $active]);

            return;
        }

        DB::transaction(function () use ($user, $company, $active) {
            $this->lockContractAndAssertSlot($company);
            $user->update(['is_active' => $active]);
        });
    }

    /**
     * Bloquea la fila de contrato CobrOS (`company_modules`) y revalida el
     * cupo DESPUÉS de adquirir el lock, dentro de la misma transacción que
     * escribe: dos altas/reactivaciones simultáneas no pueden superar ambas
     * la comprobación previa y saltarse `collector_limit`.
     *
     * En PostgreSQL el lock serializa a los escritores concurrentes; la
     * relectura posterior ve el cupo ya consumido y aborta sin crear ni
     * reactivar usuario. En SQLite el lock no serializa (grammar sin
     * `FOR UPDATE`), por lo que la protección real es de staging/producción.
     */
    private function lockContractAndAssertSlot(Company $company, bool $skipLimit = false): void
    {
        $module = CompanyModule::query()
            ->where('company_id', $company->id)
            ->where('module_key', 'cobros')
            ->lockForUpdate()
            ->first();

        if ($skipLimit || $module === null || $module->license_mode !== 'company_cupo') {
            return;
        }

        $limit = $module->collector_limit;

        if ($limit !== null && $this->seatUsage($company) >= $limit) {
            throw ValidationException::withMessages([
                'collector' => "Límite de cobradores alcanzado ({$limit}).",
            ]);
        }
    }

    public function assignBranches(User $user, Company $company, array $branchIds): void
    {
        $this->validateUserIsCollector($user, $company);
        $validBranchIds = Branch::where('company_id', $company->id)
            ->whereIn('id', $branchIds)
            ->pluck('id');
        $user->branches()->syncWithoutDetaching($validBranchIds);
    }

    public function replaceBranches(User $user, Company $company, array $branchIds): void
    {
        $this->validateUserIsCollector($user, $company);
        $validBranchIds = Branch::where('company_id', $company->id)
            ->whereIn('id', $branchIds)
            ->pluck('id');
        $user->branches()->sync($validBranchIds);
    }

    public function removeBranch(User $user, Company $company, int $branchId): void
    {
        $this->validateUserIsCollector($user, $company);
        $user->branches()->detach($branchId);
    }

    /**
     * Generate a new temporary password for an existing collector.
     * Returns the plain password for immediate delivery.
     */
    public function regenerateAccess(User $user, Company $company): string
    {
        $this->validateUserIsCollector($user, $company);

        $plainPassword = $this->passwordService->generate();
        $user->update([
            'password' => $plainPassword,
            'must_change_password' => true,
        ]);

        return $plainPassword;
    }

    private function ensureCollectorRole(Company $company): Role
    {
        $role = Role::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Cobrador CobrOS'],
            ['is_active' => true]
        );

        $collectorPermissions = [
            ['name' => 'cobros.acceder', 'label' => 'Acceder a CobrOS', 'module' => 'CobrOS'],
            ['name' => 'cobros.cobrar', 'label' => 'Registrar cobro en CobrOS', 'module' => 'CobrOS'],
        ];

        $permissionIds = collect($collectorPermissions)->map(fn (array $perm) => Permission::firstOrCreate(
            ['name' => $perm['name']],
            ['label' => $perm['label'], 'module' => $perm['module'], 'is_active' => true]
        )->id
        );

        $role->permissions()->syncWithoutDetaching($permissionIds);

        return $role;
    }

    private function validateUserNotAlreadyCollector(User $user, Company $company): void
    {
        $hasCollectorRole = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $hasCollectorRole) {
            return;
        }

        $roleIds = Role::where('company_id', $company->id)
            ->whereHas('permissions', fn ($q) => $q->where('permissions.name', 'cobros.cobrar'))
            ->pluck('id');

        $isCollector = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereIn('role_id', $roleIds)
            ->exists();

        if ($isCollector) {
            throw ValidationException::withMessages([
                'email' => 'Este usuario ya es cobrador en esta empresa.',
            ]);
        }
    }

    private function validateUserIsCollector(User $user, Company $company): void
    {
        $roleIds = Role::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('permissions', fn ($q) => $q->where('permissions.name', 'cobros.cobrar'))
            ->pluck('id');

        $isCollector = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereIn('role_id', $roleIds)
            ->exists();

        if (! $isCollector) {
            abort(404);
        }
    }
}
