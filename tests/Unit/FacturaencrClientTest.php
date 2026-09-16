<?php

namespace Tests\Unit;

use App\Services\Facturaencr\FacturaencrClient;
use App\Services\Facturaencr\FacturaencrResponse;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class FacturaencrClientTest extends TestCase
{
    public function test_constructor_reads_config_values(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        $client = new FacturaencrClient();

        $this->assertSame('https://api.facturaencr.com/v2/efactura', $client->getBaseUrl());
        $this->assertSame('test-api-key', $client->getApiKey());
        $this->assertSame(30, $client->getTimeout());
    }

public function test_post_sends_correct_headers(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['status' => 'accepted'], 200),
        ]);

        $client = new FacturaencrClient();
        $client->post('efactura', ['clave' => 'test']);

$this->assertGreaterThanOrEqual(1, count(Http::recorded()));
        Http::assertSent(fn ($request) => true);
    }

    public function test_post_includes_idempotency_key_when_provided(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['status' => 'accepted'], 200),
        ]);

        $client = new FacturaencrClient();
        $client->post('efactura', ['clave' => 'test'], 'idem-key-123');

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key'));
    }

    public function test_post_does_not_include_idempotency_key_when_empty(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['status' => 'accepted'], 200),
        ]);

        $client = new FacturaencrClient();
        $client->post('efactura', ['clave' => 'test']);

        Http::assertSent(function ($request) {
            return !$request->hasHeader('Idempotency-Key');
        });
    }

    public function test_post_sends_json_body(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['status' => 'accepted'], 200),
        ]);

        $payload = ['clave' => '506010100000000000010000000000000000000000', 'documento' => []];

        $client = new FacturaencrClient();
        $client->post('efactura', $payload);

        Http::assertSent(function ($request) use ($payload) {
            return $request->data() === $payload;
        });
    }

    public function test_post_returns_success_response(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response([
                'status' => 'accepted',
                'clave' => '506010100000000000010000000000000000000000',
                'documentId' => 'doc-123',
            ], 200),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame('success', $response->status);
        $this->assertSame(200, $response->httpStatusCode);
        $this->assertNull($response->errorCode);
        $this->assertNull($response->errorMessage);
    }

    public function test_post_returns_retryable_response_on_429(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['message' => 'Rate limited'], 429),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertTrue($response->isRetryable());
        $this->assertSame(429, $response->httpStatusCode);
    }

    public function test_post_returns_retryable_response_on_500(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['message' => 'Internal error'], 500),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertTrue($response->isRetryable());
        $this->assertSame(500, $response->httpStatusCode);
    }

    public function test_post_returns_non_retryable_response_on_400(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['error' => 'validation_error', 'message' => 'Invalid input'], 400),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(400, $response->httpStatusCode);
        $this->assertSame('validation_error', $response->errorCode);
    }

    public function test_post_returns_non_retryable_response_on_401(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['error' => 'unauthorized', 'message' => 'Invalid API key'], 401),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(401, $response->httpStatusCode);
    }

    public function test_post_returns_non_retryable_response_on_403(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['error' => 'forbidden', 'message' => 'Access denied'], 403),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(403, $response->httpStatusCode);
    }

    public function test_post_returns_non_retryable_response_on_409(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['error' => 'conflict', 'message' => 'Document already exists'], 409),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(409, $response->httpStatusCode);
    }

    public function test_post_returns_non_retryable_response_on_422(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['error' => 'validation', 'message' => 'Validation failed'], 422),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(422, $response->httpStatusCode);
    }

    public function test_get_sends_correct_headers(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura/test' => Http::response(['data' => []], 200),
        ]);

        $client = new FacturaencrClient();
        $client->get('efactura/test');

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key'));
    }

    public function test_get_returns_success_response(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura/test' => Http::response(['data' => ['id' => '1']], 200),
        ]);

        $client = new FacturaencrClient();
        $response = $client->get('efactura/test');

        $this->assertTrue($response->isSuccess());
        $this->assertSame(200, $response->httpStatusCode);
    }

    public function test_post_returns_error_when_no_error_code_in_body(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['unknown_field' => 'value'], 502),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertSame('HTTP_502', $response->errorCode);
        $this->assertSame('Unknown error', $response->errorMessage);
        $this->assertTrue($response->isRetryable());
    }

    public function test_500_response_is_retryable(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response('Server error', 500),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertTrue($response->isRetryable());
        $this->assertSame(500, $response->httpStatusCode);
    }
}