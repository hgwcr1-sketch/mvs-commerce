<?php

namespace App\Services\Cobros;

use App\Data\Cobros\CobrosPriceLine;
use App\Data\Cobros\CobrosPriceQuote;
use App\Models\CobrosBillingContract;
use App\Models\CobrosPricePlan;
use App\Services\Cobros\CobrosModalityCatalog;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 17A-1 — motor de tarifas CobrOS (cálculo, sin cobro).
 *
 * Tarifas estándar por país+concepto (cobros_price_plans, Panel Maestro);
 * reglas anti-duplicado: prestamista-cobrador cobra UNA sola vez (₡10.000 CR
 * / $60.000 CO total, nunca el doble). Precios personalizados se aplican SOLO
 * desde el contrato (cobros_price_overrides), jamás afectan otros contratos.
 */
class CobrosPricingService
{
    public const CONCEPT_ADMINISTRATION = 'administration';

    public const CONCEPT_ROUTE = 'route';

    public const CONCEPT_COLLECTOR = 'collector';

    public const CONCEPT_MONTHLY_TOTAL = 'monthly_total';

    /** País habilitado => moneda. Misma fuente que el onboarding público. */
    public const COUNTRY_CURRENCIES = [
        'CR' => 'CRC',
        'CO' => 'COP',
    ];

    public function currencyFor(string $countryIso): string
    {
        $currency = self::COUNTRY_CURRENCIES[strtoupper($countryIso)] ?? null;

        if ($currency === null) {
            throw ValidationException::withMessages([
                'country_iso' => 'País sin tarifas CobrOS habilitadas.',
            ]);
        }

        return $currency;
    }

    public function hasCountry(string $countryIso): bool
    {
        return array_key_exists(strtoupper($countryIso), self::COUNTRY_CURRENCIES);
    }

    /** Tarifa estándar vigente (fila activa más reciente) por país+concepto. */
    public function standardAmount(string $countryIso, string $concept): string
    {
        $iso = strtoupper($countryIso);
        $this->currencyFor($iso);

        $plan = CobrosPricePlan::query()
            ->where('country_iso', $iso)
            ->where('concept', $concept)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latest('id')
            ->first();

        if ($plan === null) {
            throw ValidationException::withMessages([
                'price' => "No existe tarifa estándar para {$iso} / {$concept}.",
            ]);
        }

        return (string) $plan->amount;
    }

    /**
     * Cotización ESTÁNDAR de una modalidad (sin contrato ni overrides).
     * Prestamista-cobrador: total = administración (incluye su única ruta):
     * NUNCA se duplica el cargo de ruta.
     */
    public function quote(string $countryIso, string $modality, int $seats = 1, ?string $negotiatedTotal = null): CobrosPriceQuote
    {
        $iso = strtoupper($countryIso);
        $currency = $this->currencyFor($iso);
        $seats = max(1, $seats);

        if (! CobrosModalityCatalog::has($modality)) {
            throw ValidationException::withMessages(['modality' => 'Modalidad CobrOS desconocida.']);
        }

        if ($modality === CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA) {
            throw ValidationException::withMessages([
                'modality' => 'La modalidad multiprestamista no tiene tarifa estándar habilitada.',
            ]);
        }

        $lines = $this->modalityLines($iso, $modality);
        $total = $this->sumLines($lines);

        if ($seats > 1) {
            // Empresarial: administración + cupos (cada cupo = licencia de cobrador).
            $seatUnit = $this->standardAmount($iso, self::CONCEPT_COLLECTOR);

            for ($position = 2; $position <= $seats; $position++) {
                $lines[] = new CobrosPriceLine(
                    self::CONCEPT_COLLECTOR,
                    'Cupo de cobrador #'.$position,
                    $seatUnit,
                    'standard',
                );
            }

            $total = $this->sumLines($lines);
        }

        if ($negotiatedTotal !== null) {
            $lines[] = new CobrosPriceLine(
                self::CONCEPT_MONTHLY_TOTAL,
                'Total mensual negociado',
                $this->normalizeAmount($negotiatedTotal),
                'negotiated',
            );
            $total = $this->normalizeAmount($negotiatedTotal);
        }

        return new CobrosPriceQuote($iso, $currency, $seats > 1 ? CobrosBillingContract::MODE_ENTERPRISE : CobrosBillingContract::MODE_INDIVIDUAL, $modality, $lines, $total, $seats);
    }

