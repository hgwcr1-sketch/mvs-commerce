<?php

namespace App\Services\Cobros;

use App\Models\CobrosMaestroIdentityBinding;
use App\Models\CobrosMaestroCompanyBinding;
use App\Models\Company;
use App\Models\User;

final class MaestroIdentityBindingResolver
{
    public function for(User $user, Company $company): ?CobrosMaestroIdentityBinding
    {
        $application = (string) config('maestro.identity.application_id', '');
        $installation = (string) config('maestro.identity.installation_id', '');
        if (!$user->exists || !$company->exists || $application === '' || $installation === '') {
            return null;
        }

        $binding = CobrosMaestroIdentityBinding::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', $company->getKey())
            ->where('maestro_application_id', $application)
            ->where('maestro_installation_id', $installation)
            ->where('source_system', 'cobros')
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->first();

        if ($binding === null || $binding->maestro_company_id === null) return null;
        $mapped = CobrosMaestroCompanyBinding::query()
            ->where('company_id', $company->getKey())
            ->where('maestro_company_id', $binding->maestro_company_id)
            ->where('maestro_application_id', $application)
            ->where('maestro_installation_id', $installation)
            ->where('source_system', 'cobros')
            ->where('status', 'approved')->whereNotNull('approved_at')->whereNull('revoked_at')->exists();
        if (!$mapped) return null;

        return $binding;
    }
}
