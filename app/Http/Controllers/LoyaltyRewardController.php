<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLoyaltyRewardRequest;
use App\Models\LoyaltyReward;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LoyaltyRewardController extends Controller
{
    public function index(): View
    {
        $companyId = (int) session('active_company_id');

        return view('loyalty.rewards.index', [
            'rewards' => LoyaltyReward::query()->where('company_id', $companyId)->latest()->paginate(20),
            'types' => LoyaltyReward::TYPES,
        ]);
    }

    public function store(SaveLoyaltyRewardRequest $request): RedirectResponse
    {
        $attributes = $request->validated();
        $attributes['is_active'] = true;
        $attributes['company_id'] = (int) session('active_company_id');

        LoyaltyReward::create($attributes);

        return back()->with('success', 'Premio creado correctamente.');
    }

    public function update(SaveLoyaltyRewardRequest $request, LoyaltyReward $reward): RedirectResponse
    {
        $this->ensureCompany($reward);
        $reward->update($request->validated());

        return back()->with('success', 'Premio actualizado correctamente.');
    }

    public function toggle(LoyaltyReward $reward): RedirectResponse
    {
        $this->ensureCompany($reward);
        $reward->update(['is_active' => ! $reward->is_active]);

        return back()->with('success', 'Estado del premio actualizado.');
    }

    private function ensureCompany(LoyaltyReward $reward): void
    {
        abort_unless((int) $reward->company_id === (int) session('active_company_id'), 404);
    }
}
