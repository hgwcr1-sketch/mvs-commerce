<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyCustomerContact;
use App\Services\Loyalty\LoyaltyMessageTemplateService;
use App\Services\Loyalty\LoyaltyOpportunityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoyaltyOpportunityController extends Controller
{
    public function index(Request $request, LoyaltyOpportunityService $opportunities, LoyaltyMessageTemplateService $messages): View
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        return view('loyalty.opportunities.index', [
            'company' => $company,
            'customers' => $opportunities->opportunities($company, $request),
            'messages' => $messages,
        ]);
    }

    public function contact(Request $request, Customer $customer): RedirectResponse
    {
        $companyId = (int) session('active_company_id');
        abort_unless((int) $customer->company_id === $companyId, 404);
        $data = $request->validate([
            'opportunity_type' => ['required', 'in:birthday,inactive_30,inactive_60,inactive_90'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        LoyaltyCustomerContact::create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'branch_id' => session('active_branch_id'),
            'opportunity_type' => $data['opportunity_type'],
            'channel' => 'whatsapp',
            'contacted_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Contacto registrado correctamente.');
    }
}
