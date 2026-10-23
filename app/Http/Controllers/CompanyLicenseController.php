<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyLicenseService;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosModalityService;
use App\Services\Cobros\MaestroRenewalAccessAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyLicenseController extends Controller
{
    public function show(Request $request, CompanyLicenseService $licenses, CobrosModalityService $modalities): View|RedirectResponse
    {
        $company = Company::findOrFail(session('active_company_id'));
        $license = $licenses->refresh($licenses->ensure($company));

        return view('license.status', ['company'=>$company, 'license'=>$license, 'canRenew'=>$this->canRenew($request->user(), $company, $modalities)]);
    }

    public function renew(Request $request, MaestroRenewalAccessAdapter $adapter, CobrosModalityService $modalities): RedirectResponse
    {
        $company=Company::findOrFail(session('active_company_id'));
        abort_unless($this->canRenew($request->user(),$company,$modalities),403);
        $result=$adapter->access($request->user(),$company);
        if (!($result['ok']??false)) return back()->with('renewal_error',$result['reason']??'No fue posible iniciar la renovación.');
        return redirect()->away($result['url']);
    }

    private function canRenew($user, Company $company, CobrosModalityService $modalities): bool
    {
        if (!$user || !$user->isActive() || !$user->companies()->whereKey($company->id)->exists()) return false;
        if ((int)$company->owner_user_id === (int)$user->id || $user->hasPermission('cobros.portal_dueno',$company)) return true;
        return $modalities->currentModality($company,$user) === CobrosModalityCatalog::COBRADOR_INDEPENDIENTE;
    }
}
