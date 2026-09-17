<?php

namespace App\Services\Notifications;

use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class AlertRecipientResolver
{
    public function __construct(
        private NotificationPreferenceService $preferenceService,
    ) {}

    /**
     * Resuelve destinatarios: empresa → sucursal → permiso origen +
     * permiso de notificación → preferencias → severidad.
     * Una preferencia nunca concede acceso.
     *
     * @return Collection<int, User>
     */
    public function resolve(Alert $alert, Company $company): Collection
    {
        if ((int) $alert->company_id !== (int) $company->id) {
            return new Collection;
        }

        if (AlertTypeRegistry::forType($alert->type) === null) {
            return new Collection;
        }

        return $this->eligibleUsers($company, $alert->branch_id)
            ->filter(function (User $user) use ($alert, $company) {
                if (! $user->is_active) {
                    return false;
                }

                if (! AlertTypeRegistry::isAuthorizedFor($user, $company, $alert->type)) {
                    return false;
                }

                $role = $user->roleInCompany($company);

                return $this->preferenceService->allowsAlert($user, $company, $role, $alert->type, $alert->severity);
            })
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleUsers(Company $company, ?int $branchId): Collection
    {
        return User::query()
            ->where('users.is_active', true)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->when($branchId !== null, fn ($query) => $query->whereHas(
                'branches',
                fn ($branches) => $branches->where('branches.id', $branchId)->where('branches.company_id', $company->id)
            ))
            ->get();
    }
}
