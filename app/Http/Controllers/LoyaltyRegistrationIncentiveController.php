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
                'participating_branch_ids' => $request->validated('branch_scope') === 'all'
                    ? null
                    : $request->validated('participating_branch_ids'),
                'allow_offer_products' => $request->boolean('allow_offer_products'),
                'maximum_discount_enabled' => $request->boolean('maximum_discount_enabled'),
                'maximum_discount_amount' => $request->validated('maximum_discount_amount') ?? '0',
                'stacking_allowed' => $request->boolean('stacking_allowed'),
                'require_verified_phone' => $request->boolean('require_verified_phone'),
                'require_verified_email' => $request->boolean('require_verified_email'),
                'configured_by' => $request->user()?->id,
            ],
        );

        return back()->with('success', 'Incentivo de registro actualizado.');
    }
}
