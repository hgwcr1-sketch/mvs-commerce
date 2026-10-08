<?php

namespace App\Services\Fiscal\Hacienda;

use App\DTOs\Fiscal\FiscalError;
use App\DTOs\Fiscal\FiscalTaxpayerInfo;
use Illuminate\Http\Client\Response;

/**
 * Traduce la respuesta de `api.hacienda.go.cr/fe/ae` a `FiscalTaxpayerInfo`.
 *
 * Decisión deliberada: NO se codifica a mano ningún catálogo de regímenes ni de
 * tipos de identificación. La API oficial es la fuente y su `regimen` se copia
 * literalmente a `regimeCode`. Las banderas derivadas (`regimeSimplified`,
 * `regimeTransfersTax`) salen de un mapeo configurable y vacío por defecto
 * (`fiscal.hacienda.regime_flags`): hasta que alguien verifique la tabla
 * oficial contra el PDF de la DGT y la cargue, MVS no afirma qué régimen es
 * cuál. Es preferible devolver `null` a devolver un dato fiscal equivocado.
 *
 * Las claves de la respuesta se aceptan con alias porque la documentación
 * pública de la API no fija un único nombre por concepto. Un payload que no
 * encaje en ningún alias NO se interpreta: devuelve error tipado en vez de
 * inventar una respuesta.
 */
class HaciendaTaxpayerNormalizer
{
    /** Alias aceptados por concepto, en orden de preferencia. */
    private const ALIASES = [
        'name' => ['nombre', 'name', 'nombre_razon_social', 'razon_social', 'nombre_contribuyente'],
        'identification' => ['identificacion', 'identificacion_numerica', 'numero_identificacion', 'identificacion_contribuyente'],
        'identification_type' => ['tipoIdentificacion', 'tipo_identificacion', 'tipo_de_identificacion', 'identification_type'],
        'taxpayer' => ['contribuyente', 'taxpayer', 'es_contribuyente'],
        'regime' => ['regimen', 'regimen_tributario', 'regime_code'],
        'situation' => ['situacion', 'situacion_tributaria', 'situacion_contribuyente'],
        'activities' => ['actividades', 'actividades_economicas', 'actividad_economica', 'activities'],
    ];

    public function notFound(string $identification): FiscalTaxpayerInfo
    {
        return (new FiscalTaxpayerInfo())->withError(new FiscalError(
            code: 'not_found',
            message: "El contribuyente {$identification} no existe o no está registrado",
            category: FiscalError::CATEGORY_UNKNOWN,
            retryable: false,
        ));
    }

    public function invalidRequest(string $message): FiscalTaxpayerInfo
    {
        return (new FiscalTaxpayerInfo())->withError(new FiscalError(
            code: 'invalid_identification',
            message: $message,
            category: FiscalError::CATEGORY_VALIDATION,
            retryable: false,
        ));
    }

    public function rateLimited(?int $retryAfterSeconds): FiscalTaxpayerInfo
    {
        return (new FiscalTaxpayerInfo())->withError(new FiscalError(
            code: 'rate_limited',
            message: 'La API oficial de Hacienda limitó la consulta',
            category: FiscalError::CATEGORY_RATE_LIMIT,
            retryable: true,
            retryAfterSeconds: $retryAfterSeconds,
        ));
    }

    public function serverError(int $status): FiscalTaxpayerInfo
    {
        return (new FiscalTaxpayerInfo())->withError(new FiscalError(
            code: 'hacienda_unavailable',
            message: "La API oficial de Hacienda respondió {$status}",
            category: $status >= 500 ? FiscalError::CATEGORY_SERVER : FiscalError::CATEGORY_UNKNOWN,
            retryable: $status >= 500,
        ));
    }

    public function networkError(string $message): FiscalTaxpayerInfo
    {
        return (new FiscalTaxpayerInfo())->withError(new FiscalError(
            code: 'hacienda_unreachable',
            message: $message,
            category: FiscalError::CATEGORY_NETWORK,
            retryable: true,
        ));
    }

