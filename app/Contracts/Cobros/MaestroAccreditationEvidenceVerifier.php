<?php

namespace App\Contracts\Cobros;

interface MaestroAccreditationEvidenceVerifier
{
    public function verify(array $evidence): ?array;
}
