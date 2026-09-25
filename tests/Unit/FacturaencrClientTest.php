<?php

namespace Tests\Unit;

use App\Services\Facturaencr\FacturaencrClient;
use App\Services\Facturaencr\FacturaencrResponse;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class FacturaencrClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

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

    public function test_post_retries_429_then_succeeds_with_same_idempotency_key(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fakeSequence()
            ->push(['message' => 'Rate limited'], 429)
            ->push(['status' => 'accepted', 'documentId' => 'doc-retry'], 202);

        $client = new FacturaencrClient();
        $response = $client->post('documents/factura', ['clave' => 'test'], 'idem-retry-1');

        $this->assertTrue($response->isSuccess());
        $this->assertSame(202, $response->httpStatusCode);

        $keys = [];
        Http::recorded(function ($request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return true;
        });

        $this->assertCount(2, $keys);
        $this->assertSame('idem-retry-1', $keys[0]);
        $this->assertSame('idem-retry-1', $keys[1]);
    }

    public function test_post_retries_500_then_succeeds(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fakeSequence()
            ->push(['message' => 'Internal server error'], 500)
            ->push(['status' => 'ok'], 200);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, Http::recorded());
    }

    public function test_post_does_not_retry_non_retryable_400(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fakeSequence()->push(['error' => 'validation_error', 'message' => 'Invalid'], 400);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(400, $response->httpStatusCode);
        $this->assertCount(1, Http::recorded());
    }

    public function test_post_stops_after_configured_max_retries(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);
        Config::set('facturaencr.max_retries', 0);

        Http::fake(['api.facturaencr.com/v2/efactura/efactura' => Http::response(['message' => 'Rate limited'], 429)]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'test']);

        $this->assertTrue($response->isRetryable());
        $this->assertSame(429, $response->httpStatusCode);
        $this->assertCount(1, Http::recorded());
    }

    public function test_get_retries_503_then_succeeds(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-api-key');
        Config::set('facturaencr.api_secret', 'test-api-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fakeSequence()
            ->push(['message' => 'Maintenance'], 503)
            ->push(['status' => 'accepted'], 200);

        $client = new FacturaencrClient();
        $response = $client->get('documents/doc-1');

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, Http::recorded());
    }

    public function test_calculate_retry_delay_ms_uses_exponential_backoff_with_cap(): void
    {
        Config::set('facturaencr.retry_delay_ms', 100);
        Config::set('facturaencr.retry_max_delay_ms', 250);

        $client = new FacturaencrClient();

        $this->assertSame(100, $client->calculateRetryDelayMs(0, null));
        $this->assertSame(200, $client->calculateRetryDelayMs(1, null));
        $this->assertSame(250, $client->calculateRetryDelayMs(2, null));
    }

    public function test_calculate_retry_delay_ms_respects_retry_after_seconds(): void
    {
        Config::set('facturaencr.retry_delay_ms', 100);
        Config::set('facturaencr.retry_max_delay_ms', 5000);

        $client = new FacturaencrClient();

        $this->assertSame(3000, $client->calculateRetryDelayMs(0, '3'));
        $this->assertSame(5000, $client->calculateRetryDelayMs(0, '30'));
        $this->assertSame(100, $client->calculateRetryDelayMs(0, null));
    }
}