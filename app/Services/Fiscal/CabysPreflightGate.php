<?php

namespace App\Services\Fiscal;

use App\DTOs\Fiscal\FiscalReferenceException;
use App\Services\Cabys\CabysCatalogResolver;

/**
 * Gate CABYS de emisión (FE/TE/NC/ND) contra la VERSIÓN ACTIVA GLOBAL del
 * catálogo oficial: `fiscal_catalog_versions` + `cabys_catalog_entries`.
 *
 * Reglas, en orden:
 *
 *  1. el formato oficial se exige siempre (13 dígitos);
 *  2. con versión activa, el código tiene que EXISTIR en ella: un código
 *     bien formado pero ajeno al catálogo vigente no se emite;
 *  3. sin versión activa la existencia no es verificable y NO se bloquea: la
 *     ausencia de catálogo es un estado operativo conocido, no una prueba de
 *     invalidez.
 *
 * El gate nunca infiere, corrige ni sustituye un código, y nunca degrada a una
 * categoría padre: un código ausente o inválido es un problema, no una
 * adivinanza. No hace red: solo base de datos y caché local.
 */
class CabysPreflightGate
{
    /** Formato oficial de un código CABYS terminal de bienes. */
    public const CODE_PATTERN = '/^\d{13}$/D';

    public const REASON_MISSING = 'missing';

    public const REASON_FORMAT = 'format';

    public const REASON_NOT_IN_CATALOG = 'not_in_catalog';

    public function __construct(private readonly CabysCatalogResolver $resolver) {}

    /**
     * Motivo del bloqueo, o null si el código es utilizable en una emisión.
     */
    public function problem(?string $code): ?string
    {
        $digits = trim((string) $code);

        if ($digits === '') {
            return self::REASON_MISSING;
        }

        if (preg_match(self::CODE_PATTERN, $digits) !== 1) {
            return self::REASON_FORMAT;
        }

        $version = $this->resolver->activeVersion();

        if ($version === null) {
            return null;
        }

        $entry = $this->resolver->entry($digits, (int) $version->id);

        return ($entry === null || ! $entry->is_active) ? self::REASON_NOT_IN_CATALOG : null;
    }

    /**
     * Versión vigente contra la que se compara, para que el mensaje diga
     * contra QUÉ catálogo se validó sin inventar una versión.
     */
    public function activeVersionLabel(): ?string
    {
        return $this->resolver->activeVersion()?->source_version;
    }

    /**
     * Gate de EMISIÓN: el formato ya lo exige el documento o el mapper, así
     * que aquí solo se bloquea la pertenencia al catálogo vigente. Sin versión
     * activa no hay comparación posible y la emisión continúa.
     *
     * @param  iterable<string|null>  $codes
     *
     * @throws FiscalReferenceException
     */
    public function assertInCatalog(iterable $codes): void
    {
        if ($this->resolver->activeVersion() === null) {
            return;
        }

        foreach ($codes as $code) {
            if ($this->problem($code) !== self::REASON_NOT_IN_CATALOG) {
                continue;
            }

            throw new FiscalReferenceException(
                "CABYS fuera del catálogo vigente: {$code}.",
                'unknown_cabys'
            );
        }
    }
}