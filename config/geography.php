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

    /*
    |--------------------------------------------------------------------------
    | Archivos de catálogo por país (importación reutilizable, 15B-1)
    |--------------------------------------------------------------------------
    | Rutas relativas a database_path(). Cada archivo: nivel jerárquico,
    | delimitador y columnas (código, nombre, padre). Se importa en orden.
    | NI, PA, GT, SV, HN, MX, DO y VE: importación preparada, fuentes
    | pendientes de verificación (sin datos inventados).
    */

    'catalog_files' => [
        'CR' => [
            ['file' => 'catalogs/provinces.csv', 'level' => 1, 'delimiter' => ',', 'columns' => ['code' => 0, 'name' => 1]],
            ['file' => 'catalogs/cantones.csv', 'level' => 2, 'delimiter' => "\t", 'columns' => ['code' => 2, 'name' => 3, 'parent' => 0]],
            ['file' => 'catalogs/distritos.csv', 'level' => 3, 'delimiter' => "\t", 'columns' => ['code' => 4, 'name' => 5, 'parent' => 2]],
        ],
        'CO' => [
            ['file' => 'catalogs/col_departamentos.csv', 'level' => 1, 'delimiter' => ',', 'columns' => ['code' => 0, 'name' => 1]],
            ['file' => 'catalogs/col_municipios.csv', 'level' => 2, 'delimiter' => ',', 'columns' => ['code' => 2, 'name' => 3, 'parent' => 0]],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fuente, versión y fecha de cada catálogo incorporado
    |--------------------------------------------------------------------------
    */

    'catalog_sources' => [
        'CR' => [
            'source' => 'Repositorio MVS database/catalogs (divisiones territoriales CR)',
            'source_url' => null,
            'version' => 'sin-version-declarada',
            'source_date' => null,
            'notes' => 'Archivos UTF-8 del repositorio con la estructura oficial de códigos INEC (provincia/cantón/distrito). Sin fecha de corte declarada en origen.',
        ],
        'CO' => [
            'source' => 'DANE vía datos.gov.co — DIVIPOLA',
            'source_url' => 'https://www.datos.gov.co/resource/gdxc-w37w',
            'version' => 'corte 30-diciembre-2024',
            'source_date' => '2024-12-30',
            'notes' => 'Datasets oficiales DIVIPOLA: departamentos vcjz-niiq y municipios gdxc-w37w (descarga directa, snapshot en database/catalogs).',
        ],
    ],

];
