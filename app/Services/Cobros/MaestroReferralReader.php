<?php

namespace App\Services\Cobros;

use App\Models\Company;
use App\Models\User;
use App\Contracts\Cobros\MaestroReferralCredentialProvider;
use Mvs\MaestroClient\MaestroPlatformClient;

/** Read-only boundary for Maestro Referrals v1. Disabled until secure auth is configured. */
class MaestroReferralReader
{
    public function __construct(private readonly MaestroReferralCredentialProvider $credentials) {}

    public function read(User $user, Company $company): array
    {
        if (! (bool) config('maestro.referrals.enabled', false)) {
            return ['available' => false, 'stale' => false, 'link' => null, 'referrals' => [], 'credit' => null];
        }

        $jws = $this->credentials->for($user, $company);
        if ($jws === null || trim($jws) === '') {
            return ['available' => false, 'stale' => false, 'link' => null, 'referrals' => [], 'credit' => null];
        }

        $client = app(MaestroPlatformClient::class);
        $binding = $client->referralBinding($jws);
        if (! ($binding['ok'] ?? false)) {
            return ['available' => false, 'stale' => false, 'link' => null, 'referrals' => [], 'credit' => null];
        }

        $data = is_array($binding['data'] ?? null) ? $binding['data'] : [];
        return [
            'available' => true,
            'stale' => false,
            'link' => $data['link'] ?? null,
            'referrals' => is_array($data['referrals'] ?? null) ? $data['referrals'] : [],
            'credit' => $data['credit'] ?? null,
        ];
    }
}
