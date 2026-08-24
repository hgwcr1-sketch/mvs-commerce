<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalAccess;
use App\Services\Loyalty\LoyaltyCustomerPortalService;
use App\Services\Loyalty\LoyaltyPortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoyaltyPortalAccessController extends Controller
{
    /**
     * Acceso público del cliente (F33/F34): resuelve el token seguro y renderiza
     * el mismo portal F30-F32, sin sesión staff.
     */
    public function access(string $token, LoyaltyPortalAccessService $portalAccess, LoyaltyCustomerPortalService $portal): View
    {
        $resolved = $portalAccess->resolve($token);
        abort_unless($resolved !== null, 404);

        return view('loyalty.portal.show', $portal->data($resolved['company'], $resolved['customer']));
    }

    public function index(Request $request, LoyaltyPortalAccessService $service): View
    {
        $companyId = (int) $request->session()->get('active_company_id');

        return view('loyalty.accesses.index', [
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
            'qrSupported' => $service->qrSupported(),
        ]);
    }

    public function store(Request $request, LoyaltyPortalAccessService $service): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
        ]);

        $company = Company::query()->findOrFail((int) $request->session()->get('active_company_id'));
        $customer = Customer::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->findOrFail($validated['customer_id']);

        // Regenerar revoca el acceso anterior; el token en claro solo existe en esta respuesta.
        $result = $service->generate($customer, $company, $request->user());

        return back()->with([
            'success' => 'Enlace de acceso generado para '.$customer->name.'. Se muestra una sola vez; si se pierde debe regenerarse.',
            'portal_url' => $result['url'],
            'portal_url_customer' => $customer->name,
        ]);
    }

    public function revoke(Request $request, Customer $cliente, LoyaltyPortalAccessService $service): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) $request->session()->get('active_company_id'));

        // Aislamiento: solo clientes de la empresa activa.
        $customer = Customer::query()
            ->where('company_id', $company->id)
            ->findOrFail($cliente->getKey());

        $revoked = $service->revoke($customer, $company);

        return back()->with('success', $revoked > 0
            ? 'Acceso al portal revocado para '.$customer->name.'.'
            : $customer->name.' no tiene un acceso activo al portal.');
    }
}
