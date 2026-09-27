<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BaselineCspTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_CSP = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; worker-src 'self' blob:";

    private const FORBIDDEN_DIRECTIVES = ['script-src', 'style-src', 'connect-src', 'unsafe-inline', 'unsafe-eval'];

    public function test_login_sends_baseline_csp_without_dangerous_directives(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $this->assertBaselineCsp($response);
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_pos_entry_sends_baseline_csp(): void
    {
        $response = $this->get(route('pos.index'));

        $this->assertBaselineCsp($response);
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_customer_portal_login_sends_baseline_csp(): void
    {
        $company = Company::create([
            'trade_name' => 'Empresa CSP',
            'legal_name' => 'Empresa CSP',
            'identification_number' => uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $response = $this->get(route('loyalty.customer.login', $company));

        $response->assertOk();
        $this->assertBaselineCsp($response);
    }

    private function assertBaselineCsp(TestResponse $response): void
    {
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertSame(self::EXPECTED_CSP, $csp);

        foreach (self::FORBIDDEN_DIRECTIVES as $directive) {
            $this->assertStringNotContainsString($directive, $csp);
        }
    }
}
