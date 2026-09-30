<?php

return [

    'provider' => env('FISCAL_PROVIDER', 'facturaencr'),

    'providers' => [
        'facturaencr' => \App\Services\Facturaencr\FacturaencrProvider::class,
    ],

    'cabys_catalog' => \App\Services\Fiscal\LocalCabysCatalog::class,

    'emission' => [
        'auto_emit' => filter_var(env('FISCAL_EMISSION_AUTO_EMIT', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
