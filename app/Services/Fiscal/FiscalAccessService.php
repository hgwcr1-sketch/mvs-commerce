<?php

namespace App\Services\Fiscal;

use App\Models\Company;
use App\Models\CompanyLicense;
use Illuminate\Http\Request;

/**
 * Autoridad única de acceso al portal fiscal tenant.
 *
 * El Panel Maestro conserva su administración por separado (rutas platform.*).
 * El bloqueo de emisión (FiscalConsumptionService) sigue existiendo como
 * defensa adicional independiente de este gate.
 */
class FiscalAccessService
{
    public function enabledForRequest(?Request $request): bool
    {
        if ($request?->user()?->isPlatformAdmin()) {
            return true;
        }

        $companyId = session('active_company_id');

        if (! $companyId) {
            return false;
        }

        return $this->enabledForCompany(Company::find($companyId));
    }

    public function enabledForCompany(?Company $company): bool
    {
        if (! $company) {
            return false;
        }

        $fiscalEnabled = CompanyLicense::query()
            ->where('company_id', $company->id)
            ->value('fiscal_enabled');

        return (bool) $fiscalEnabled;
    }
}
