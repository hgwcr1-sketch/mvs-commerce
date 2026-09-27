<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\FiscalConsumptionService;
use Illuminate\View\View;

class CompanyLicenseController extends Controller
{
    public function show(CompanyLicenseService $licenses): View
    {
        $company = Company::findOrFail(session('active_company_id'));
        $license = $licenses->refresh($licenses->ensure($company));
        $fiscal = app(FiscalConsumptionService::class)->monthlyBreakdown($company->id);
        $fiscalQuotaText = $license->fiscal_monthly_quota !== null
            ? ' de ' . $license->fiscal_monthly_quota . ' (disponibles: ' . max(0, $license->fiscal_monthly_quota - $fiscal['total']) . ').'
            : ' (sin límite).';
        $fiscalOverageText = $license->fiscal_overage_unit_price !== null
            ? ' a ₡' . number_format((float) $license->fiscal_overage_unit_price, 2) . ' c/u.'
            : '.';
        $fiscalTypeLabels = ['01' => 'Facturas electrónicas (01)', '04' => 'Tiquetes electrónicos (04)'];

        return view('license.status', compact('company', 'license', 'fiscal', 'fiscalQuotaText', 'fiscalOverageText', 'fiscalTypeLabels'));
    }
}
