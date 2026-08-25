<?php

namespace App\Http\Controllers;

use App\Services\BeautyOS\BeautyOSPortalAccessService;
use App\Services\Loyalty\LoyaltyCustomerPortalService;
use Illuminate\View\View;

class BeautyOSPortalAccessController extends Controller
{
    public function __construct(
        private BeautyOSPortalAccessService $beautyPortalAccess,
        private LoyaltyCustomerPortalService $loyaltyPortal
    ) {}

    public function access(string $token): View
    {
        $resolved = $this->beautyPortalAccess->resolve($token);
        abort_unless($resolved !== null, 404);

        return view('beautyos.portal.show', $this->portalData($resolved['company'], $resolved['customer']));
    }

    private function portalData($company, $customer): array
    {
        return [
            'company' => $company,
            'customer' => $customer,
        ];
    }
}
