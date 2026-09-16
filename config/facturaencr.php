<?php

return [

    'base_url' => env('FACTURAENCR_BASE_URL', 'https://api.facturaencr.com/v2/efactura'),

    'environment' => env('FACTURAENCR_ENVIRONMENT', 'sandbox'),

    'api_key' => env('FACTURAENCR_API_KEY', ''),

    'api_secret' => env('FACTURAENCR_API_SECRET', ''),

    'timeout' => (int) env('FACTURAENCR_TIMEOUT', 30),

];