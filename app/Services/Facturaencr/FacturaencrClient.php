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
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $headers = [
            'X-API-Key' => $this->apiKey,
            'X-API-Secret' => $this->apiSecret,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->retry(0)
                ->withHeaders($headers)
                ->post($url, $payload);

            return $this->buildResponse($response);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new FacturaencrResponse(
                status: 'error',
                errorCode: 'CONNECTION_TIMEOUT',
                errorMessage: $e->getMessage(),
                retryable: true,
                httpStatusCode: null,
            );
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $response = $e->response;
            if ($response) {
                return $this->buildResponse($response);
            }

            return new FacturaencrResponse(
                status: 'error',
                errorCode: 'REQUEST_FAILED',
                errorMessage: $e->getMessage(),
                retryable: true,
                httpStatusCode: null,
            );
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
    }

    public function get(string $endpoint, array $queryParams = []): FacturaencrResponse
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $headers = [
            'X-API-Key' => $this->apiKey,
            'X-API-Secret' => $this->apiSecret,
            'Accept' => 'application/json',
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->retry(0)
                ->withHeaders($headers)
                ->get($url, $queryParams);

            return $this->buildResponse($response);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new FacturaencrResponse(
                status: 'error',
                errorCode: 'CONNECTION_TIMEOUT',
                errorMessage: $e->getMessage(),
                retryable: true,
                httpStatusCode: null,
            );
        } catch (\Throwable $e) {
            Log::error('FacturaencrClient: unexpected GET error', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return new FacturaencrResponse(
                status: 'error',
                errorCode: 'UNKNOWN_ERROR',
                errorMessage: $e->getMessage(),
                retryable: true,
                httpStatusCode: null,
            );
        }
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