    /**
     * Cotización de un contrato existente: aplica total negociado de alta,
     * `admin_included` (administración bonificada/incluida) y overrides
     * personalizados vigentes SOLO de ese contrato.
     */
    public function quoteForContract(CobrosBillingContract $contract): CobrosPriceQuote
    {
        $iso = strtoupper((string) $contract->country_iso);

        if ($contract->modality !== null && ! CobrosModalityCatalog::has($contract->modality)) {
            throw ValidationException::withMessages(['modality' => 'Modalidad CobrOS desconocida en el contrato.']);
        }

        if ($contract->modality !== null) {
            $lines = $this->modalityLines($iso, $contract->modality);
        } else {
            // Empresarial puro: administración + cupos.
            $lines = [new CobrosPriceLine(
                self::CONCEPT_ADMINISTRATION,
                'Administración del prestamista',
                $this->standardAmount($iso, self::CONCEPT_ADMINISTRATION),
                'standard',
            )];

            for ($position = 2; $position <= max(1, $contract->seats_total); $position++) {
                $lines[] = new CobrosPriceLine(
                    self::CONCEPT_COLLECTOR,
                    'Cupo de cobrador #'.$position,
                    $this->standardAmount($iso, self::CONCEPT_COLLECTOR),
                    'standard',
                );
            }
        }

        if ($contract->admin_included) {
            $lines = array_map(static function (CobrosPriceLine $line) use ($contract): CobrosPriceLine {
                if ($line->concept !== self::CONCEPT_ADMINISTRATION || bccomp($line->amount, '0', 4) === 0) {
                    return $line;
                }

                return new CobrosPriceLine(
                    $line->concept,
                    $line->label.' (bonificada/incluida en el contrato)',
                    '0.0000',
                    'included',
                );
            }, $lines);
        }

        // Total negociado registrado al crear el contrato (fuente de verdad):
        // la línea es informativa; NO se suma dos veces al total.
        $breakdownTotal = $this->sumLines(array_values(array_filter(
            $lines,
            static fn (CobrosPriceLine $line): bool => $line->concept !== self::CONCEPT_MONTHLY_TOTAL,
        )));

        if (bccomp((string) $contract->monthly_amount, $breakdownTotal, 4) !== 0) {
            $lines[] = new CobrosPriceLine(
                self::CONCEPT_MONTHLY_TOTAL,
                'Total mensual negociado en el contrato',
                (string) $contract->monthly_amount,
                'negotiated',
            );
        }

        $total = $this->normalizeAmount((string) $contract->monthly_amount);

        // Overrides personalizados vigentes: reemplazan línea o total.
        $overrides = $contract->priceOverrides()
            ->where('is_active', true)
            ->where('valid_from', '<=', today())
            ->where('valid_until', '>=', today())
            ->get();

        $monthlyTotalOverride = $overrides->firstWhere('concept', self::CONCEPT_MONTHLY_TOTAL);

        if ($monthlyTotalOverride !== null) {
            $lines[] = new CobrosPriceLine(
                self::CONCEPT_MONTHLY_TOTAL,
                'Precio personalizado autorizado',
                (string) $monthlyTotalOverride->amount,
                'override',
            );
            $total = $this->normalizeAmount((string) $monthlyTotalOverride->amount);
        } elseif ($overrides->isNotEmpty()) {
            $byConcept = $overrides->reject(static fn ($override) => $override->concept === self::CONCEPT_MONTHLY_TOTAL)
                ->keyBy('concept');

            $lines = array_map(static function (CobrosPriceLine $line) use ($byConcept): CobrosPriceLine {
                $override = $byConcept->get($line->concept);

                if ($override === null) {
                    return $line;
                }

                return new CobrosPriceLine(
                    $line->concept,
                    $line->label.' (precio personalizado)',
                    $override->amount !== null ? (string) $override->amount : $line->amount,
                    'override',
                );
            }, array_values($lines));

            // Con overrides por concepto el total es la suma de las líneas
            // (sin la línea informativa negociada).
            $total = $this->sumLines(array_values(array_filter(
                $lines,
                static fn (CobrosPriceLine $line): bool => $line->concept !== self::CONCEPT_MONTHLY_TOTAL
                    && $line->source !== 'negotiated',
            )));
        }

        return new CobrosPriceQuote(
            $iso,
            (string) $contract->currency,
            (string) $contract->billing_mode,
            $contract->modality,
            $lines,
            $total,
            max(1, (int) $contract->seats_total),
        );
    }

    /**
     * Líneas estándar por modalidad. Anti-duplicado: prestamista y
     * prestamista-cobrador pagan la administración UNA vez; la ruta del
     * prestamista-cobrador viene incluida (línea en ₡0/$0).
     *
     * @return list<CobrosPriceLine>
     */
    private function modalityLines(string $iso, string $modality): array
    {
        $administration = $this->standardAmount($iso, self::CONCEPT_ADMINISTRATION);

        if ($modality === CobrosModalityCatalog::PRESTAMISTA) {
            return [
                new CobrosPriceLine(self::CONCEPT_ADMINISTRATION, 'Administración del prestamista', $administration, 'standard'),
            ];
        }

        if ($modality === CobrosModalityCatalog::PRESTAMISTA_COBRADOR) {
            // Anti-duplicado (17A-1): UNA sola vez; la ruta va incluida en ₡0.
            return [
                new CobrosPriceLine(self::CONCEPT_ADMINISTRATION, 'Administración del prestamista', $administration, 'standard'),
                new CobrosPriceLine(self::CONCEPT_ROUTE, 'Ruta del prestamista-cobrador (incluida)', '0.0000', 'included'),
            ];
        }

        if ($modality === CobrosModalityCatalog::COBRADOR_VINCULADO
            || $modality === CobrosModalityCatalog::COBRADOR_INDEPENDIENTE) {
            return [
                new CobrosPriceLine(self::CONCEPT_COLLECTOR, 'Licencia de cobrador', $this->standardAmount($iso, self::CONCEPT_COLLECTOR), 'standard'),
            ];
        }

        throw ValidationException::withMessages([
            'modality' => 'La modalidad no tiene tarifa estándar habilitada.',
        ]);
    }

    /** @param  list<CobrosPriceLine>  $lines */
    private function sumLines(array $lines): string
    {
        $total = '0.0000';

        foreach ($lines as $line) {
            $total = bcadd($total, $line->amount, 4);
        }

        return $total;
    }

    private function normalizeAmount(string $amount): string
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $amount, $parts) !== 1) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser un importe positivo válido.']);
        }

        return $parts[1].'.'.str_pad($parts[2] ?? '', 4, '0');
    }
}
