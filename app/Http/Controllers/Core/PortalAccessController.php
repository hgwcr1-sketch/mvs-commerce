<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalAccess;
use App\Services\Core\PortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalAccessController extends Controller
{
    public function __construct(
        private PortalAccessService $portalAccess
    ) {}

    public function access(string $token): View
    {
        $resolved = $this->portalAccess->resolve($token);
        abort_unless($resolved !== null, 404);

        return view('portal.show', $this->portalData($resolved['company'], $resolved['customer']));
    }

    public function index(Request $request): View
    {
        $companyId = (int) $request->session()->get('active_company_id');

        return view('portal.accesses.index', [
            'accesses' => LoyaltyPortalAccess::query()
                ->where('company_id', $companyId)
                ->whereNull('revoked_at')
                ->with(['customer:id,company_id,name', 'user:id,name'])
                ->latest()
                ->paginate(20),
            'customers' => Customer::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
        ]);

        $company = Company::query()->findOrFail((int) $request->session()->get('active_company_id'));
        $customer = Customer::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->findOrFail($validated['customer_id']);

        $result = $this->portalAccess->generate($customer, $company, $request->user());

        return back()->with('success', 'Enlace de acceso generado para '.$customer->name.'. Se muestra una sola vez; si se pierde debe regenerarse.')
            ->with('portal_url', $result['url'])
            ->with('portal_url_customer', $customer->name);
    }

    public function revoke(Request $request, Customer $customer): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) $request->session()->get('active_company_id'));

        $customer = Customer::query()
            ->where('company_id', $company->id)
            ->findOrFail($customer->getKey());

        $revoked = $this->portalAccess->revoke($customer, $company);

        return back()->with('success', $revoked > 0
            ? 'Acceso al portal revocado para '.$customer->name.'.'
            : $customer->name.' no tiene un acceso activo al portal.');
    }

    private function portalData($company, $customer): array
    {
        return [
            'company' => $company,
            'customer' => $customer,
        ];
    }
}
