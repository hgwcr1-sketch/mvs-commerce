<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLoyaltySettingRequest;
use App\Models\Branch;
use App\Models\LoyaltySetting;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LoyaltyRuleCenterController extends Controller
{
    private const DEFAULTS = [
        'is_active' => false,
        'earning_percentage' => '0.0000',
        'point_value' => '1.0000',
        'birthday_enabled' => false,
        'birthday_points' => '0.0000',
        'returning_customer_enabled' => false,
        'returning_customer_days' => 0,
        'returning_customer_points' => '0.0000',
    ];

    public function index(LoyaltyRegistrationIncentiveService $incentives): View
    {
        $companyId = (int) session('active_company_id');

        $loyaltySetting = LoyaltySetting::query()->where('company_id', $companyId)->first()
            ?? new LoyaltySetting(['company_id' => $companyId] + self::DEFAULTS);

        $registrationIncentive = $incentives->settingForCompanyId($companyId);
        $registrationIncentiveBranches = Branch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('loyalty.rules.index', compact('loyaltySetting', 'registrationIncentive', 'registrationIncentiveBranches'));
    }

    public function update(UpdateLoyaltySettingRequest $request): RedirectResponse
    {
        $companyId = (int) session('active_company_id');

        LoyaltySetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            $request->toValues(),
        );

        return back()->with('success', 'Reglas de Fidelización actualizadas correctamente.');
    }
}
