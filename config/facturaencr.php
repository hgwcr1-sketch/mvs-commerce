<?php

return [

    'base_url' => env('FACTURAENCR_BASE_URL', 'https://api.facturaencr.com/v2/efactura'),

    'environment' => env('FACTURAENCR_ENVIRONMENT', 'sandbox'),

    'api_key' => env('FACTURAENCR_API_KEY', ''),

    'api_secret' => env('FACTURAENCR_API_SECRET', ''),

    'timeout' => (int) env('FACTURAENCR_TIMEOUT', 30),

    'max_retries' => (int) env('FACTURAENCR_MAX_RETRIES', 2),

    'retry_delay_ms' => (int) env('FACTURAENCR_RETRY_DELAY_MS', 500),

    'retry_max_delay_ms' => (int) env('FACTURAENCR_RETRY_MAX_DELAY_MS', 5000),

    'sandbox_emisor' => env('FACTURAENCR_SANDBOX_EMISOR', 'EMISORPRUEBA'),

];