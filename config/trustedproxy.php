<?php

/*
|--------------------------------------------------------------------------
| Proxies de confianza (TRUSTED_PROXIES)
|--------------------------------------------------------------------------
|
| IPs o rangos CIDR del proxy reverso (nginx, balanceador, CDN) desde los
| cuales se aceptan las cabeceras X-Forwarded-For/Proto/Host/Port.
|
| - Se configura en .env como lista separada por comas.
| - Lista vacía = ningún proxy confiable: las cabeceras X-Forwarded-* de
|   cualquier cliente se ignoran.
| - No se admite '*' ni '**' (confianza global): solo proxies explícitos.
|
*/

$entries = explode(',', (string) env('TRUSTED_PROXIES', ''));

$proxies = array_values(array_filter(
    array_map(trim(...), $entries),
    static fn (string $entry): bool => $entry !== '' && $entry !== '*' && $entry !== '**',
));

return [
    'proxies' => $proxies,
];
