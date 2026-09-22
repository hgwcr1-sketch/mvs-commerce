<?php

namespace App\Services\Cobros;

use App\Models\Branch;
use App\Models\Company;
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
        $roleIds = Role::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('permissions', fn ($q) => $q->where('permissions.name', 'cobros.cobrar'))
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

        return $this->getCollectorCount($company) < $limit;
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

        return max(0, $limit - $this->getCollectorCount($company));
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

            DB::transaction(function () use ($company, $data, $existingUser, $plainPassword) {
                $role = $this->ensureCollectorRole($company);
                $existingUser->companies()->syncWithoutDetaching([
                    $company->id => ['role_id' => $role->id],
                ]);

                $existingUser->update([
                    'password' => $plainPassword,
                    'must_change_password' => true,
                ]);

                if (! empty($data['branch_ids'])) {
                    $branchIds = Branch::where('company_id', $company->id)
                        ->whereIn('id', $data['branch_ids'])
                        ->pluck('id');
                    $existingUser->branches()->syncWithoutDetaching($branchIds);
                }
            });

            return ['user' => $existingUser->fresh(), 'plain_password' => $plainPassword];
        }

        return DB::transaction(function () use ($company, $data, $plainPassword) {
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

            return ['user' => $user, 'plain_password' => $plainPassword];
        });
    }

    public function toggleCollector(User $user, Company $company, bool $active): void
    {
        $this->validateUserIsCollector($user, $company);
        $user->update(['is_active' => $active]);
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

        $collectorPermissions = ['cobros.acceder', 'cobros.cobrar'];
        $permissionIds = Permission::whereIn('name', $collectorPermissions)
            ->where('is_active', true)
            ->pluck('id');

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
