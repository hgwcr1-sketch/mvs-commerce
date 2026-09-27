<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

class AbsoluteUrlPinningTest extends TestCase
{
    public function test_production_reset_url_ignores_malicious_host_and_uses_app_url(): void
    {
        config(['app.url' => 'https://app.pinned.example']);

        $this->app['url']->setRequest(Request::create('https://evil.example/reset', 'GET'));

        $unpinned = route('password.reset', ['token' => 'token-value', 'email' => 'user@example.test']);
        $this->assertStringStartsWith('https://evil.example/', $unpinned);

        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $resetUrl = route('password.reset', ['token' => 'token-value', 'email' => 'user@example.test']);

        $this->assertStringStartsWith('https://app.pinned.example/', $resetUrl);
        $this->assertStringNotContainsString('evil.example', $resetUrl);

        $loginUrl = route('password.request');
        $this->assertStringStartsWith('https://app.pinned.example/', $loginUrl);
        $this->assertStringNotContainsString('evil.example', $loginUrl);
    }

    public function test_testing_environment_keeps_request_based_urls_and_ignores_app_url_override(): void
    {
        config(['app.url' => 'https://should-not-be-used.example']);

        $this->app['url']->setRequest(Request::create('http://localhost/forgot-password', 'GET'));

        $resetUrl = route('password.reset', ['token' => 'token-value', 'email' => 'user@example.test']);

        $this->assertStringStartsWith('http://localhost/', $resetUrl);
        $this->assertStringNotContainsString('should-not-be-used.example', $resetUrl);
    }

    public function test_malicious_request_host_is_ignored_before_any_url_generation_in_production(): void
    {
        config(['app.url' => 'http://app.pinned.example']);

        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $this->app['url']->setRequest(Request::create('https://evil.example/reset', 'GET'));

        $resetUrl = route('password.reset', ['token' => 'token-value', 'email' => 'user@example.test']);

        $this->assertStringStartsWith('http://app.pinned.example/', $resetUrl);
        $this->assertStringNotContainsString('evil.example', $resetUrl);
    }
}
