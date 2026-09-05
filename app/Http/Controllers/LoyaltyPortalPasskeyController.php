<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalSetting;
use App\Services\Loyalty\LoyaltyPortalPasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoyaltyPortalPasskeyController extends Controller
{
    public function __construct(private readonly LoyaltyPortalPasskeyService $passkeys) {}

    public function manage(Request $request, Company $company): View
    {
        $customer = $this->customerFromSession($request, $company);

        return view('loyalty.portal.passkeys', [
            'company' => $company,
            'customer' => $customer,
            'passkeys' => $this->passkeys->list($customer, $company),
            'portalBranding' => $this->branding($company),
        ]);
    }

    public function startRegistration(Request $request, Company $company): JsonResponse
    {
        $customer = $this->customerFromSession($request, $company);
        $rateKey = 'loyalty-portal-passkey-register:'.$company->id.':'.$customer->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            throw ValidationException::withMessages(['passkey' => 'Demasiados intentos. Intenta de nuevo en '.RateLimiter::availableIn($rateKey).' segundos.']);
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $payload = $this->passkeys->startRegistration($customer, $company, $data['name']);
        RateLimiter::hit($rateKey, 60);

        return response()->json($payload);
    }

    public function finishRegistration(Request $request, Company $company): JsonResponse
    {
        $customer = $this->customerFromSession($request, $company);
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:128'],
            'credential_id' => ['required', 'string', 'max:64'],
            'enrollment_secret' => ['required', 'string', 'max:256'],
            'challenge_signature' => ['required', 'string', 'max:256'],
        ]);

        $result = $this->passkeys->finishRegistration(
            $customer,
            $company,
            $data['challenge'],
            $data['credential_id'],
            $data['enrollment_secret'],
            $data['challenge_signature'],
            $data['name'],
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'ok' => true,
            'passkey' => ['id' => $result['passkey']->id, 'name' => $result['passkey']->name, 'algorithm' => $result['passkey']->algorithm],
            'enrollment_secret' => $this->base64UrlEncode($result['enrollment_secret']),
        ]);
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function startAuthentication(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:150']]);
        $rateKey = 'loyalty-portal-passkey-auth:'.$company->id.':'.Str::lower($data['identifier']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            throw ValidationException::withMessages(['identifier' => 'Demasiados intentos. Intenta de nuevo en '.RateLimiter::availableIn($rateKey).' segundos.']);
        }
        $payload = $this->passkeys->startAuthentication($company, $data['identifier']);
        RateLimiter::hit($rateKey, 60);

        return response()->json($payload);
    }

    public function finishAuthentication(Request $request, Company $company): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
            'credential_id' => ['required', 'string', 'max:64'],
            'challenge' => ['required', 'string', 'max:128'],
            'signature' => ['required', 'string', 'max:1024'],
        ]);
        $rateKey = 'loyalty-portal-passkey-auth:'.$company->id.':'.Str::lower($data['identifier']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            throw ValidationException::withMessages(['identifier' => 'Demasiados intentos. Intenta de nuevo en '.RateLimiter::availableIn($rateKey).' segundos.']);
        }
        $result = $this->passkeys->finishAuthentication(
            $company,
            $data['identifier'],
            $data['credential_id'],
            $data['challenge'],
            $data['signature'],
        );
        $request->session()->regenerate();
        $request->session()->put([
            'loyalty_portal_company_id' => $company->id,
            'loyalty_portal_customer_id' => $result['customer']->id,
        ]);
        $result['credential']->update(['last_login_at' => now()]);
        RateLimiter::clear($rateKey);

        if ($request->wantsJson() && ! $request->header('X-Portal-Form')) {
            return response()->json(['ok' => true, 'redirect' => route('loyalty.customer.home', $company)]);
        }

        return redirect()->route('loyalty.customer.home', $company);
    }

    public function revoke(Request $request, Company $company, int $passkey): RedirectResponse
    {
        $customer = $this->customerFromSession($request, $company);
        $this->passkeys->revoke($customer, $company, $passkey, $request->ip());

        return back()->with('success', 'Passkey revocada.');
    }

    public function rename(Request $request, Company $company, int $passkey): RedirectResponse
    {
        $customer = $this->customerFromSession($request, $company);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $this->passkeys->rename($customer, $company, $passkey, $data['name']);

        return back()->with('success', 'Passkey renombrada.');
    }

    private function customerFromSession(Request $request, Company $company): Customer
    {
        abort_unless((int) $request->session()->get('loyalty_portal_company_id') === (int) $company->id, 403);
        $customerId = (int) $request->session()->get('loyalty_portal_customer_id');
        abort_unless($customerId > 0, 403);

        return Customer::query()->where('company_id', $company->id)->where('id', $customerId)->firstOrFail();
    }

    private function branding(Company $company): LoyaltyPortalSetting
    {
        return LoyaltyPortalSetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            ['is_active' => true, 'show_active_offers' => true],
        );
    }
}