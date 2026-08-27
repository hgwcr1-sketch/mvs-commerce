<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CompanyLicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyLicense
{
    public function __construct(private readonly CompanyLicenseService $licenses) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isPlatformAdmin() || $request->routeIs('license.status')) {
            return $next($request);
        }
        $companyId = session('active_company_id');
        if (! $companyId) {
            return $next($request);
        }
        $company = Company::find($companyId);
        if (! $company) {
            return $next($request);
        }
        $license = $this->licenses->refresh($this->licenses->ensure($company));
        if (! $license->isOperable()) {
            return redirect()->route('license.status');
        }

        return $next($request);
    }
}
