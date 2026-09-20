<?php

namespace App\Services\Cobros;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CollectorAccessService
{
    public function branchesForCollecting(User $user, Company $company): Collection
    {
        if (! $this->canCollect($user, $company)) {
            return new Collection;
        }

        return $this->assignedBranches($user, $company)->orderBy('branches.name')->get();
    }

    public function canCollectIn(User $user, Company $company, int $branchId): bool
    {
        return $this->canCollect($user, $company)
            && $this->assignedBranches($user, $company)->where('branches.id', $branchId)->exists();
    }

    private function canCollect(User $user, Company $company): bool
    {
        // hasPermission valida company_user, rol de esa empresa activo y módulo.
        return $user->isActive() && $user->hasPermission('cobros.cobrar', $company);
    }

    private function assignedBranches(User $user, Company $company): BelongsToMany
    {
        // El pivote por sí solo y active_branch_id nunca conceden autorización.
        return $user->branches()->where('branches.company_id', $company->id)
            ->where('branches.is_active', true);
    }
}
