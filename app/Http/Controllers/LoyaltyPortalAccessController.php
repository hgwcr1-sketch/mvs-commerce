<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalAccess;
use App\Models\LoyaltyPortalSetting;
use App\Services\Loyalty\LoyaltyCustomerPortalService;
use App\Services\Loyalty\LoyaltyPortalAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoyaltyPortalAccessController extends Controller
{
    /**
     * Acceso público del cliente (F33/F34): resuelve el token seguro y renderiza
     * el mismo portal F30-F32, sin sesión staff.
     */
    public function access(Request $request, string $token, LoyaltyPortalAccessService $portalAccess, LoyaltyCustomerPortalService $portal): View
    {
        $resolved = $portalAccess->resolve($token);
        abort_unless($resolved !== null, 404);
        $request->session()->regenerate();
        $request->session()->put([
            'loyalty_portal_company_id' => $resolved['company']->id,
            'loyalty_portal_customer_id' => $resolved['customer']->id,
        ]);

        $branding = LoyaltyPortalSetting::query()->firstOrCreate(
            ['company_id' => $resolved['company']->id],
            ['is_active' => true, 'show_active_offers' => true]
        );

        return view('loyalty.portal.show', $portal->data($resolved['company'], $resolved['customer']) + ['customerAuthenticated' => true, 'portalBranding' => $branding]);
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
            'qrSupported' => $service->qrSupported(),
        ]);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $companyId = (int) $request->session()->get('active_company_id');
        $search = trim((string) $request->query('q', ''));

        if ($search === '') {
            return response()->json([]);
        }

        $search = mb_substr($search, 0, 100);
        $like = '%'.$search.'%';
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $customers = Customer::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($like, $likeOperator) {
                $query->where('name', $likeOperator, $like)
                    ->orWhere('identification', $likeOperator, $like)
                    ->orWhere('phone', $likeOperator, $like)
                    ->orWhere('mobile', $likeOperator, $like)
                    ->orWhere('email', $likeOperator, $like);
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'identification', 'phone', 'mobile', 'email']);

        return response()->json($customers);
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

        $phones = app(\App\Services\PhoneNumberService::class);
        $rawPhone = $customer->phone ?: $customer->mobile;
        $countryCode = $customer->phone_country_code ?: $company->default_phone_country_code;
        $rawDigits = preg_replace('/\D/', '', (string) $rawPhone);
        if (!$countryCode && $rawDigits && preg_match('/^\d{8}$/', $rawDigits)) {
            $countryCode = '506';
        }
        $whatsappPhone = $phones->forWhatsApp($countryCode, $rawPhone);
        $whatsappUrl = null;
        if ($whatsappPhone) {
            $message = 'Hola '.$customer->name.', tu acceso al Portal de Clientes de '.$company->trade_name.":\n".$result['url'];
            $whatsappUrl = 'https://wa.me/'.$whatsappPhone.'?text='.rawurlencode($message);
        }

        $flash = [
            'success' => 'Enlace de acceso generado para '.$customer->name.'. Se muestra una sola vez; si se pierde debe regenerarse.',
            'portal_url' => $result['url'],
            'portal_url_customer' => $customer->name,
            'portal_customer_email' => $customer->email,
            'portal_whatsapp_url' => $whatsappUrl,
            'portal_whatsapp_phone' => $whatsappPhone,
        ];

        // F33: el QR codifica exactamente el enlace seguro y se genera localmente;
        // como el token vive solo en esta respuesta, tampoco se persiste el QR.
        if ($service->qrSupported()) {
            $flash['portal_qr'] = $service->qrSvg($result['url']);
        }

        return back()->with($flash);
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
