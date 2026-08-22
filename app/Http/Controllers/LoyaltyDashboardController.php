<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Loyalty\LoyaltyOpportunityService;
use Illuminate\View\View;

class LoyaltyDashboardController extends Controller
{
    public function __invoke(LoyaltyOpportunityService $opportunities): View
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        return view('loyalty.dashboard', ['summary' => $opportunities->dashboard($company)]);
    }
}
