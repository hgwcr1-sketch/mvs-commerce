<?php

namespace App\Http\Controllers;

use App\Http\Requests\RedeemLoyaltyRewardRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRewardRedemption;
use App\Services\Loyalty\LoyaltyRewardRedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LoyaltyRewardRedemptionController extends Controller
{
    public function index(): View
    {
        $companyId = (int) session('active_company_id');

        return view('loyalty.redemptions.index', [
            'redemptions' => LoyaltyRewardRedemption::query()
                ->where('company_id', $companyId)
                ->with(['customer', 'user', 'branch', 'loyaltyMovement'])
                ->latest()
                ->paginate(20),
            'customers' => Customer::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'rewards' => LoyaltyReward::query()->where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name', 'points_cost', 'availability_mode']),
        ]);
    }

    public function store(RedeemLoyaltyRewardRequest $request, LoyaltyRewardRedemptionService $service): RedirectResponse
    {
        $companyId = (int) session('active_company_id');

        $customer = Customer::query()->where('company_id', $companyId)->findOrFail($request->validated('customer_id'));
        $reward = LoyaltyReward::query()->where('company_id', $companyId)->findOrFail($request->validated('reward_id'));
        $branch = Branch::query()->where('company_id', $companyId)->findOrFail((int) session('active_branch_id'));

        $service->redeem($customer, $reward, $customer->company, $branch, $request->user());

        return back()->with('success', 'Premio canjeado correctamente.');
    }
}
