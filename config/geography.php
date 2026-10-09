<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Países soportados y niveles territoriales configurables
    |--------------------------------------------------------------------------
    | Arquitectura geográfica internacional. La cantidad de niveles por país
    | es variable: no existe profundidad obligatoria de tres niveles. CR es el
    | país predeterminado mientras la plataforma opere de forma centralizada.
    */

    'default_country' => 'CR',

    'countries' => [
        'CR' => [
            'name' => 'Costa Rica',
            'iso3' => 'CRI',
            'phone_code' => '+506',
            'currency' => 'CRC',
            'currency_symbol' => '₡',
            'levels' => [
                ['level' => 1, 'name' => 'Provincia', 'parent_level' => null],
                ['level' => 2, 'name' => 'Cantón', 'parent_level' => 1],
                ['level' => 3, 'name' => 'Distrito', 'parent_level' => 2],
            ],
        ],
        'NI' => [
            'name' => 'Nicaragua',
            'iso3' => 'NIC',
            'phone_code' => '+505',
            'currency' => 'NIO',
            'currency_symbol' => 'C$',
            'levels' => [
                ['level' => 1, 'name' => 'Departamento', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
            ],
        ],
        'PA' => [
            'name' => 'Panamá',
            'iso3' => 'PAN',
            'phone_code' => '+507',
            'currency' => 'PAB',
            'currency_symbol' => 'B/.',
            'levels' => [
                ['level' => 1, 'name' => 'Provincia', 'parent_level' => null],
                ['level' => 2, 'name' => 'Distrito', 'parent_level' => 1],
            ],
        ],
        'GT' => [
            'name' => 'Guatemala',
            'iso3' => 'GTM',
            'phone_code' => '+502',
            'currency' => 'GTQ',
            'currency_symbol' => 'Q',
            'levels' => [
                ['level' => 1, 'name' => 'Departamento', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
            ],
        ],
        'SV' => [
            'name' => 'El Salvador',
            'iso3' => 'SLV',
            'phone_code' => '+503',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'levels' => [
                ['level' => 1, 'name' => 'Departamento', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
            ],
        ],
        'HN' => [
            'name' => 'Honduras',
            'iso3' => 'HND',
            'phone_code' => '+504',
            'currency' => 'HNL',
            'currency_symbol' => 'L',
            'levels' => [
                ['level' => 1, 'name' => 'Departamento', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
            ],
        ],
        'CO' => [
            'name' => 'Colombia',
            'iso3' => 'COL',
            'phone_code' => '+57',
            'currency' => 'COP',
            'currency_symbol' => '$',
            'levels' => [
                ['level' => 1, 'name' => 'Departamento', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
                ['level' => 3, 'name' => 'Corregimiento', 'parent_level' => 2],
            ],
        ],
        'MX' => [
            'name' => 'México',
            'iso3' => 'MEX',
            'phone_code' => '+52',
            'currency' => 'MXN',
            'currency_symbol' => '$',
            'levels' => [
                ['level' => 1, 'name' => 'Estado', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
            ],
        ],
        'DO' => [
            'name' => 'República Dominicana',
            'iso3' => 'DOM',
            'phone_code' => '+1',
            'currency' => 'DOP',
            'currency_symbol' => 'RD$',
            'levels' => [
                ['level' => 1, 'name' => 'Provincia', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
            ],
        ],
        'VE' => [
            'name' => 'Venezuela',
            'iso3' => 'VEN',
            'phone_code' => '+58',
            'currency' => 'VES',
            'currency_symbol' => 'Bs.',
            'levels' => [
                ['level' => 1, 'name' => 'Estado', 'parent_level' => null],
                ['level' => 2, 'name' => 'Municipio', 'parent_level' => 1],
                ['level' => 3, 'name' => 'Parroquia', 'parent_level' => 2],
            ],
        ],
    ],

];
