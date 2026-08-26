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
            [
                'minimum_purchase_enabled' => $request->boolean('minimum_purchase_enabled'),
                'minimum_purchase_amount' => $request->validated('minimum_purchase_amount') ?? '0',
                'award_timing' => $request->validated('award_timing'),
                'allow_on_first_purchase' => $request->boolean('allow_on_first_purchase'),
                'bypass_redemption_minimum' => $request->boolean('bypass_redemption_minimum'),
                'expiration_enabled' => $request->boolean('expiration_enabled'),
                'expiration_days' => $request->validated('expiration_days'),
            ],
        );

        return back()->with('success', 'Incentivo de registro actualizado.');
    }
}
