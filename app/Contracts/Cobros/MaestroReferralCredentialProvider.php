<?php

namespace App\Contracts\Cobros;

use App\Models\Company;
use App\Models\User;

interface MaestroReferralCredentialProvider
{
    public function for(User $user, Company $company): ?string;
}
