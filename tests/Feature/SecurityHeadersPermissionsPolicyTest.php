<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersPermissionsPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_policy_allows_same_origin_geolocation_without_weakening_other_policies(): void
    {
        $response = $this->get('/login');
        $response->assertOk();

        $policy = $response->headers->get('Permissions-Policy');
        $this->assertNotNull($policy);
        $this->assertStringContainsString('camera=(self)', $policy);
        $this->assertStringContainsString('geolocation=(self)', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
        $this->assertStringNotContainsString('geolocation=()', $policy);

        // Resto de caberas de AddSecurityHeaders intactas.
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }
}
