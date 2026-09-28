<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLoyaltyMultiplierRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyMultiplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LoyaltyMultiplierController extends Controller
{
    public function index(): View
    {
        $companyId = (int) session('active_company_id');

        return view('loyalty.multipliers.index', [
            'multipliers' => LoyaltyMultiplier::query()->where('company_id', $companyId)->with('branch')->latest('starts_at')->paginate(20),
            'branches' => Branch::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'timezone' => Company::query()->findOrFail($companyId)->timezone ?: config('app.timezone'),
        ]);
    }

    public function store(SaveLoyaltyMultiplierRequest $request): RedirectResponse
    {
        LoyaltyMultiplier::create($request->validated() + ['company_id' => (int) session('active_company_id')]);

        return back()->with('success', 'Multiplicador creado correctamente.');
    }

    public function update(SaveLoyaltyMultiplierRequest $request, LoyaltyMultiplier $multiplier): RedirectResponse
    {
        $this->ensureCompany($multiplier);
        $multiplier->update($request->validated());

        return back()->with('success', 'Multiplicador actualizado correctamente.');
    }

    public function toggle(LoyaltyMultiplier $multiplier): RedirectResponse
    {
        $this->ensureCompany($multiplier);
        $multiplier->update(['is_active' => ! $multiplier->is_active]);

        return back()->with('success', 'Estado del multiplicador actualizado.');
    }

    private function ensureCompany(LoyaltyMultiplier $multiplier): void
    {
        abort_unless((int) $multiplier->company_id === (int) session('active_company_id'), 404);
    }
}
