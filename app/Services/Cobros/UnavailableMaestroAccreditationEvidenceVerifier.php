<?php

namespace App\Services\Cobros;

use App\Contracts\Cobros\MaestroAccreditationEvidenceVerifier;

final class UnavailableMaestroAccreditationEvidenceVerifier implements MaestroAccreditationEvidenceVerifier
{
    public function verify(array $evidence): ?array
    {
        return null;
    }
}
