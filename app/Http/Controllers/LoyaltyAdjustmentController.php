<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoyaltyAdjustmentRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Services\Loyalty\LoyaltyAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LoyaltyAdjustmentController extends Controller
{
    public function __construct(private readonly LoyaltyAccountService $accountService) {}

    public function index(): View
    {
        $companyId = (int) session('active_company_id');

        return view('loyalty.adjustments.index', [
            'customers' => Customer::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'adjustments' => LoyaltyMovement::query()
                ->where('company_id', $companyId)
                ->where('type', LoyaltyMovement::TYPE_ADJUSTMENT)
                ->with(['customer', 'user', 'branch'])
                ->latest()
                ->paginate(20),
            'branchName' => Branch::query()->find((int) session('active_branch_id'))?->name,
        ]);
    }

    public function store(StoreLoyaltyAdjustmentRequest $request): RedirectResponse
    {
        $companyId = (int) session('active_company_id');

        $company = Company::query()->findOrFail($companyId);
        $customer = Customer::query()->where('company_id', $companyId)->findOrFail($request->validated('customer_id'));
        $branch = Branch::query()->where('company_id', $companyId)->findOrFail((int) session('active_branch_id'));
        $user = $request->user();

        $points = bcadd((string) $request->validated('points'), '0', 4);
        $signedPoints = $request->validated('direction') === 'restar' ? '-'.$points : $points;

        $account = $this->accountService->getOrCreateAccount($customer, $company, $user);

        $this->accountService->adjustPoints($account, $signedPoints, [
            'branch' => $branch,
            'user' => $user,
            'description' => (string) $request->validated('reason'),
            'source_type' => 'loyalty_manual_adjustment',
            'event_key' => 'adjustment:'.$request->validated('event_token'),
            'metadata' => [
                'direction' => (string) $request->validated('direction'),
                'requested_points' => $points,
                'reason' => (string) $request->validated('reason'),
            ],
        ]);

        return back()->with('success', 'Ajuste de puntos registrado correctamente.');
    }
}
