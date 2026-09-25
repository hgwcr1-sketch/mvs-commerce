<?php

namespace App\Services\Facturaencr;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacturaencrClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = Config::get('facturaencr.base_url');
        $this->apiKey = Config::get('facturaencr.api_key');
        $this->apiSecret = Config::get('facturaencr.api_secret');
        $this->timeout = Config::get('facturaencr.timeout', 30);
    }

    public function post(string $endpoint, array $payload, string $idempotencyKey = ''): FacturaencrResponse
    {
        $url = $this->url($endpoint);

        $headers = [
            'X-API-Key' => $this->apiKey,
            'X-API-Secret' => $this->apiSecret,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->sendWithRetries($endpoint, function () use ($url, $headers, $payload) {
            return Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->post($url, $payload);
        });
    }

    public function get(string $endpoint, array $queryParams = []): FacturaencrResponse
    {
        $url = $this->url($endpoint);

        $headers = [
            'X-API-Key' => $this->apiKey,
            'X-API-Secret' => $this->apiSecret,
            'Accept' => 'application/json',
        ];

        return $this->sendWithRetries($endpoint, function () use ($url, $headers, $queryParams) {
            return Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->get($url, $queryParams);
        });
    }

    /**
     * Reintenta solo errores clasificados como reintentables (429, 5xx y
     * fallos de conexión), respetando Retry-After cuando viene en la
     * respuesta. La misma Idempotency-Key se reutiliza en cada intento.
     */
    private function sendWithRetries(string $endpoint, callable $attempt): FacturaencrResponse
    {
        $maxRetries = max(0, (int) Config::get('facturaencr.max_retries', 2));
        $attemptNumber = 0;

        while (true) {
            $retryAfter = null;

            try {
                $response = $attempt();
                $retryAfter = $response->header('Retry-After');
                $result = $this->buildResponse($response);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $result = new FacturaencrResponse(
                    status: 'error',
                    errorCode: 'CONNECTION_TIMEOUT',
                    errorMessage: $e->getMessage(),
                    retryable: true,
                    httpStatusCode: null,
                );
            } catch (\Illuminate\Http\Client\RequestException $e) {
                if ($e->response) {
                    $retryAfter = $e->response->header('Retry-After');
                    $result = $this->buildResponse($e->response);
                } else {
                    return new FacturaencrResponse(
                        status: 'error',
                        errorCode: 'REQUEST_FAILED',
                        errorMessage: $e->getMessage(),
                        retryable: true,
                        httpStatusCode: null,
                    );
                }
            } catch (\Throwable $e) {
                Log::error('FacturaencrClient: unexpected error', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return new FacturaencrResponse(
                    status: 'error',
                    errorCode: 'UNKNOWN_ERROR',
                    errorMessage: $e->getMessage(),
                    retryable: true,
                    httpStatusCode: null,
                );
            }

            if ($result->isSuccess() || !$result->isRetryable() || $attemptNumber >= $maxRetries) {
                return $result;
            }

            $delayMs = $this->calculateRetryDelayMs($attemptNumber, $retryAfter);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $attemptNumber++;
        }
    }

    /**
     * Backoff exponencial (base * 2^n) acotado por retry_max_delay_ms.
     * Si la respuesta trae Retry-After (segundos) se respeta ese valor,
     * también acotado por retry_max_delay_ms.
     */
    public function calculateRetryDelayMs(int $attemptNumber, ?string $retryAfter): int
    {
        $baseDelay = max(0, (int) Config::get('facturaencr.retry_delay_ms', 500));
        $maxDelay = max(0, (int) Config::get('facturaencr.retry_max_delay_ms', 5000));

        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
            return min((int) round((float) $retryAfter * 1000), $maxDelay);
        }

        return min($baseDelay * (2 ** max(0, $attemptNumber)), $maxDelay);
    }

    private function buildResponse(\Illuminate\Http\Client\Response $response): FacturaencrResponse
    {
        $statusCode = $response->status();
        $body = $response->json();

        if ($statusCode >= 200 && $statusCode < 300) {
            return new FacturaencrResponse(
                status: 'success',
                data: $body,
                httpStatusCode: $statusCode,
                retryable: false,
            );
        }

        $errorCode = $body['error'] ?? 'HTTP_' . $statusCode;
        $errorMessage = $body['message'] ?? $body['error'] ?? 'Unknown error';

        $classification = $this->classifyError($statusCode);

        return new FacturaencrResponse(
            status: 'error',
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            retryable: $classification['retryable'],
            httpStatusCode: $statusCode,
            data: $body,
        );
    }

    private function classifyError(int $statusCode): array
    {
        return match (true) {
            $statusCode === 400 => ['retryable' => false, 'category' => 'validation'],
            $statusCode === 401 => ['retryable' => false, 'category' => 'authentication'],
            $statusCode === 403 => ['retryable' => false, 'category' => 'authorization'],
            $statusCode === 409 => ['retryable' => false, 'category' => 'conflict'],
            $statusCode === 422 => ['retryable' => false, 'category' => 'validation'],
            $statusCode === 429 => ['retryable' => true, 'category' => 'rate_limit'],
            $statusCode >= 500 => ['retryable' => true, 'category' => 'server_error'],
            default => ['retryable' => false, 'category' => 'unknown'],
        };
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
