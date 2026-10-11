<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cobros\CobrosDemoSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CobrosDemoOperationalSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        Route::middleware(['web', 'auth'])->get('/demo-session-probe', fn () => response('allowed'));
    }

    private function loginDemo(): array
    {
        $demo = app(CobrosDemoSessionService::class)->start();
        $this->actingAs($demo['session']->owner)->withSession([
            'cobros.demo_token' => $demo['token'],
            'active_company_id' => $demo['session']->company_id,
        ]);
        return $demo;
    }

    public function test_valid_then_expired_session_is_blocked_on_subsequent_request(): void
    {
        $demo = $this->loginDemo();
        $this->get('/demo-session-probe')->assertOk();
        $demo['session']->update(['expires_at' => now()->subSecond()]);
        $this->get('/demo-session-probe')->assertForbidden();
        $this->assertGuest();
    }

    public function test_revoked_session_is_blocked(): void
    {
        $demo = $this->loginDemo();
        $demo['session']->delete();
        $this->get('/demo-session-probe')->assertForbidden();
        $this->assertGuest();
    }

    public function test_foreign_company_and_foreign_token_are_blocked(): void
    {
        $other = app(CobrosDemoSessionService::class)->start();
        $this->loginDemo();
        $this->withSession(['active_company_id' => $other['session']->company_id])
            ->get('/demo-session-probe')->assertForbidden();
        $this->loginDemo();
        $this->withSession(['cobros.demo_token' => $other['token']])
            ->get('/demo-session-probe')->assertForbidden();
    }

    public function test_missing_token_and_disabled_company_are_blocked(): void
    {
        $this->loginDemo();
        $this->withSession(['cobros.demo_token' => null])
            ->get('/demo-session-probe')->assertForbidden();
        $demo = $this->loginDemo();
        $demo['session']->company->update(['is_active' => false]);
        $this->get('/demo-session-probe')->assertForbidden();
    }

    public function test_real_user_with_stale_demo_token_is_unaffected(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['cobros.demo_token' => 'expired'])
            ->get('/demo-session-probe')->assertOk();
        $this->assertAuthenticated();
    }
}