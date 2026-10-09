<?php

namespace App\Services\Cobros;

use App\Models\CobrosBillingContract;
use App\Models\CobrosContractSeat;
use App\Models\CobrosPriceOverride;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 17A-1 — contratos de facturación CobrOS (preparación, sin activar).
 *
 * Contratos INDIVIDUAL y EMPRESARIAL con UN único responsable de pago,
 * cupos, total mensual (estándar o negociado) y precios personalizados
 * auditables por contrato. NO crea licencias, rutas ni cuenta: no cobra ni
 * activa (17A-2). MIXTA queda bloqueada hasta habilitarse en Panel Maestro.
 */
class CobrosBillingContractService
{
    public function __construct(
        private readonly CobrosPricingService $pricing,
    ) {}

    /** Bandera del Panel Maestro: modalidad de facturación MIXTA. */
    public function mixedEnabled(): bool
    {
        return (bool) config('cobros.billing.mixed_enabled', false);
    }

    /** Solo Platform Admin puede administrar tarifas/contratos (Panel Maestro). */
    public function assertCanManagePricing(User $actor): void
    {
        abort_unless($actor->isPlatformAdmin(), 403, 'Solo el administrador de plataforma puede administrar precios CobrOS.');
    }

    /**
     * Contrato INDIVIDUAL: el cobrador (o el prestamista) es el único
     * responsable de pago; 1 cupo; total = tarifa estándar de la modalidad
     * o el total negociado indicado.
     */
    public function createIndividual(
        Company $company,
        string $modality,
        string $countryIso,
        User $payer,
        CarbonInterface $validFrom,
        CarbonInterface $validUntil,
        User $actor,
        ?string $negotiatedTotal = null,
        ?string $reason = null,
        ?string $notes = null,
    ): CobrosBillingContract {
        $this->assertCanManagePricing($actor);
        $this->assertValidTerm($validFrom, $validUntil);
        $this->assertOpenContractAbsent($company);

        $quote = $this->pricing->quote($countryIso, $modality, 1, $negotiatedTotal);

        return $this->persistContract($company, $actor, CobrosBillingContract::MODE_INDIVIDUAL, $modality, $quote, $payer, 1, $validFrom, $validUntil, $negotiatedTotal !== null ? $reason : null, $notes);
    }

    /**
     * Contrato EMPRESARIAL: el prestamista paga UNA sola factura mensual por
     * N cupos (precio estándar o negociado). Los cupos se crean disponibles;
     * asignar un cupo no crea licencias todavía.
     */
    public function createEnterprise(
        Company $company,
        int $seats,
        string $countryIso,
        User $payer,
        CarbonInterface $validFrom,
        CarbonInterface $validUntil,
        User $actor,
        ?string $negotiatedTotal = null,
        ?string $reason = null,
        ?string $notes = null,
    ): CobrosBillingContract {
        $this->assertCanManagePricing($actor);
        $this->assertValidTerm($validFrom, $validUntil);
        $this->assertOpenContractAbsent($company);

        if ($seats < 1) {
            throw ValidationException::withMessages(['seats' => 'Un contrato empresarial incluye al menos un cupo.']);
        }

        $quote = $this->pricing->quote($countryIso, CobrosModalityCatalog::PRESTAMISTA, $seats, $negotiatedTotal);

        return $this->persistContract($company, $actor, CobrosBillingContract::MODE_ENTERPRISE, null, $quote, $payer, $seats, $validFrom, $validUntil, $negotiatedTotal !== null ? $reason : null, $notes);
    }

