<?php

namespace App\Services\Fiscal;

use App\Models\FiscalProfile;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FiscalTaxService
{
    public function resolveProfile(string $taxCode, ?string $taxRateCode, string $documentType = '01'): FiscalProfile
    {
        $profile = FiscalProfile::query()
            ->where('tax_code', $taxCode)
            ->where('tax_rate_code', $taxRateCode)
            ->where('is_active', true)
            ->whereHas('catalogVersion', fn ($query) => $query->where('status', 'active'))
            ->latest('id')
            ->first();

        if (! $profile) {
            throw new InvalidArgumentException('Perfil fiscal inexistente o inactivo.');
        }

        $this->validateProfile($profile, $documentType);

        return $profile;
    }

    public function resolveLegacyTaxRate(?float $taxRate, string $documentType = '01'): FiscalProfile
    {
        if ($taxRate === null || abs($taxRate) < 0.0001) {
            throw new InvalidArgumentException('tax_rate=0 es ambiguo: requiere perfil fiscal explícito.');
        }

        $rateCode = match (true) {
            abs($taxRate - 1) < 0.0001 => '02',
            abs($taxRate - 2) < 0.0001 => '03',
            abs($taxRate - 4) < 0.0001 => '04',
            abs($taxRate - 13) < 0.0001 => '08',
            default => null,
        };

        if ($rateCode === null) {
            throw new InvalidArgumentException('tax_rate legado no tiene equivalencia fiscal inequívoca.');
        }

        return $this->resolveProfile('01', $rateCode, $documentType);
    }

    public function resolveForProduct(?int $fiscalProfileId, ?float $legacyTaxRate = null, string $documentType = '01'): FiscalProfile
    {
        if ($fiscalProfileId !== null) {
            $profile = FiscalProfile::query()
                ->where('id', $fiscalProfileId)
                ->where('is_active', true)
                ->whereHas('catalogVersion', fn ($query) => $query->where('status', 'active'))
                ->first();

            if (! $profile) {
                throw new InvalidArgumentException('Perfil fiscal inexistente o inactivo.');
            }

            $this->validateProfile($profile, $documentType);

            return $profile;
        }

        return $this->resolveLegacyTaxRate($legacyTaxRate, $documentType);
    }

    public function resolveProductProfile(Product $product, string $documentType = '01'): FiscalProfile
    {
        $fiscalProfileId = $product->fiscal_profile_id !== null
            ? (int) $product->fiscal_profile_id
            : null;

        $legacyTaxRate = $product->tax_rate !== null
            ? (float) $product->tax_rate
            : null;

        $profile = $this->resolveForProduct($fiscalProfileId, $legacyTaxRate, $documentType);

        if ($profile->tax_code !== '01' || $profile->rate === null) {
            throw new InvalidArgumentException('su perfil fiscal no define una tarifa de IVA calculable.');
        }

        return $profile;
    }

    public function productProfiles(): Collection
    {
        return FiscalProfile::query()
            ->where('tax_code', '01')
            ->where('is_active', true)
            ->whereHas('catalogVersion', fn ($query) => $query->where('status', 'active'))
            ->orderBy('rate')
            ->orderBy('id')
            ->get()
            ->filter(fn (FiscalProfile $profile) => in_array('01', (array) $profile->document_types, true))
            ->values();
    }

    /**
     * Perfiles fiscales activos de la versión de catálogo activa (fuente
     * única para catálogos descargables y guías; no duplicar esta autoridad).
     *
     * @return Collection<int, FiscalProfile>
     */
    public function activeCatalog(): Collection
    {
        return FiscalProfile::query()
            ->with('catalogVersion')
            ->where('is_active', true)
            ->whereHas('catalogVersion', fn ($query) => $query->where('status', 'active'))
            ->orderBy('tax_code')
            ->orderBy('tax_rate_code')
            ->orderBy('rate')
            ->orderBy('id')
            ->get();
    }

    public function snapshotForSaleItem(SaleItem $item, string $documentType = '01'): array
    {
        if (is_array($item->fiscal_snapshot) && ! empty($item->fiscal_snapshot['taxes'])) {
            $snapshot = $item->fiscal_snapshot;
            $this->validateSnapshot($snapshot, $documentType);

            return $snapshot;
        }

        if ($item->tax_code !== null || $item->tax_rate_code !== null) {
            $profile = $this->resolveProfile((string) $item->tax_code, $item->tax_rate_code, $documentType);
        } else {
            $profile = $this->resolveLegacyTaxRate($item->tax_rate !== null ? (float) $item->tax_rate : null, $documentType);
        }

        return $this->snapshotFromProfile($profile);
    }

    public function snapshotFromProfile(FiscalProfile $profile): array
    {
        return [
            'source' => $profile->catalogVersion?->source,
            'source_version' => $profile->catalogVersion?->source_version,
            'taxes' => [[
                'codigo' => $profile->tax_code,
                'codigoTarifa' => $profile->tax_rate_code,
                'tarifa' => $profile->rate !== null ? (float) $profile->rate : null,
                'factorIVA' => $profile->factor_iva !== null ? (float) $profile->factor_iva : null,
                'codigoTarifaOtro' => $profile->tax_rate_other,
                'treatment' => $profile->treatment,
            ]],
        ];
    }

    public function validateProfile(FiscalProfile $profile, string $documentType = '01'): void
    {
        if (in_array($profile->tax_rate_code, ['05', '06', '07'], true) && ! in_array($documentType, ['02', '03'], true)) {
            throw new InvalidArgumentException('Tarifa transitoria solo es válida para notas de crédito/débito.');
        }

        if ($profile->tax_code === '08' && $profile->factor_iva === null) {
            throw new InvalidArgumentException('tax_code=08 requiere factorIVA.');
        }

        if ($profile->tax_code === '99' && blank($profile->tax_rate_other)) {
            throw new InvalidArgumentException('tax_code=99 requiere codigoTarifaOtro.');
        }
    }

    public function validateSnapshot(array $snapshot, string $documentType = '01'): void
    {
        $taxes = $snapshot['taxes'] ?? [];
        if ($taxes === []) {
            throw new InvalidArgumentException('El snapshot fiscal debe contener al menos un impuesto.');
        }

        $hasIva = false;
        foreach ($taxes as $tax) {
            $code = (string) ($tax['codigo'] ?? '');
            $rateCode = $tax['codigoTarifa'] ?? null;
            $hasIva = $hasIva || in_array($code, ['01', '07', '08'], true);
            if (in_array($rateCode, ['05', '06', '07'], true) && ! in_array($documentType, ['02', '03'], true)) {
                throw new InvalidArgumentException('Tarifa transitoria no válida para factura/tiquete.');
            }
            if ($code === '08' && blank($tax['factorIVA'] ?? null)) {
                throw new InvalidArgumentException('tax_code=08 requiere factorIVA.');
            }
            if ($code === '99' && blank($tax['codigoTarifaOtro'] ?? null)) {
                throw new InvalidArgumentException('tax_code=99 requiere codigoTarifaOtro.');
            }
            if (in_array($code, ['03', '04', '05', '06', '12'], true) && empty($tax['datosImpuestoEspecifico'])) {
                throw new InvalidArgumentException('El impuesto específico requiere datosImpuestoEspecifico.');
            }
        }

        if (! $hasIva) {
            throw new InvalidArgumentException('Toda línea debe incluir un impuesto de la familia IVA.');
        }
    }

    public function serializeSnapshot(array $snapshot): array
    {
        $this->validateSnapshot($snapshot);

        return array_map(function (array $tax): array {
            $serialized = [];
            foreach (['codigo', 'codigoTarifa', 'tarifa', 'factorIVA', 'codigoTarifaOtro', 'datosImpuestoEspecifico', 'exoneracion'] as $field) {
                if (array_key_exists($field, $tax) && $tax[$field] !== null) {
                    $serialized[$field] = $tax[$field];
                }
            }

            if (isset($serialized['tarifa']) && is_numeric($serialized['tarifa']) && (float) $serialized['tarifa'] == (int) $serialized['tarifa']) {
                $serialized['tarifa'] = (int) $serialized['tarifa'];
            }

            return $serialized;
        }, $snapshot['taxes']);
    }

    /**
     * Normalización fiscal única para importaciones de productos.
     *
     * Precedencia: perfil explícito > códigos explícitos (codigo + tarifa)
     * > tasa legada inequívoca (1/2/4/13). El 0/8/NULL jamás se infiere y
     * CABYS nunca participa en la clasificación.
     *
     * @param  array{fiscal_profile_id?: int|string|null, tax_code?: string|null, tax_rate_code?: string|null, tax_rate?: float|int|string|null}  $attributes
     * @return array{fiscal_profile_id: int, tax_rate: float}
     *
     * @throws InvalidArgumentException
     */
    public function normalizeProductFiscalAttributes(array $attributes): array
    {
        $profileId = $this->nullableInt($attributes['fiscal_profile_id'] ?? null);
        $taxCode = $this->nullableString($attributes['tax_code'] ?? null);
        $rateCode = $this->nullableString($attributes['tax_rate_code'] ?? null);
        $legacyRate = $this->nullableFloat($attributes['tax_rate'] ?? null);

        if ($profileId !== null) {
            $profile = $this->resolveForProduct($profileId, null);
        } elseif ($taxCode !== null && $rateCode !== null) {
            $profile = $this->resolveProfile($taxCode, $rateCode);
        } elseif ($taxCode !== null && $legacyRate !== null) {
            $derived = $this->rateCodeFromTarifa($legacyRate, $taxCode);
            if ($derived === null) {
                throw new InvalidArgumentException("la tasa {$legacyRate} del documento no tiene tarifa inequívoca para el código {$taxCode}; use códigos o perfil fiscal explícito.");
            }
            $profile = $this->resolveProfile($taxCode, $derived);
        } else {
            // Tasa legada sin códigos: solo 1/2/4/13; 0/8/NULL u otras quedan bloqueadas.
            $profile = $this->resolveLegacyTaxRate($legacyRate);
        }

        if ($profile->tax_code !== '01' || $profile->rate === null) {
            throw new InvalidArgumentException('su perfil fiscal no define una tarifa de IVA calculable.');
        }

        return [
            'fiscal_profile_id' => (int) $profile->id,
            'tax_rate' => (float) $profile->rate,
        ];
    }

    /**
     * Precedencia fiscal única de una línea de compra/importación:
     * perfil explícito > códigos explícitos > fiscalidad del documento
     * (si existe, manda el documento y jamás se sustituye por el producto)
     * > tasa legada inequívoca > perfil del producto. Si nada resuelve,
     * se bloquea: nunca se inventa tratamiento.
     *
     * @param  array<int, array<string, mixed>>|null  $documentTaxes
     *
     * @throws InvalidArgumentException
     */
    public function resolveImportLineProfile(
        ?int $fiscalProfileId,
        ?string $taxCode,
        ?string $taxRateCode,
        ?float $legacyRate,
        ?array $documentTaxes,
        ?Product $product,
    ): FiscalProfile {
        if ($fiscalProfileId !== null) {
            return $this->resolveForProduct($fiscalProfileId, null);
        }

        $taxCode = $this->nullableString($taxCode);
        $taxRateCode = $this->nullableString($taxRateCode);

        if ($taxCode !== null && $taxRateCode !== null) {
            return $this->resolveProfile($taxCode, $taxRateCode);
        }

        if (! empty($documentTaxes)) {
            return $this->profileForDocumentTaxes($documentTaxes);
        }

        if ($taxCode !== null && $legacyRate !== null) {
            $derived = $this->rateCodeFromTarifa($legacyRate, $taxCode);
            if ($derived === null) {
                throw new InvalidArgumentException("la tasa {$legacyRate} no es inequívoca para el código {$taxCode}; use perfil fiscal explícito.");
            }

            return $this->resolveProfile($taxCode, $derived);
        }

        if ($legacyRate !== null && $this->isUnequivocalRate($legacyRate)) {
            return $this->resolveLegacyTaxRate($legacyRate);
        }

        if ($product !== null) {
            return $this->resolveProductProfile($product);
        }

        throw new InvalidArgumentException('la línea no incluye perfil fiscal, códigos ni tasa inequívoca.');
    }

    /**
     * Resuelve el perfil IVA a partir de la fiscalidad fiel de un documento
     * Hacienda (todos los Impuesto de la línea). Si el documento existe pero
     * su clasificación no es inequívoca, se bloquea: no se infiere 0/8 ni se
     * sustituye por el producto actual.
     *
     * @param  array<int, array<string, mixed>>  $documentTaxes
     *
     * @throws InvalidArgumentException
     */
    public function profileForDocumentTaxes(array $documentTaxes): FiscalProfile
    {
        $primary = $this->primaryDocumentTax($documentTaxes);

        if ($primary === null) {
            throw new InvalidArgumentException('el documento no incluye impuestos clasificables.');
        }

        $codigo = $this->nullableString($primary['codigo'] ?? null);
        $tarifa = isset($primary['tarifa']) && is_numeric($primary['tarifa'])
            ? (float) $primary['tarifa']
            : null;
        $rateCode = $this->nullableString($primary['codigo_tarifa'] ?? null)
            ?? $this->rateCodeFromTarifa($tarifa, $codigo);

        if ($rateCode === null) {
            $tarifaTexto = $tarifa ?? 'n/d';
            throw new InvalidArgumentException("la tarifa del documento ({$tarifaTexto}) no es inequívoca; use perfil fiscal explícito.");
        }

        // El código 01 solo se asume cuando la tarifa no es cero: el 0 del
        // documento exige código de IVA explícito, nunca se infiere.
        if ($codigo === null && $tarifa !== null && abs($tarifa) < 0.0001 && $rateCode === '01') {
            throw new InvalidArgumentException('la tarifa 0 del documento requiere código de IVA (01) explícito.');
        }
        $codigo ??= '01';

        $profile = $this->resolveProfile($codigo, $rateCode);

        if ($profile->rate === null) {
            throw new InvalidArgumentException('la clasificación del documento no define tarifa de IVA calculable.');
        }

        return $profile;
    }

    /**
     * Índice del impuesto IVA principal dentro de la fiscalidad del documento.
     *
     * @param  array<int, array<string, mixed>>  $documentTaxes
     */
    public function primaryDocumentTaxIndex(array $documentTaxes): ?int
    {
        foreach ($documentTaxes as $index => $tax) {
            if ($this->nullableString($tax['codigo'] ?? null) === '01') {
                return (int) $index;
            }
        }

        foreach ($documentTaxes as $index => $tax) {
            if (isset($tax['tarifa']) && is_numeric($tax['tarifa'])) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Equivalencia inequívoca tarifa Hacienda → código de tarifa (solo
     * 1/2/4/13 y 0 cuando el propio documento declara código 01).
     */
    public function rateCodeFromTarifa(?float $tarifa, ?string $taxCode): ?string
    {
        if ($tarifa === null) {
            return null;
        }

        return match (true) {
            abs($tarifa - 1) < 0.0001 => '02',
            abs($tarifa - 2) < 0.0001 => '03',
            abs($tarifa - 4) < 0.0001 => '04',
            abs($tarifa - 13) < 0.0001 => '08',
            abs($tarifa) < 0.0001 => $taxCode === '01' ? '01' : null,
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $documentTaxes
     */
    private function primaryDocumentTax(array $documentTaxes): ?array
    {
        $index = $this->primaryDocumentTaxIndex($documentTaxes);

        return $index === null ? null : $documentTaxes[$index];
    }

    private function isUnequivocalRate(float $rate): bool
    {
        return abs($rate - 1) < 0.0001
            || abs($rate - 2) < 0.0001
            || abs($rate - 4) < 0.0001
            || abs($rate - 13) < 0.0001;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}