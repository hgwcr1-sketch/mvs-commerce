<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexLoyaltyMovementRequest;
use App\Models\LoyaltyMovement;
use App\Services\Loyalty\LoyaltyMovementQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoyaltyMovementController extends Controller
{
    public function index(IndexLoyaltyMovementRequest $request, LoyaltyMovementQueryService $service): View
    {
        $companyId = (int) $request->session()->get('active_company_id');
        $filters = $request->validated();

        return view('loyalty.kardex.index', [
            'movements' => $service->paginate($companyId, $filters),
            'customers' => $service->customersWithAccounts($companyId),
            'branches' => $service->filterOptions($companyId)['branches'],
            'filters' => $filters,
            'types' => $this->typeLabels(),
        ]);
    }

    public function show(Request $request, LoyaltyMovement $movement, LoyaltyMovementQueryService $service): View
    {
        $companyId = (int) $request->session()->get('active_company_id');

        return view('loyalty.kardex.show', [
            'movement' => $service->detail($companyId, $movement->id),
            'types' => $this->typeLabels(),
        ]);
    }

    private function typeLabels(): array
    {
        return [
            LoyaltyMovement::TYPE_PURCHASE => 'Compra',
            LoyaltyMovement::TYPE_NEW_CUSTOMER => 'Cliente nuevo',
            LoyaltyMovement::TYPE_BIRTHDAY => 'Cumpleaños',
            LoyaltyMovement::TYPE_RETURN_CUSTOMER => 'Cliente que retorna',
            LoyaltyMovement::TYPE_PROMOTION => 'Promoción',
            LoyaltyMovement::TYPE_REDEMPTION => 'Canje',
            LoyaltyMovement::TYPE_REWARD => 'Premio',
            LoyaltyMovement::TYPE_RETURN => 'Devolución',
            LoyaltyMovement::TYPE_VOID => 'Anulación',
            LoyaltyMovement::TYPE_EXPIRATION => 'Vencimiento',
            LoyaltyMovement::TYPE_ADJUSTMENT => 'Ajuste',
        ];
    }
}
