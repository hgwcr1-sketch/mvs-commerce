<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoyaltyRegistrationIncentiveController extends Controller
{
    public function update(Request $request, LoyaltyRegistrationIncentiveService $incentives): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $data = $request->validate(['is_enabled' => ['nullable', 'boolean']]);
        $incentives->toggle($company, $request->boolean('is_enabled'));

        return back()->with('success', 'Incentivo de registro actualizado.');
    }
}
