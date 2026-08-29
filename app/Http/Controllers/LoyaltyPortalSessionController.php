<?php

namespace App\Http\Controllers;

use App\Mail\LoyaltyPortalPasswordResetMail;
use App\Mail\SaleReceiptMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Models\Sale;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyCustomerPortalService;
use App\Services\PhoneNumberService;
use App\Services\Sales\SaleReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class LoyaltyPortalSessionController extends Controller
{
    public function loginForm(Company $company): View
    {
        abort_unless($company->is_active, 404);

        return view('loyalty.portal.login', compact('company'));
    }

    public function registerForm(Company $company): View
    {
        abort_unless($company->is_active, 404);

        return view('loyalty.portal.register', compact('company'));
    }

    public function register(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->is_active, 404);

        $rateKey = 'loyalty-portal-register:'.$company->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return back()->withErrors(['name' => 'Demasiados intentos. Inténtalo nuevamente en '.RateLimiter::availableIn($rateKey).' segundos.'])->onlyInput();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'identification_type' => ['nullable', 'string', 'in:01,02,03,04,05'],
            'identification' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->mixedCase()->numbers()],
        ]);

        // Normalización
        $phones = app(PhoneNumberService::class);
        $identification = isset($data['identification']) ? trim($data['identification']) : null;
        if ($identification === '') {
            $identification = null;
        }
        $phoneNormalized = $phones->normalizePhone($data['phone'] ?? null);
        $phoneCountryCode = $phones->normalizeCountryCode($company->default_phone_country_code);
        $emailNormalized = isset($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        if ($emailNormalized === '') {
            $emailNormalized = null;
        }
        $username = trim($data['username']);

        // Validación mínima: al menos teléfono o correo o identificación para poder dedup y contacto
        // Se permite registro solo con nombre+credenciales, pero P03 exige chequear duplicados si alguno viene.

        // P03 – deduplicación y bloqueo por conflicto de identidad
        // Si identificación, teléfono o correo apuntan a clientes distintos, bloquear sin fusionar.
        $byIdentification = $identification ? Customer::query()->where('company_id', $company->id)->where('identification', $identification)->first() : null;
        $byPhone = $phoneNormalized ? Customer::query()->where('company_id', $company->id)->where(function ($query) use ($phoneNormalized) {
            $query->where('phone', $phoneNormalized)->orWhere('mobile', $phoneNormalized);
        })->first() : null;
        $byEmail = $emailNormalized ? Customer::query()->where('company_id', $company->id)->whereRaw('LOWER(email) = ?', [$emailNormalized])->first() : null;

        $foundMap = array_filter(['identification' => $byIdentification, 'phone' => $byPhone, 'email' => $byEmail]);
        $uniqueIds = collect($foundMap)->pluck('id')->unique()->filter();

        if ($uniqueIds->count() > 1) {
            RateLimiter::hit($rateKey, 60);

            return back()->withErrors(['identification' => 'Los datos proporcionados coinciden con clientes distintos. Contacta a soporte o verifica tu información.'])->onlyInput('name', 'identification', 'phone', 'email', 'username');
        }

        $existingCustomer = $foundMap ? reset($foundMap) : null;

        if ($existingCustomer && LoyaltyPortalCredential::query()->where('customer_id', $existingCustomer->id)->exists()) {
            RateLimiter::hit($rateKey, 60);

            return back()->withErrors(['username' => 'Este cliente ya tiene acceso. Inicia sesión o recupera tu contraseña.'])->onlyInput('name', 'identification', 'phone', 'email', 'username');
        }

        // Validar unicidad de username/email de portal dentro de la empresa (aislado)
        $credentialDuplicate = LoyaltyPortalCredential::query()
            ->where('company_id', $company->id)
            ->where(function ($query) use ($username, $emailNormalized) {
                $query->where('username', $username);
                if ($emailNormalized !== null) {
                    $query->orWhere('email', $emailNormalized);
                }
            })
            ->when($existingCustomer, fn ($query) => $query->where('customer_id', '!=', $existingCustomer->id))
            ->exists();

        if ($credentialDuplicate) {
            RateLimiter::hit($rateKey, 60);

            return back()->withErrors(['username' => 'El usuario o correo ya está registrado en esta empresa.'])->onlyInput('name', 'identification', 'phone', 'email', 'username');
        }

        $result = DB::transaction(function () use ($company, $data, $identification, $phoneNormalized, $phoneCountryCode, $emailNormalized, $username, $existingCustomer) {
            // Reutilizar cliente existente si aplica (P03), sino crear activo disponible para Clientes/POS/Fidelización
            if ($existingCustomer) {
                $customer = $existingCustomer;
            } else {
                $customer = Customer::create([
                    'company_id' => $company->id,
                    'customer_type' => 'individual',
                    'identification_type' => $data['identification_type'] ?? null,
                    'identification' => $identification,
                    'name' => trim($data['name']),
                    'phone' => $phoneNormalized,
                    'phone_country_code' => $phoneNormalized ? $phoneCountryCode : null,
                    'mobile' => null,
                    'email' => $emailNormalized,
                    'is_active' => true,
                    'credit_limit' => 0,
                    'credit_days' => 0,
                    'price_level' => 'normal',
                ]);
            }

            // P05 – Crear/activar cuenta de fidelización al autorregistrarse (sin duplicar, sin bono)
            app(LoyaltyAccountService::class)->getOrCreateAccount($customer, $company);

            $credential = LoyaltyPortalCredential::create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'username' => $username,
                'email' => $emailNormalized ?? $username.'@portal.local',
                'password' => $data['password'],
                'is_active' => true,
            ]);

            return ['customer' => $customer, 'credential' => $credential];
        });

        RateLimiter::clear($rateKey);
        $request->session()->regenerate();
        $this->putPortalSession($request, $company->id, $result['customer']->id);
        $result['credential']->update(['last_login_at' => now()]);

        return redirect()->route('loyalty.customer.home', $company)->with('success', 'Cuenta creada correctamente.');
    }

    public function login(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:150'], 'password' => ['required', 'string']]);
        $rateKey = 'loyalty-portal-login:'.$company->id.':'.Str::lower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return back()->withErrors(['username' => 'Demasiados intentos. Inténtalo nuevamente en '.RateLimiter::availableIn($rateKey).' segundos.'])->onlyInput('username');
        }
        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('is_active', true)
            ->where(fn ($query) => $query->where('username', $data['username'])->orWhere('email', $data['username']))->first();
        if (! $credential || ! Hash::check($data['password'], $credential->password)) {
            RateLimiter::hit($rateKey, 60);

            return back()->withErrors(['username' => 'Las credenciales no son válidas.'])->onlyInput('username');
        }

        RateLimiter::clear($rateKey);
        $request->session()->regenerate();
        $this->putPortalSession($request, $credential->company_id, $credential->customer_id);
        $credential->update(['last_login_at' => now()]);

        if ($credential->must_change_password) {
            return redirect()->route('loyalty.customer.password.force', $company);
        }

        return redirect()->route('loyalty.customer.home', $company);
    }

    public function forceChangeForm(Request $request, Company $company): View
    {
        $customer = $this->sessionCustomer($request, $company);
        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        abort_unless($credential->must_change_password, 404);

        return view('loyalty.portal.force-change', compact('company'));
    }

    public function forceChange(Request $request, Company $company): RedirectResponse
    {
        $customer = $this->sessionCustomer($request, $company);
        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        abort_unless($credential->must_change_password, 404);

        $data = $request->validate([
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->mixedCase()->numbers()],
        ]);

        $credential->update(['password' => $data['password'], 'must_change_password' => false]);

        return redirect()->route('loyalty.customer.home', $company)->with('success', 'Contraseña actualizada correctamente.');
    }

    public function home(Request $request, Company $company, LoyaltyCustomerPortalService $portal): View|RedirectResponse
    {
        $customer = $this->sessionCustomer($request, $company);
        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->first();
        if ($credential && $credential->must_change_password) {
            return redirect()->route('loyalty.customer.password.force', $company);
        }

        return view('loyalty.portal.show', $portal->data($company, $customer) + ['customerAuthenticated' => true]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) $request->session()->get('loyalty_portal_company_id'));
        $customer = $this->sessionCustomer($request, $company);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100', 'alpha_dash'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->mixedCase()->numbers()],
        ]);
        $duplicate = LoyaltyPortalCredential::query()->where('company_id', $company->id)
            ->where('customer_id', '!=', $customer->id)
            ->where(fn ($query) => $query->where('username', $data['username'])->orWhere('email', $data['email']))->exists();
        if ($duplicate) {
            return back()->withErrors(['username' => 'El usuario o correo ya está registrado en esta empresa.']);
        }
        LoyaltyPortalCredential::updateOrCreate(['customer_id' => $customer->id], $data + ['company_id' => $company->id, 'is_active' => true]);

        return back()->with('success', 'Tu acceso con contraseña quedó configurado.');
    }

    public function profile(Request $request, Company $company): RedirectResponse
    {
        $customer = $this->sessionCustomer($request, $company);
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'accepts_email_invoice' => ['nullable', 'boolean'],
        ]);
        $data['accepts_email_invoice'] = $request->boolean('accepts_email_invoice');
        $customer->update($data);

        return back()->with('success', 'Preferencias actualizadas.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $companyId = (int) $request->session()->pull('loyalty_portal_company_id');
        $request->session()->forget('loyalty_portal_customer_id');
        $request->session()->regenerateToken();

        return redirect()->route('loyalty.customer.login', $companyId);
    }

    public function receiptPdf(Request $request, Company $company, Sale $sale, SaleReceiptService $receipts)
    {
        $customer = $this->sessionCustomer($request, $company);
        $sale = $this->customerSale($sale, $company, $customer);

        return $receipts->pdf($sale, $company)->download("comprobante-{$sale->sale_number}.pdf");
    }

    public function sendReceipt(Request $request, Company $company, Sale $sale, SaleReceiptService $receipts): RedirectResponse
    {
        $customer = $this->sessionCustomer($request, $company);
        $sale = $this->customerSale($sale, $company, $customer);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:150']]);
        Mail::to($data['email'])->send(new SaleReceiptMail($sale, $receipts->pdf($sale, $company)->output()));

        return back()->with('success', 'Comprobante enviado.');
    }

    public function forgotForm(Company $company): View
    {
        return view('loyalty.portal.forgot-password', compact('company'));
    }

    public function forgot(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:150']]);
        $credential = LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('email', $data['email'])->where('is_active', true)->first();
        if ($credential) {
            $token = Str::random(64);
            DB::table('loyalty_portal_password_resets')->where('credential_id', $credential->id)->delete();
            DB::table('loyalty_portal_password_resets')->insert(['credential_id' => $credential->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
            Mail::to($credential->email)->send(new LoyaltyPortalPasswordResetMail($credential, route('loyalty.customer.password.reset', ['company' => $company, 'token' => $token])));
        }

        return back()->with('success', 'Si el correo está registrado, recibirás un enlace de recuperación.');
    }

    public function resetForm(Company $company, string $token): View
    {
        abort_unless($this->validReset($company, $token), 404);

        return view('loyalty.portal.reset-password', compact('company', 'token'));
    }

    public function reset(Request $request, Company $company, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->mixedCase()->numbers()]]);
        $reset = $this->validReset($company, $token);
        abort_unless($reset, 404);
        DB::transaction(function () use ($reset, $data) {
            LoyaltyPortalCredential::query()->findOrFail($reset->credential_id)->update(['password' => $data['password']]);
            DB::table('loyalty_portal_password_resets')->where('id', $reset->id)->update(['used_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('loyalty.customer.login', $company)->with('success', 'Contraseña actualizada. Ya puedes ingresar.');
    }

    public function establishSession(Request $request, Company $company, Customer $customer): void
    {
        $this->putPortalSession($request, $company->id, $customer->id);
    }

    private function sessionCustomer(Request $request, Company $company): Customer
    {
        abort_unless((int) $request->session()->get('loyalty_portal_company_id') === (int) $company->id, 403);

        return Customer::query()->where('company_id', $company->id)->findOrFail((int) $request->session()->get('loyalty_portal_customer_id'));
    }

    private function putPortalSession(Request $request, int $companyId, int $customerId): void
    {
        $request->session()->put(['loyalty_portal_company_id' => $companyId, 'loyalty_portal_customer_id' => $customerId]);
    }

    private function findExistingCustomer(Company $company, ?string $identification, ?string $phoneNormalized, ?string $emailNormalized): ?Customer
    {
        if ($identification !== null && $identification !== '') {
            $found = Customer::query()->where('company_id', $company->id)->where('identification', $identification)->first();
            if ($found) {
                return $found;
            }
        }

        if ($phoneNormalized !== null && $phoneNormalized !== '') {
            $found = Customer::query()->where('company_id', $company->id)
                ->where(function ($query) use ($phoneNormalized) {
                    $query->where('phone', $phoneNormalized)->orWhere('mobile', $phoneNormalized);
                })->first();
            if ($found) {
                return $found;
            }
        }

        if ($emailNormalized !== null && $emailNormalized !== '') {
            $found = Customer::query()->where('company_id', $company->id)->whereRaw('LOWER(email) = ?', [$emailNormalized])->first();
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function customerSale(Sale $sale, Company $company, Customer $customer): Sale
    {
        abort_unless((int) $sale->company_id === (int) $company->id && (int) $sale->customer_id === (int) $customer->id, 404);

        return $sale->load(['company', 'branch', 'user', 'customer', 'items', 'payments.paymentMethod', 'cashSession.cashRegister']);
    }

    private function validReset(Company $company, string $token): ?object
    {
        return DB::table('loyalty_portal_password_resets as reset')->join('loyalty_portal_credentials as credential', 'credential.id', '=', 'reset.credential_id')
            ->where('credential.company_id', $company->id)->where('reset.token_hash', hash('sha256', $token))->whereNull('reset.used_at')->where('reset.expires_at', '>', now())
            ->select('reset.*')->first();
    }
}
