<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MVS Maestro (B11B) — integración deshabilitada por defecto
    |--------------------------------------------------------------------------
    |
    | Cliente maestro local consumido vía Composer path desde
    | mvs-maestro/packages/maestro-client. Sin conexiones reales salvo que
    | se habilite explícitamente por entorno. Credenciales solo desde env.
    |
    */

    'enabled' => (bool) env('MAESTRO_ENABLED', false),

    'integration_kit' => [
        'enabled' => (bool) env('MAESTRO_INTEGRATION_KIT_ENABLED', false),
    ],

    'url' => env('MAESTRO_URL'),

    'token' => env('MAESTRO_TOKEN'),

    'event_secret' => env('MAESTRO_EVENT_SECRET'),

    'identity' => [
        'application_id' => env('MAESTRO_IDENTITY_APPLICATION_ID'),
        'installation_id' => env('MAESTRO_IDENTITY_INSTALLATION_ID'),
        'issuer' => env('MAESTRO_IDENTITY_ISSUER'),
        'kid' => env('MAESTRO_IDENTITY_KID'),
        'private_key' => env('MAESTRO_IDENTITY_PRIVATE_KEY'),
    ],

    'timeout' => (float) env('MAESTRO_TIMEOUT', 5.0),

    /*
    |--------------------------------------------------------------------------
    | Tarifas B11E — caché con fallback local
    |--------------------------------------------------------------------------
    |
    | El flujo de cobros NUNCA hace HTTP síncrono: solo lee caché o las
    | tarifas locales. La actualización contra Maestro es explícita
    | (refreshTariffs) y ante cualquier fallo conserva caché o local.
    |
    */

    'tariffs_cache_key' => env('MAESTRO_TARIFFS_CACHE_KEY', 'maestro.tariffs.cobros'),

    'tariffs_cache_ttl' => (int) env('MAESTRO_TARIFFS_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Países B13B — caché con fallback local
    |--------------------------------------------------------------------------
    |
    | El acceso y el registro NUNCA hacen HTTP síncrono en autenticación:
    | solo leen caché o el catálogo local. La lectura contra Maestro usa
    | timeout corto y ante cualquier fallo conserva el catálogo local.
    |
    */

    'countries_cache_key' => env('MAESTRO_COUNTRIES_CACHE_KEY', 'maestro.countries.cobros'),

    'countries_cache_ttl' => (int) env('MAESTRO_COUNTRIES_CACHE_TTL', 3600),

];
