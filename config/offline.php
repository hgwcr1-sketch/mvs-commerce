<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum Offline Hours
    |--------------------------------------------------------------------------
    |
    | Maximum number of hours a terminal authorization remains valid without
    | re-validation against the server. The server enforces this limit when
    | issuing authorizations; the client must not trust its own duration.
    |
    */

    'max_hours' => (int) env('MVS_OFFLINE_MAX_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Authorization Token Version
    |--------------------------------------------------------------------------
    |
    | Version identifier embedded in authorization tokens. Increment when
    | the token payload format changes to invalidate older tokens.
    |
    */

    'token_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Signature Algorithm
    |--------------------------------------------------------------------------
    |
    | Cryptographic algorithm used for offline authorization tokens.
    | Supported: 'RSA-SHA256' (RSA-2048 minimum + SHA-256).
    |
    | libsodium/Ed25519 is preferred but requires the sodium PHP extension.
    | When sodium becomes available, migrate to Ed25519 for smaller signatures
    | and faster verification.
    |
    */

    'signature_algorithm' => 'RSA-SHA256',

    /*
    |--------------------------------------------------------------------------
    | Key Storage Paths
    |--------------------------------------------------------------------------
    |
    | Paths to the RSA key pair for offline authorization signing.
    | Keys are stored under storage/app/private/mvs-offline/ by default.
    | Paths are relative to the Laravel storage_path().
    |
    */

    'keys' => [
        'private' => env('MVS_OFFLINE_PRIVATE_KEY_PATH', 'app/private/mvs-offline/private.pem'),
        'public' => env('MVS_OFFLINE_PUBLIC_KEY_PATH', 'app/private/mvs-offline/public.pem'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot Schema Version
    |--------------------------------------------------------------------------
    |
    | Version of the offline snapshot data structure. Increment when the
    | snapshot format changes to force clients to re-download.
    |
    */

    'snapshot_schema_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Snapshot Customer Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of customers to include in a snapshot. This prevents
    | excessively large snapshots for companies with thousands of customers.
    | V1 uses a reasonable default for POS operations.
    |
    */

    'snapshot_customer_limit' => 2000,

];
