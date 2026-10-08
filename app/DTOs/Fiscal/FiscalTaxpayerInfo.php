<?php

namespace App\DTOs\Fiscal;

/**
 * Información fiscal neutral de un contribuyente, traducida desde el
 * proveedor de consulta. Las actividades económicas usan claves MVS
 * ("code"/"description"), nunca las claves propietarias del proveedor.
 *
 * MF03: `name` e `identificationType` se añaden porque la consulta oficial
 * devuelve el nombre del contribuyente y el tipo de identificación, y sin ellos
 * la propuesta al usuario era inservible. Son datos de una persona física o
 * jurídica: no se registran en logs.
 */
final class FiscalTaxpayerInfo
{
    /**
     * @param  array<int, array{code: string, description: string}>  $activities
     * @param  string|null  $name  nombre o razón social oficial.
     * @param  string|null  $identificationType  código MVS del tipo de identificación.
     */
    public function __construct(
        public readonly bool $found = false,
        public readonly ?bool $taxpayer = null,
        public readonly ?string $regimeCode = null,
        public readonly ?string $regimeKey = null,
        public readonly ?string $regimeDescription = null,
        public readonly bool $regimeSimplified = false,
        public readonly bool $regimeTransfersTax = false,
        public readonly array $activities = [],
        public readonly ?string $situation = null,
        public readonly ?FiscalError $error = null,
        public readonly ?string $name = null,
        public readonly ?string $identificationType = null,
    ) {
    }

    /**
     * Error tipado sin datos del contribuyente: seguro para logs y metrics.
     */
    public function withError(FiscalError $error): self
    {
        return new self(
            found: false,
            taxpayer: null,
            regimeCode: null,
            regimeKey: null,
            regimeDescription: null,
            regimeSimplified: false,
            regimeTransfersTax: false,
            activities: [],
            situation: null,
            error: $error,
            name: null,
            identificationType: null,
        );
    }

    /**
     * Propuesta oficial lista para mostrar al usuario. NUNCA se aplica sola:
     * los datos capturados por MVS no se sobrescriben sin confirmación.
     *
     * @return array<string, mixed>
     */
    public function proposal(): array
    {
        return [
            'name' => $this->name,
            'identification_type' => $this->identificationType,
            'taxpayer' => $this->taxpayer,
            'regime' => $this->regimeKey ?? $this->regimeCode,
            'regime_description' => $this->regimeDescription,
            'regime_simplified' => $this->regimeSimplified,
            'regime_transfers_tax' => $this->regimeTransfersTax,
            'situation' => $this->situation,
            'activities' => $this->activities,
        ];
    }
}
