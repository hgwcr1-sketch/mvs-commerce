<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Loyalty\LoyaltyCustomerPortalService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoyaltyCustomerPortalController extends Controller
{
    public function __construct(private readonly LoyaltyCustomerPortalService $portal) {}

    public function show(Request $request, Customer $cliente): View
    {
        $company = Company::query()->findOrFail((int) $request->session()->get('active_company_id'));

        // Aislamiento: el cliente siempre se resuelve dentro de la empresa activa.
        $customer = Customer::query()
            ->where('company_id', $company->id)
            ->findOrFail($cliente->getKey());

        return view('loyalty.portal.show', $this->portal->data($company, $customer));
    }
}
