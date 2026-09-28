<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLoyaltyPromotionRequest;
use App\Models\Company;
use App\Models\LoyaltyPromotion;
use App\Services\Loyalty\LoyaltyPromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LoyaltyPromotionController extends Controller
{
    public function index(LoyaltyPromotionService $service): View
    {
        $companyId = (int) session('active_company_id');
        $timezone = Company::query()->findOrFail($companyId)->timezone ?: config('app.timezone');

        $promotions = LoyaltyPromotion::query()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->latest('starts_at')
            ->paginate(20);

        $estados = $promotions->getCollection()
            ->mapWithKeys(fn (LoyaltyPromotion $promotion) => [(int) $promotion->id => $service->estado($promotion, $timezone)]);

        return view('loyalty.promotions.index', [
            'promotions' => $promotions,
            'estados' => $estados,
            'timezone' => $timezone,
        ]);
    }

    public function store(SaveLoyaltyPromotionRequest $request): RedirectResponse
    {
        LoyaltyPromotion::create($request->validated() + [
            'company_id' => (int) session('active_company_id'),
            'sort_order' => (int) $request->validated('sort_order', 0),
        ]);

        return back()->with('success', 'Promoción creada correctamente.');
    }

    public function update(SaveLoyaltyPromotionRequest $request, LoyaltyPromotion $promotion): RedirectResponse
    {
        $this->ensureCompany($promotion);
        $data = $request->validated();
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $promotion->update($data);

        return back()->with('success', 'Promoción actualizada correctamente.');
    }

    public function toggle(LoyaltyPromotion $promotion): RedirectResponse
    {
        $this->ensureCompany($promotion);
        $promotion->update(['is_active' => ! $promotion->is_active]);

        return back()->with('success', 'Estado de la promoción actualizado.');
    }

    private function ensureCompany(LoyaltyPromotion $promotion): void
    {
        abort_unless((int) $promotion->company_id === (int) session('active_company_id'), 404);
    }
}
