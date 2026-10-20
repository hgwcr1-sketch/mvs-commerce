<?php

namespace App\Services\Cobros;

use App\Contracts\Cobros\MaestroReferralCredentialProvider;
use App\Models\Company;
use App\Models\User;

final class UnavailableMaestroReferralCredentialProvider implements MaestroReferralCredentialProvider
{
    public function for(User $user, Company $company): ?string
    {
        return null;
    }
}
