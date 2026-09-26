<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustProxiesConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_untrusted_client_cannot_spoof_forwarded_headers(): void
    {
        $this->registerProbeRoute();

        $response = $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
        ])->getJson('/__trusted-proxy-probe');

        $response->assertOk();

        $this->assertSame('127.0.0.1', $response->json('ip'), 'IP must come from REMOTE_ADDR when no proxy is trusted');
        $this->assertFalse($response->json('secure'), 'X-Forwarded-Proto must be ignored from untrusted clients');
        $this->assertSame('localhost', $response->json('host'), 'X-Forwarded-Host must be ignored from untrusted clients');
    }

    public function test_explicitly_configured_proxy_is_honoured(): void
    {
        $this->registerProbeRoute();
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $response = $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'app.mvscommerce.com',
        ])->getJson('/__trusted-proxy-probe');

        $response->assertOk();

        $this->assertSame('203.0.113.9', $response->json('ip'), 'Configured proxy must be able to forward client IP');
        $this->assertTrue($response->json('secure'), 'Configured proxy must be able to forward X-Forwarded-Proto');
        $this->assertSame('app.mvscommerce.com', $response->json('host'), 'Configured proxy must be able to forward X-Forwarded-Host');
    }

    public function test_trusted_proxies_default_configuration_is_an_empty_list(): void
    {
        $this->assertEmpty(config('trustedproxy.proxies'), 'Default configuration must trust no proxies');
    }

    public function test_trusted_proxies_env_is_parsed_as_an_explicit_list_without_global_wildcard(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1, 172.16.0.0/12 ,*';
        $_SERVER['TRUSTED_PROXIES'] = '10.0.0.1, 172.16.0.0/12 ,*';

        try {
            $parsed = require base_path('config/trustedproxy.php');
        } finally {
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }

        $this->assertSame(['10.0.0.1', '172.16.0.0/12'], $parsed['proxies']);
    }

    private function registerProbeRoute(): void
    {
        Route::get('/__trusted-proxy-probe', static function (Request $request) {
            return response()->json([
                'ip' => $request->ip(),
                'secure' => $request->isSecure(),
                'host' => $request->getHost(),
            ]);
        });
    }
}
