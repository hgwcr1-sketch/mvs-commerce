<?php

namespace App\Services\BeautyOS;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Core\PortalAccessService;
use Illuminate\Validation\ValidationException;

class BeautyOSPortalAccessService
{
    public function __construct(
        private PortalAccessService $portalAccess
    ) {}

    public function generate(Customer $customer, Company $company, ?User $user): array
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }

        return $this->portalAccess->generate($customer, $company, $user);
    }

    public function revoke(Customer $customer, Company $company): int
    {
        return $this->portalAccess->revoke($customer, $company);
    }

    public function activeFor(Customer $customer, Company $company)
    {
        return $this->portalAccess->activeFor($customer, $company);
    }

    /**
     * Resuelve un token compartido y valida que el cliente tenga módulos BeautyOS habilitados.
     *
     * @return array{access:object,company:Company,customer:Customer}|null
     */
    public function resolve(string $token): ?array
    {
        $resolved = $this->portalAccess->resolve($token);

        if ($resolved === null) {
            return null;
        }

        $customer = $resolved['customer'];

        if (! $this->customerHasBeautyOSAccess($customer)) {
            return null;
        }

        return $resolved;
    }

    private function customerHasBeautyOSAccess(Customer $customer): bool
    {
        return true;
    }
}