    public function unrecognisedPayload(): FiscalTaxpayerInfo
    {
        return (new FiscalTaxpayerInfo())->withError(new FiscalError(
            code: 'unrecognised_payload',
            message: 'La respuesta de Hacienda no tiene una estructura reconocida',
            category: FiscalError::CATEGORY_UNKNOWN,
            retryable: false,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): FiscalTaxpayerInfo
    {
        if ($payload === []) {
            return $this->unrecognisedPayload();
        }

        $name = $this->string($payload, self::ALIASES['name']);
        $regime = $this->regimeCode($payload['regimen'] ?? null)
            ?? $this->string($payload, self::ALIASES['regime']);
        $situation = $this->situation($payload['situacion'] ?? null)
            ?? $this->string($payload, self::ALIASES['situation']);
        $identificationType = $this->string($payload, self::ALIASES['identification_type']);

        /**
         * Si no se reconoce ni el nombre ni el régimen ni la situación, la
         * respuesta no es de un contribuyente: mejor un error explícito que una
         * ficha vacía que el usuario confundiría con "no está registrado".
         */
        if ($name === null && $regime === null && $situation === null) {
            return $this->unrecognisedPayload();
        }

        $taxpayer = $this->bool($payload, self::ALIASES['taxpayer']) ?? true;
        $flags = $this->regimeFlags($regime);

        return new FiscalTaxpayerInfo(
            found: true,
            taxpayer: $taxpayer,
            regimeCode: $regime,
            regimeDescription: $this->regimeDescription($payload['regimen'] ?? null),
            regimeKey: null,
            regimeSimplified: $flags['simplified'],
            regimeTransfersTax: $flags['transfers_tax'],
            activities: $this->activities($payload),
            situation: $situation,
            name: $name,
            identificationType: $identificationType ?? $this->string($payload, self::ALIASES['identification']),
        );
    }

    /**
     * La API oficial entrega `regimen` como OBJETO `{codigo, descripcion}`
     * (verificado en producción de la API, no en el mock). Se prefiere la
     * descripción oficial como código literal; si solo hay código numérico,
     * se usa ese. Un escalar ("R", "RS") pasa tal cual por los alias.
     */
    private function regimeCode(mixed $regime): ?string
    {
        if (! is_array($regime)) {
            return null;
        }

        $description = $regime['descripcion'] ?? $regime['description'] ?? null;

        if (is_string($description) && trim($description) !== '') {
            return trim($description);
        }

        $code = $regime['codigo'] ?? $regime['code'] ?? null;

        if (is_numeric($code)) {
            return (string) $code;
        }

        return null;
    }

    /**
     * La API oficial entrega `situacion` como OBJETO
     * `{moroso, omiso, estado, administracionTributaria, mensaje}`. Lo que la
     * UI necesita es el `estado` tributario ("Inscrito", "Desinscrito oficio",
     * etc.). Un escalar ("A", "ACTIVO") pasa tal cual por los alias.
     */
    private function situation(mixed $situation): ?string
    {
        if (! is_array($situation)) {
            return null;
        }

        $estado = $situation['estado'] ?? null;

        return is_string($estado) && trim($estado) !== '' ? trim($estado) : null;
    }

    private function regimeDescription(mixed $regime): ?string
    {
        if (! is_array($regime)) {
            return null;
        }

        $description = $regime['descripcion'] ?? $regime['description'] ?? null;

        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }

    public function fromResponse(Response $response): FiscalTaxpayerInfo
    {
        $status = $response->status();

        if ($status === 404) {
            return $this->notFound('consultada');
        }

        if ($status === 400) {
            return $this->invalidRequest('La API oficial rechazó la identificación consultada');
        }

        if ($status === 429) {
            return $this->rateLimited((int) ($response->header('Retry-After') ?? 0) ?: null);
        }

        if ($status >= 500) {
            return $this->serverError($status);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return $this->unrecognisedPayload();
        }

        return $this->normalize($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, description: string}>
     */
    private function activities(array $payload): array
    {
        $raw = [];

        foreach (self::ALIASES['activities'] as $alias) {
            if (array_key_exists($alias, $payload)) {
                $raw = $payload[$alias];
                break;
            }
        }

        if (! is_array($raw)) {
            return [];
        }

        $activities = [];

        foreach ($raw as $key => $entry) {
            if (is_string($entry)) {
                /**
                 * Lista plana: el propio valor ES el código. Usar la clave del
                 * array (0, 1, 2...) inventaría códigos que nadie declaró.
                 */
                $code = trim($entry);
                $description = $code;
            } elseif (is_array($entry)) {
                $code = $this->string($entry, ['codigo', 'code', 'categoria', 'id']) ?? (string) $key;
                $description = $this->string($entry, ['descripcion', 'description', 'nombre', 'name']) ?? $code;
            } else {
                continue;
            }

            if ($code === '') {
                continue;
            }

            $activities[] = ['code' => $code, 'description' => $description];
        }

        return $activities;
    }

    /**
     * @return array{simplified: bool, transfers_tax: bool}
     */
    private function regimeFlags(?string $regimeCode): array
    {
        $flags = config('fiscal.hacienda.regime_flags', []);

        if (! is_array($flags) || $regimeCode === null) {
            return ['simplified' => false, 'transfers_tax' => false];
        }

        $entry = $flags[$regimeCode] ?? null;

        if (! is_array($entry)) {
            return ['simplified' => false, 'transfers_tax' => false];
        }

        return [
            'simplified' => (bool) ($entry['simplified'] ?? false),
            'transfers_tax' => (bool) ($entry['transfers_tax'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function string(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function bool(array $payload, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_bool($value)) {
                return $value;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));

                if (in_array($normalized, ['true', '1', 'si', 'sí'], true)) {
                    return true;
                }

                if (in_array($normalized, ['false', '0', 'no'], true)) {
                    return false;
                }
            }
        }

        return null;
    }
}