    /**
     * Modalidad MIXTA: desactivada por defecto; solo habilitable desde el
     * Panel Maestro (config/cobros.billing.mixed_enabled). Mientras esté
     * apagada, el servidor la rechaza aunque la interfaz la ofreciera.
     */
    public function createMixed(
        Company $company,
        int $seats,
        string $countryIso,
        User $payer,
        CarbonInterface $validFrom,
        CarbonInterface $validUntil,
        User $actor,
    ): CobrosBillingContract {
        if (! $this->mixedEnabled()) {
            throw ValidationException::withMessages([
                'billing_mode' => 'La modalidad de facturación mixta no está habilitada.',
            ]);
        }

        $this->assertCanManagePricing($actor);
        $this->assertValidTerm($validFrom, $validUntil);
        $this->assertOpenContractAbsent($company);

        if ($seats < 1) {
            throw ValidationException::withMessages(['seats' => 'Un contrato mixto incluye al menos un cupo.']);
        }

        $quote = $this->pricing->quote($countryIso, CobrosModalityCatalog::PRESTAMISTA, $seats);

        return $this->persistContract($company, $actor, CobrosBillingContract::MODE_MIXED, null, $quote, $payer, $seats, $validFrom, $validUntil, null, null);
    }

    /**
     * Precio personalizado POR CONTRATO, con autorización auditada (quién,
     * cuándo y por qué). Aplica solo a ese contrato: nunca a otros.
     */
    public function authorizeOverride(
        CobrosBillingContract $contract,
        string $concept,
        string $amount,
        string $reason,
        CarbonInterface $validFrom,
        CarbonInterface $validUntil,
        User $actor,
    ): CobrosPriceOverride {
        $this->assertCanManagePricing($actor);
        $this->assertValidTerm($validFrom, $validUntil);

        if (! in_array($concept, CobrosPriceOverride::CONCEPTS, true)) {
            throw ValidationException::withMessages(['concept' => 'Concepto de precio personalizado desconocido.']);
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Indique el motivo del precio personalizado (mínimo 5 caracteres).']);
        }

        // Normaliza/valida el monto con precisión decimal (sin float).
        $normalized = $this->normalize($amount);

        return DB::transaction(function () use ($contract, $concept, $normalized, $reason, $validFrom, $validUntil, $actor) {
            CobrosBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            return CobrosPriceOverride::create([
                'billing_contract_id' => $contract->id,
                'company_id' => $contract->company_id,
                'concept' => $concept,
                'currency' => $contract->currency,
                'amount' => $normalized,
                'valid_from' => $validFrom->toDateString(),
                'valid_until' => $validUntil->toDateString(),
                'is_active' => true,
                'authorized_by_user_id' => $actor->id,
                'authorized_at' => now(),
                'reason' => trim($reason),
            ]);
        });
    }

