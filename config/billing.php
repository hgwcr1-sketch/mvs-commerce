<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedores de pago registrados
    |--------------------------------------------------------------------------
    |
    | Cada proveedor tiene una clase adaptadora que implementa
    | PaymentProviderInterface. Los no integrados responden isAvailable()
    | false y lanzan PaymentProviderUnavailableException.
    |
    */

    'providers' => [
        'stripe' => App\Services\Billing\Adapters\StripePaymentProvider::class,
        'tilopay' => App\Services\Billing\Adapters\TilopayPaymentProvider::class,
        'fygaro' => App\Services\Billing\Adapters\FygaroPaymentProvider::class,
        'sinpe' => App\Services\Billing\Adapters\SinpePaymentProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración Stripe
    |--------------------------------------------------------------------------
    |
    | Modo: test | live
    | Claves separadas por entorno para evitar mezclas accidentales.
    | Se leen desde variables de entorno para no guardar secretos en repo.
    |
    */

    'stripe' => [
        'mode' => env('STRIPE_MODE', 'test'),
        'secret_key_test' => env('STRIPE_TEST_SECRET_KEY'),
        'secret_key_live' => env('STRIPE_LIVE_SECRET_KEY'),
        'publishable_key_test' => env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'publishable_key_live' => env('STRIPE_LIVE_PUBLISHABLE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proveedor por defecto por producto y país
    |--------------------------------------------------------------------------
    |
    | Permite reemplazar Stripe por Tilopay/Fygaro por país sin tocar
    | código: solo se ajusta esta configuración. Un producto sin entrada
    | usará el primer proveedor disponible o fallará explícitamente.
    |
    */

    'product_providers' => [
        // 'cobros' => ['CR' => 'stripe', 'CO' => 'stripe'],
    ],

];
