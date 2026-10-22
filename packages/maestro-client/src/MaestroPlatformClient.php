<?php

namespace Mvs\MaestroClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class MaestroPlatformClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly ?string $eventSecret = null,
        private readonly float $timeout = 5.0,
    ) {
        if (trim($this->baseUrl) === '' || trim($this->token) === '') {
            throw new \InvalidArgumentException('Maestro: base URL y token requeridos.');
        }
    }

    public function referralBinding(string $userJws): array
    {
        return $this->sendReferral('/api/v1/referrals/me/binding', $userJws);
    }

    public function identityAccreditation(string $userJws): array
    {
        return $this->sendReferral('/api/v1/identity/accreditation', $userJws);
    }

    private function sendReferral(string $path, string $userJws): array
    {
        if (trim($userJws) === '') throw new \InvalidArgumentException('Maestro: JWS requerido.');
        try {
            $response = Http::timeout($this->timeout)->withToken($this->token)
                ->withHeaders(['X-Maestro-Authorization' => $userJws])->acceptJson()
                ->get(rtrim($this->baseUrl, '/').$path);
            return ['ok' => $response->successful(), 'status' => $response->status(), 'data' => $response->json(), 'error' => $response->successful() ? null : 'HTTP '.$response->status()];
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Sin conexión con MVS Maestro.'];
        } catch (Throwable) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Error inesperado al contactar MVS Maestro.'];
        }
    }
}
