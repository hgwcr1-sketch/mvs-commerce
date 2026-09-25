<?php

return [

    'provider' => env('FISCAL_PROVIDER', 'facturaencr'),

    'providers' => [
        'facturaencr' => \App\Services\Facturaencr\FacturaencrProvider::class,
    ],

    'cabys_catalog' => \App\Services\Fiscal\LocalCabysCatalog::class,

];
