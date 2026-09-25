<?php

namespace App\Services\Fiscal;

use App\Models\FiscalProfile;
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
}