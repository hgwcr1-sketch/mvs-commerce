<?php

use App\Services\Fiscal\Cabys\LocalCabysCatalog;
use App\Services\Fiscal\Emission\MvsHaciendaProvider;
use App\Services\Fiscal\Simulation\MvsSandboxProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor fiscal por defecto
    |--------------------------------------------------------------------------
    | La Oleada 1 solo entrega contratos, DTOs, manager y catálogo.
    | No hay proveedor de emisión conectado: hasta que exista uno
    | registrado, FiscalManager::provider() lanza una excepción clara.
    */

    'provider' => env('FISCAL_PROVIDER', 'facturaencr'),

    /*
    |--------------------------------------------------------------------------
    | Identidad del comprobante (MF06-A)
    |--------------------------------------------------------------------------
    | Condición de venta y medio de pago por defecto al emitir. Son
    | parámetros comerciales, no fiscales: se quedan en configuración para
    | que el XML sea determinista sin inventarlos en cada emisión.
    */

    'identity' => [
        'condicion_venta' => env('FISCAL_CONDICION_VENTA', '01'),
        'medio_pago' => env('FISCAL_MEDIO_PAGO', '01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Esquema XSD oficial (MF06-C)
    |--------------------------------------------------------------------------
    | El XSD se versiona en resources/xsd/ junto a su huella: la validación
    | ocurre ANTES de cualquier HTTP. Si el archivo no existe, no se emite.
    */

    'xsd' => [
        'version' => env('FISCAL_XSD_VERSION', '4.4'),
        'invoice_path' => resource_path('xsd/ComprobanteElectronico/xsd/FacturaElectronica_V4.4.xsd'),
        'xmldsig_path' => resource_path('xsd/xmldsig-core-schema.xsd'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firma XML-DSig (MF06-D)
    |--------------------------------------------------------------------------
    | Solo material almacenado por la empresa (clave privada + certificado
    | vigentes, segregados por entorno). No hay passphrases en configuración.
    */

    'signing' => [
        'digest_algorithm' => 'sha256',
        'signature_algorithm' => 'rsa-sha256',
        'canonicalization' => 'exclusive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Envío directo de comprobantes (MF06-E/F/G)
    |--------------------------------------------------------------------------
    |
    | Rutas y flujo CONFIRMADOS contra la documentación oficial
    | (ver docs/fiscal/PRE_MF07_GATE_OFICIAL.md):
    |
    |  - Recepción:  POST {base_url}/recepcion/v1/recepcion  y
    |                GET  {base_url}/recepcion/v1/recepcion/{clave}
    |                El cuerpo es JSON; el XML viaja en Base64 dentro de
    |                `comprobanteXml` (NO se envía el XML crudo).
    |  - Autenticación: OAuth 2.0 / OIDC del IdP de Comprobantes
    |    Electrónicos, grant_type=password (Resource Owner Password
    |    Credentials) contra {token_url}; el token viaja como
    |    `Authorization: bearer <access_token>`.
    |
    | IMPORTANTE: el host y el TOKEN_URL de PRODUCCIÓN no están publicados en
    | la guía oficial (solo el realm sandbox `rut-stag`), por eso no se
    | inventan: se configuran por entorno. Sandbox tampoco lleva valor por
    | defecto para `base_url` porque el API oficial documenta UN solo host;
    | apuntar sandbox a producción por omisión sería un error fiscal.
    |
    | 'environments' separa sandbox de producción de forma ESTRICTA: el
    | cliente rechaza cualquier entorno distinto de las dos claves y jamás
    | mezcla base_url o token_url de un entorno con otro.
    |
    | 'credentials' nombra las credenciales por empresa que se leen con
    | FiscalCredentialResolver (client_id / username / password). Este archivo
    | no contiene secretos: solo nombres de llave.
    */

    'submission' => [
        'provider' => env('FISCAL_SUBMISSION_PROVIDER', 'mvs_hacienda'),

        'proveedor_sistemas' => env('FISCAL_PROVEEDOR_SISTEMAS', ''),

        'timeout_seconds' => (int) env('FISCAL_SUBMISSION_TIMEOUT', 15),

        'credentials' => [
            'client_id' => 'client_id',
            'username' => 'username',
            'password' => 'password',
        ],

        'environments' => [
            'production' => [
                'base_url' => env('FISCAL_SUBMISSION_PRODUCTION_URL', 'https://api.comprobanteselectronicos.go.cr'),
                'token_url' => env('FISCAL_SUBMISSION_PRODUCTION_TOKEN_URL', ''),
                'send_path' => '/recepcion/v1/recepcion',
                'status_path' => '/recepcion/v1/recepcion',
            ],

            'sandbox' => [
                'base_url' => env('FISCAL_SUBMISSION_SANDBOX_URL', ''),
                'token_url' => env('FISCAL_SUBMISSION_SANDBOX_TOKEN_URL', 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token'),
                'send_path' => '/recepcion/v1/recepcion',
                'status_path' => '/recepcion/v1/recepcion',
            ],

            /*
             * MVS FISCAL — SANDBOX: simulación interna. Sin endpoints a
             * propósito: no hay red, ni credenciales, ni token. Cualquier
             * intento de envío real en este ambiente debe fallar.
             */
            'mvs_sandbox' => [
                'base_url' => '',
                'token_url' => '',
                'send_path' => '',
                'status_path' => '',
                'is_simulation' => true,
                'hacienda_submitted' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MVS FISCAL — SANDBOX (simulación interna)
    |--------------------------------------------------------------------------
    | Escenario por defecto de la simulación: processing → accepted.
    */
    'simulation' => [
        'scenario' => env('FISCAL_MVS_SANDBOX_SCENARIO', 'processing_accepted'),
        'marks' => [
            'MVS FISCAL — SANDBOX',
            'DOCUMENTO DE PRUEBA',
            'NO VÁLIDO FISCALMENTE',
            'NO ENVIADO A HACIENDA',
        ],
    ],

    'providers' => [
        'mvs_hacienda' => MvsHaciendaProvider::class,
        'mvs_sandbox' => MvsSandboxProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo CABYS (MF04)
    |--------------------------------------------------------------------------
    | El catálogo LOCAL es la única fuente de lectura: el POS valida un código
    | en cada venta y eso no puede depender de una API que devuelve 500 en
    | consultas válidas. `LocalCabysCatalog` no tiene red.
    |
    | El endpoint oficial (`/fe/cabys`) se usa SOLO para verificación puntual
    | y su respuesta NO se cachea: llega con `Cache-Control: no-store`.
    |
    | Su cubo de rate limit es PROPIO y no comparte presupuesto con el de
    | contribuyentes (`hacienda-fe-ae-global`): un catálogo voluminoso no
    | puede agotar el presupuesto que sí se usa en ventas.
    |
    | `fiscal.cabys.cache_store` en null usa el store por defecto de la app.
    */

    'cabys_catalog' => env('FISCAL_CABYS_CATALOG', LocalCabysCatalog::class),

    'cabys' => [
        /** Caché de resultados de búsqueda. 0 desactiva la caché por completo. */
        'search_cache_seconds' => (int) env('FISCAL_CABYS_SEARCH_CACHE', 900),

        'cache_store' => env('FISCAL_CABYS_CACHE_STORE'),

        'rate_limit' => [
            'max_attempts' => (int) env('FISCAL_CABYS_RATE_MAX', 5),
            'decay_seconds' => (int) env('FISCAL_CABYS_RATE_DECAY', 60),
        ],

        /**
         * Fuente de la sincronización automática. `null` = solo importación
         * manual con `fiscal:cabys:sync --file`. Se deja en null porque una
         * descarga desatendida a un sitio externo es una decisión de
         * operación, no un detalle de código.
         */
        'remote_source_url' => env('FISCAL_CABYS_REMOTE_URL'),
        'remote_checksum_url' => env('FISCAL_CABYS_REMOTE_CHECKSUM_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proveedor fiscal por empresa
    |--------------------------------------------------------------------------
    | MF02-B4: la asignación por empresa ya NO vive en configuración. Se lee
    | de company_fiscal_credentials. 'provider' queda solo como respaldo para
    | instalaciones de un solo tenant.
    |
    | Las credenciales y certificados viven cifrados por empresa; este archivo
    | no contiene ni secretos ni rutas de certificados.
    |
    | 'certificate_disk'  disco privado, fuera de public/ (por defecto 'local').
    | 'certificate_root'  subcarpeta segregada por company_id.
    | 'certificate_max_bytes'  tamaño máximo aceptado (2 MB).
    */

    'certificate_disk' => env('FISCAL_CERTIFICATE_DISK', 'local'),

    'certificate_root' => 'fiscal',

    'certificate_max_bytes' => 2 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Rotación de APP_KEY
    |--------------------------------------------------------------------------
    | MF02-B4: al rotar APP_KEY hay que conservar la clave anterior aquí
    | para poder descifrar el material ya sellado. Ver el runbook
    | docs/fiscal/MVS_FISCAL_MF02_B4_RUNBOOK.md.
    */

    'previous_master_keys' => array_values(array_filter(
        explode(',', (string) env('FISCAL_PREVIOUS_MASTER_KEYS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Consulta oficial de contribuyentes (MF03)
    |--------------------------------------------------------------------------
    |
    | `api.hacienda.go.cr` NO es el canal de envío de comprobantes: es un
    | servicio de consulta de datos maestros. Solo se usa `/fe/*`.
    |
    | LÍMITES OFICIALES: 20 req/s en 5 s y 10 req/s sostenidos en 120 s. Superar
    | el umbral bloquea la IP de salida durante 10 minutos. Como todos los
    | tenants de MVS salen por la misma IP (CGNAT), el rate limit es GLOBAL y
    | compartido: un límite por tenant dejaría que diez empresas en conjunto
    | superen el umbral oficial y bloquearan a todos a la vez.
    |
    | La DGT recomienda caché local explícitamente porque el estado tributario
    | de un contribuyente no cambia en tiempo real. La clave de caché es GLOBAL
    | (hash de la identificación, sin company_id): el dato oficial es el mismo
    | para todas las empresas y duplicarlo por tenant solo multiplicaría el
    | tráfico hacia la API oficial.
    |
    | 'regime_flags' está VACÍO A PROPÓSITO. Las banderas derivadas del régimen
    | (simplificado, traspaso de taxes) no se codifican sin verificar la tabla
    | oficial contra el PDF de la DGT: es preferible devolver null a afirmar un
    | dato fiscal equivocado. Quien lo cargue debe hacerlo con la fuente
    | verificada, no de memoria.
    |
    */

    'hacienda' => [
        'base_url' => env('FISCAL_HACIENDA_BASE_URL', 'https://api.hacienda.go.cr'),

        'timeout_seconds' => (int) env('FISCAL_HACIENDA_TIMEOUT', 8),

        'retry_attempts' => (int) env('FISCAL_HACIENDA_RETRY_ATTEMPTS', 2),
        'backoff_base_ms' => (int) env('FISCAL_HACIENDA_BACKOFF_BASE_MS', 250),

        /**
         * Presupuesto máximo de espera para un `Retry-After` de la API oficial
         * ante 429. Si la API pide más que esto, NO se duerme la petición (eso
         * agotaría `max_execution_time` y el usuario vería un 500): se devuelve
         * un error tipado de inmediato para reintentar después, por ejemplo en
         * cola. Mantener por debajo del `timeout` de PHP-FPM de producción.
         */
        'retry_after_max_seconds' => (int) env('FISCAL_HACIENDA_RETRY_AFTER_MAX', 5),

        /** 30 días: la DGT recomienda caché porque el estado no cambia en tiempo real. */
        'cache_ttl_seconds' => (int) env('FISCAL_HACIENDA_CACHE_TTL', 2_592_000),

        /** 1 hora para "no encontrado": evita re-preguntar una identificación inválida en cada venta. */
        'negative_cache_seconds' => (int) env('FISCAL_HACIENDA_NEGATIVE_CACHE_TTL', 3_600),

        /**
         * null = usar el store por defecto de la aplicación. Fijar 'database'
         * aquí override el store global y rompería el cacheo en entornos donde
         * el default es array o redis.
         */
        'cache_store' => env('FISCAL_HACIENDA_CACHE_STORE'),

        'rate_limit' => [
            /** Cubo ÚNICO, global para todos los tenants. */
            'max_attempts' => (int) env('FISCAL_HACIENDA_RATE_MAX', 10),
            'decay_seconds' => (int) env('FISCAL_HACIENDA_RATE_DECAY', 60),
        ],

        /**
         * Mapeo verificado regime_code => flags. Vacío hasta disposition de la
         * tabla oficial de regímenes.
         */
        'regime_flags' => [],
    ],

];
