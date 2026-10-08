<?php

namespace App\Services\Customers;

use App\Contracts\Fiscal\FiscalTaxpayerLookupInterface;
use App\Models\Company;
use App\Models\Customer;
use Throwable;

/**
 * Integración COMÚN de autocompletado: identificación => nombre.
 *
 * Orden (MF03 portado de mvs-fiscal):
 *  1) Buró (lo resuelve el flujo 7B ANTES de llamar a este servicio);
 *  2) Hacienda / `FiscalTaxpayerLookupInterface` para el país con fuente
 *     habilitada y tipo numérico soportado (cédula física/jurídica, DIMEX,
 *     NITE) — nunca bloquea el formulario ni escribe nada;
 *  3) fallback local: clientes existentes de la empresa activa (mismo
 *     criterio que `pos.customers.search`), aislado por tenant;
 *  4) manual si nada encuentra.
 *
 * El proveedor es POR PAÍS (no global). La fuente fiscal MF03 se usa
 * directamente (sin switch propio, igual que mvs-fiscal) para CR; si falla,
 * el nombre queda en fallback local o manual.
 */
class IdentificationNameLookupService
{
    /** iso2 => tipos de identificación que su proveedor local soporta realmente. */
    private const PROVIDERS = [
        'CR' => ['cedula'],
    ];

    /** Tipos numéricos que MF03 (Hacienda, CR) resuelve realmente. */
    private const FISCAL_TYPES = ['cedula', 'juridica', 'dimex', 'nit'];

    /** ¿Existe fuente (fiscal o local) para el país de la cuenta y ese tipo? */
    public function supports(Company $company, string $identificationType): bool
    {
        $iso = $this->countryIso($company);
        if ($iso === null) {
            return false;
        }

        if ($iso === 'CR' && in_array($identificationType, self::FISCAL_TYPES, true)) {
            return true;
        }

        return in_array($identificationType, self::PROVIDERS[$iso] ?? [], true);
    }

    /** Nombre encontrado o null. Nunca escribe ni bloquea. */
    public function lookup(Company $company, string $identificationType, string $identification): ?string
    {
        $iso = $this->countryIso($company);
        if ($iso === null) {
            return null;
        }

        $id = trim($identification);
        if ($id === '') {
            return null;
        }

        // 2) MF03: propuesta oficial de Hacienda. Cualquier fallo (red, 429,
        //    5xx, payload raro) cae al fallback sin bloquear el formulario.
        if ($iso === 'CR' && in_array($identificationType, self::FISCAL_TYPES, true)) {
            try {
                $info = app(FiscalTaxpayerLookupInterface::class)->lookup($id);

                if ($info->found && is_string($info->name) && $info->name !== '') {
                    return $info->name;
                }
            } catch (Throwable) {
                // La fuente oficial no respondió: sigue el fallback local.
            }
        }

        // 3) Fallback local: SOLO clientes de la empresa activa (aislamiento).
        if (
            in_array($identificationType, self::PROVIDERS[$iso] ?? [], true)
            && $this->hasValidFormat($identificationType, $id)
        ) {
            return Customer::forCompany((int) $company->id)
                ->where('identification', $id)
                ->orderBy('id')
                ->value('name');
        }

        return null;
    }

    /** Formato "válido" del proveedor LOCAL (CR: cédula nacional de 9 dígitos). */
    private function hasValidFormat(string $identificationType, string $identification): bool
    {
        if ($identificationType === 'cedula') {
            return (bool) preg_match('/^\d{9}$/', $identification);
        }

        return false;
    }

    /** País configurado de la cuenta; sin país explícito, moneda CRC => Costa Rica. */
    private function countryIso(Company $company): ?string
    {
        $iso = $company->country?->iso2;
        if ($iso) {
            return strtoupper($iso);
        }

        return strtoupper((string) $company->currency) === 'CRC' ? 'CR' : null;
    }
}
