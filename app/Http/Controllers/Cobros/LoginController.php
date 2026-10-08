<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Auth\LoginController as SharedLoginController;
use App\Http\Requests\Cobros\LoginRequest;
use App\Models\Company;
use App\Services\Cobros\CollectorLicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends SharedLoginController
{
    public function create()
    {
        return view('cobros.auth.login');
    }

    public function authenticate(LoginRequest $request)
    {
        return parent::store($request);
    }

    protected function authenticated(Request $request)
    {
        // Entry always resolves CobrOS permissions, never a cross-product intended URL.
        $request->session()->forget('url.intended');

        // Licenciamiento individual: sin licencia vigente no inicia sesión
        // en CobrOS (el cobrador ve el motivo y puede reintentar tras renovar).
        $company = $this->entryCompany($request);
        if ($company !== null) {
            $licenses = app(CollectorLicenseService::class);
            if (! $licenses->hasValidLicense($request->user(), $company)) {
                $message = $licenses->denyMessage($request->user(), $company);
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $this->failedLoginRedirect()
                    ->withErrors(['email' => $message])
                    ->onlyInput('email');
            }
        }

        if ($request->user()->must_change_password) {
            return redirect()->route('cobros.password.force');
        }

        return redirect()->route('cobros.index');
    }

    /** Empresa que gobernaría el acceso (misma resolución que el middleware CobrOS). */
    private function entryCompany(Request $request): ?Company
    {
        $activeId = session('active_company_id');
        $company = $activeId ? Company::find($activeId) : null;

        if ($company !== null && ! $request->user()->companies()->where('companies.id', $company->id)->exists()) {
            $company = null;
        }

        return $company ?? $request->user()->companies()
            ->where('companies.is_active', true)
            ->orderBy('companies.id')
            ->first();
    }

    protected function failedLoginRedirect()
    {
        return redirect()->route('cobros.login');
    }

    protected function loggedOut()
    {
        return redirect()->route('cobros.login');
    }
}
