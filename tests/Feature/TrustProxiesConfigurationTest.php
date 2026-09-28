<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustProxiesConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_with_forwarded_proto_https_is_secure(): void
    {
        $request = Request::create('https://rebound-fotos-see-visibility.trycloudflare.com/test', 'GET');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('X-Forwarded-Host', 'rebound-fotos-see-visibility.trycloudflare.com');
        $request->setTrustedProxies(
            ['*'],
            Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT,
        );

        $this->assertTrue($request->isSecure(), 'Request with X-Forwarded-Proto: https must be detected as secure');
        $this->assertSame('https', $request->getScheme(), 'Scheme must be https when X-Forwarded-Proto is https');
    }

    public function test_url_generation_uses_https_behind_proxy(): void
    {
        $request = Request::create('https://rebound-fotos-see-visibility.trycloudflare.com/pos', 'GET');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('X-Forwarded-Host', 'rebound-fotos-see-visibility.trycloudflare.com');
        $request->setTrustedProxies(
            ['*'],
            Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT,
        );

        $this->assertTrue($request->isSecure());
        $this->assertSame('https://rebound-fotos-see-visibility.trycloudflare.com/pos', $request->fullUrl());
    }

    public function test_build_assets_would_be_https_behind_proxy(): void
    {
        $request = Request::create('https://rebound-fotos-see-visibility.trycloudflare.com/', 'GET');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('X-Forwarded-Host', 'rebound-fotos-see-visibility.trycloudflare.com');
        $request->setTrustedProxies(
            ['*'],
            Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT,
        );

        $this->assertTrue($request->isSecure());
        $baseUrl = $request->getScheme().'://'.$request->getHost();
        $this->assertSame('https://rebound-fotos-see-visibility.trycloudflare.com', $baseUrl);
    }
}