    /** Asigna el siguiente cupo disponible al cobrador (sin crear licencias). */
    public function assignSeat(CobrosBillingContract $contract, User $collector): CobrosContractSeat
    {
        if ($contract->billing_mode === CobrosBillingContract::MODE_INDIVIDUAL) {
            throw ValidationException::withMessages(['contract' => 'El contrato individual no tiene cupos adicionales.']);
        }

        return DB::transaction(function () use ($contract, $collector) {
            $taken = CobrosContractSeat::query()
                ->where('billing_contract_id', $contract->id)
                ->where('user_id', $collector->id)
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['collector' => 'El cobrador ya ocupa un cupo en este contrato.']);
            }

            $seat = CobrosContractSeat::query()
                ->where('billing_contract_id', $contract->id)
                ->where('status', CobrosContractSeat::STATUS_AVAILABLE)
                ->orderBy('position')
                ->lockForUpdate()
                ->first();

            if ($seat === null) {
                throw ValidationException::withMessages(['seats' => 'No quedan cupos disponibles en este contrato.']);
            }

            $seat->update([
                'status' => CobrosContractSeat::STATUS_ASSIGNED,
                'user_id' => $collector->id,
                'assigned_at' => now(),
            ]);

            return $seat->refresh();
        });
    }

    /** Libera el cupo del cobrador; el contrato y sus precios no cambian. */
    public function releaseSeat(CobrosBillingContract $contract, User $collector): void
    {
        DB::transaction(function () use ($contract, $collector) {
            $seat = CobrosContractSeat::query()
                ->where('billing_contract_id', $contract->id)
                ->where('user_id', $collector->id)
                ->where('status', CobrosContractSeat::STATUS_ASSIGNED)
                ->lockForUpdate()
                ->first();

            if ($seat === null) {
                throw ValidationException::withMessages(['collector' => 'El cobrador no ocupa un cupo en este contrato.']);
            }

            $seat->update([
                'status' => CobrosContractSeat::STATUS_AVAILABLE,
                'user_id' => null,
                'assigned_at' => null,
            ]);
        });
    }

    public function seatsRemaining(CobrosBillingContract $contract): int
    {
        $assigned = CobrosContractSeat::query()
            ->where('billing_contract_id', $contract->id)
            ->where('status', CobrosContractSeat::STATUS_ASSIGNED)
            ->count();

        return max(0, (int) $contract->seats_total - $assigned);
    }

    /**
     * Total mensual vigente del contrato (motor de precios + overrides).
     * El dinero es decimal string: nunca float.
     */
    public function monthlyTotal(CobrosBillingContract $contract): string
    {
        return $this->pricing->quoteForContract($contract)->monthlyTotal;
    }

    private function persistContract(
        Company $company,
        User $actor,
        string $billingMode,
        ?string $modality,
        \App\Data\Cobros\CobrosPriceQuote $quote,
        User $payer,
        int $seats,
        CarbonInterface $validFrom,
        CarbonInterface $validUntil,
        ?string $reason,
        ?string $notes,
    ): CobrosBillingContract {
        if (! $payer->is_active) {
            throw ValidationException::withMessages(['payer' => 'El responsable de pago debe ser una cuenta activa.']);
        }

        return DB::transaction(function () use ($company, $actor, $billingMode, $modality, $quote, $payer, $seats, $validFrom, $validUntil, $reason, $notes) {
            $contract = CobrosBillingContract::create([
                'company_id' => $company->id,
                'billing_mode' => $billingMode,
                'modality' => $modality,
                'country_iso' => $quote->countryIso,
                'currency' => $quote->currency,
                'status' => CobrosBillingContract::STATUSES[0], // draft: sin activar ni facturar
                'payer_user_id' => $payer->id,
                'seats_total' => $seats,
                'monthly_amount' => $quote->monthlyTotal,
                'admin_included' => false,
                'valid_from' => $validFrom->toDateString(),
                'valid_until' => $validUntil->toDateString(),
                'authorized_by_user_id' => $actor->id,
                'authorized_at' => now(),
                'authorization_reason' => $reason,
                'notes' => $notes,
            ]);

            foreach (range(1, $seats) as $position) {
                CobrosContractSeat::create([
                    'billing_contract_id' => $contract->id,
                    'position' => $position,
                    'status' => CobrosContractSeat::STATUS_AVAILABLE,
                ]);
            }

            return $contract->load('seats');
        });
    }

    /** Un solo contrato abierto (draft/active) por empresa: sin duplicados. */
    private function assertOpenContractAbsent(Company $company): void
    {
        $open = CobrosBillingContract::query()
            ->where('company_id', $company->id)
            ->whereIn('status', CobrosBillingContract::OPEN_STATUSES)
            ->lockForUpdate()
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'company' => 'La empresa ya tiene un contrato de facturación abierto; modifíquelo o cancélalo antes de crear otro.',
            ]);
        }
    }

    private function assertValidTerm(CarbonInterface $validFrom, CarbonInterface $validUntil): void
    {
        if ($validUntil->lte($validFrom)) {
            throw ValidationException::withMessages(['valid_until' => 'La vigencia del acuerdo debe terminar después de su inicio.']);
        }
    }

    private function normalize(string $amount): string
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $amount, $parts) !== 1) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser un importe positivo válido.']);
        }

        return $parts[1].'.'.str_pad($parts[2] ?? '', 4, '0');
    }
}
