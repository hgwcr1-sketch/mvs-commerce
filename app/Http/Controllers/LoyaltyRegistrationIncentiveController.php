<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLoyaltyRegistrationIncentiveRequest;
use App\Models\Company;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Http\RedirectResponse;

class LoyaltyRegistrationIncentiveController extends Controller
{
    public function update(UpdateLoyaltyRegistrationIncentiveRequest $request, LoyaltyRegistrationIncentiveService $incentives): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $incentives->configure(
            $company,
            $request->boolean('is_enabled'),
            $request->validated('benefit_type'),
            $request->validated('benefit_value'),
        );

        return back()->with('success', 'Incentivo de registro actualizado.');
    }
}
