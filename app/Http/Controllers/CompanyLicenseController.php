<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyLicenseService;
use Illuminate\View\View;

class CompanyLicenseController extends Controller
{
    public function show(CompanyLicenseService $licenses): View
    {
        $company = Company::findOrFail(session('active_company_id'));
        $license = $licenses->refresh($licenses->ensure($company));

        return view('license.status', compact('company', 'license'));
    }
}
