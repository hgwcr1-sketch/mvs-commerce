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
