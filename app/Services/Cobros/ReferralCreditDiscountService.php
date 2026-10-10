<?php

namespace App\Services\Cobros;

use App\Data\Cobros\ReferralCreditPlan;
use App\Models\CobrosBillingContract;
use App\Models\CobrosReferralBonusCredit;
use App\Models\CobrosReferralCreditReservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * PRE-19B-4A — cálculo SEGURO del descuento por saldo a favor de referidos.
 *
 * Solo servicio interno: NO modifica Checkout Stripe, SINPE ni activación.
 * Aritmética exacta en decimales (bcmath, escala 4), nunca float.
 *
 * Reglas:
 *  - Crédito aplicable = mínimo(total, saldo disponible).
 *  - Total a pagar   = máximo(0, total - crédito aplicable).
 *  - Saldo remanente = saldo disponible - crédito aplicable.
 *  - Propietario del saldo: el pagador del contrato (payer_user_id);
 *    jamás se aplica crédito de otra persona.
 *  - Empresa y moneda deben coincidir con las del bono.
 *  - Reserva/consumo idempotente por contrato/período/beneficiario: evita
 *    doble descuento SIN descontar el saldo definitivamente (el bono
 *    conserva status=credit hasta liquidación legítima en 4B).
 *  - Factura de ₡0 queda preparada para liquidación interna (sin pago
 *    externo) mediante la bandera requiereLiquidacionInterna.
 */
class ReferralCreditDiscountService
{
    public const SCALE = 4;

    public function __construct(
        private readonly CobrosBillingContractService $contracts,
    ) {
    }

    /**
     * Cálculo puro y reutilizable sobre montos ya normalizados.
     */
    public function calculate(string $total, string $available, string $currency): ReferralCreditPlan
    {
        $total = $this->normalize($total);
        $available = $this->normalize($available);

        if (bccomp($available, '0', self::SCALE) < 0) {
            $available = '0.'.str_repeat('0', self::SCALE);
        }

        $credit = bccomp($available, $total, self::SCALE) <= 0 ? $available : $total;
        $payable = bcsub($total, $credit, self::SCALE);
        if (bccomp($payable, '0', self::SCALE) < 0) {
            $payable = '0.'.str_repeat('0', self::SCALE);
        }
        $remaining = bcsub($available, $credit, self::SCALE);

        return new ReferralCreditPlan(
            totalOriginal: $total,
            saldoDisponible: $available,
            creditoAplicable: $credit,
            totalAPagar: $payable,
            saldoRemanente: $remaining,
            currency: strtoupper($currency),
            requiereLiquidacionInterna: bccomp($payable, '0', self::SCALE) === 0,
        );
    }

    /**
     * Saldo a favor DISPONIBLE de un beneficiario en una empresa/moneda:
     * bonos status=credit menos reservas activas. Propietario, empresa y
     * moneda validados por el ámbito de la consulta.
     */
    public function availableBalance(int $companyId, int $beneficiaryUserId, string $currency): string
    {
        $credit = (string) CobrosReferralBonusCredit::query()
            ->where('company_id', $companyId)
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->where('currency', strtoupper($currency))
            ->where('status', CobrosReferralBonusCredit::STATUS_CREDIT)
            ->sum('amount');

        $reserved = (string) CobrosReferralCreditReservation::query()
            ->where('company_id', $companyId)
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->where('currency', strtoupper($currency))
            ->whereIn('status', [
                CobrosReferralCreditReservation::STATUS_RESERVED,
                CobrosReferralCreditReservation::STATUS_SETTLED,
            ])
            ->sum('amount');

        $balance = bcsub($this->normalize($credit), $this->normalize($reserved), self::SCALE);

        return bccomp($balance, '0', self::SCALE) < 0 ? '0.'.str_repeat('0', self::SCALE) : $balance;
    }

    /**
     * Plan de descuento para la mensualidad vigente de un contrato.
     * El saldo consultado es SIEMPRE del pagador del contrato.
     */
    public function plan(CobrosBillingContract $contract, int $periodYear, int $periodMonth): ReferralCreditPlan
    {
        $this->assertValidPeriod($periodYear, $periodMonth);

        $total = $this->contracts->monthlyTotal($contract);
        $available = $this->availableBalance(
            (int) $contract->company_id,
            (int) $contract->payer_user_id,
            (string) $contract->currency,
        );

        return $this->calculate($total, $available, (string) $contract->currency);
    }

    /**
     * Reserva idempotente de crédito hacia un contrato/período. Si ya existe
     * reserva para esa clave, devuelve las filas existentes sin crear más.
     * El bono NO se consume definitivamente (status=credit intacto): solo se
     * compromete para evitar doble descuento hasta la liquidación de 4B.
     *
     * @return Collection<int, CobrosReferralCreditReservation>
     */
    public function reserve(CobrosBillingContract $contract, int $periodYear, int $periodMonth): Collection
    {
        $this->assertValidPeriod($periodYear, $periodMonth);

        $beneficiaryId = (int) $contract->payer_user_id;
        $companyId = (int) $contract->company_id;
        $currency = strtoupper((string) $contract->currency);

        $existing = CobrosReferralCreditReservation::query()
            ->where('cobros_billing_contract_id', $contract->getKey())
            ->where('beneficiary_user_id', $beneficiaryId)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->where('status', CobrosReferralCreditReservation::STATUS_RESERVED)
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $plan = $this->plan($contract, $periodYear, $periodMonth);
        if (bccomp($plan->creditoAplicable, '0', self::SCALE) <= 0) {
            return collect();
        }

        $target = $plan->creditoAplicable;
        $eligible = CobrosReferralBonusCredit::query()
            ->where('company_id', $companyId)
            ->where('beneficiary_user_id', $beneficiaryId)
            ->where('currency', $currency)
            ->where('status', CobrosReferralBonusCredit::STATUS_CREDIT)
            ->whereNotIn('id', function ($query) use ($companyId, $beneficiaryId, $currency) {
                $query->select('bonus_credit_id')
                    ->from('cobros_referral_credit_reservations')
                    ->where('company_id', $companyId)
                    ->where('beneficiary_user_id', $beneficiaryId)
                    ->where('currency', $currency)
                    ->where('status', CobrosReferralCreditReservation::STATUS_RESERVED);
            })
            ->orderBy('id')
            ->get();

        $reservations = collect();
        foreach ($eligible as $bonus) {
            if (bccomp($target, '0', self::SCALE) <= 0) {
                break;
            }

            $bonusAmount = $this->normalize((string) $bonus->amount);
            $take = bccomp($bonusAmount, $target, self::SCALE) <= 0 ? $bonusAmount : $target;

            try {
                $reservations->push(CobrosReferralCreditReservation::create([
                    'company_id' => $companyId,
                    'beneficiary_user_id' => $beneficiaryId,
                    'cobros_billing_contract_id' => $contract->getKey(),
                    'bonus_credit_id' => $bonus->getKey(),
                    'period_year' => $periodYear,
                    'period_month' => $periodMonth,
                    'amount' => $take,
                    'currency' => $currency,
                    'status' => CobrosReferralCreditReservation::STATUS_RESERVED,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Carrera: otra reserva tomó este bono; se omite y se sigue.
            }

            $target = bcsub($target, $take, self::SCALE);
        }

        return $reservations;
    }

    /**
     * PRE-19B-4B — saldo comprometido (reservado o liquidado) de UN período
     * concreto de un contrato. Base del importe neto cobrable.
     */
    public function reservedForPeriod(CobrosBillingContract $contract, int $periodYear, int $periodMonth): string
    {
        $sum = (string) CobrosReferralCreditReservation::query()
            ->where('cobros_billing_contract_id', $contract->getKey())
            ->where('beneficiary_user_id', (int) $contract->payer_user_id)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->whereIn('status', [
                CobrosReferralCreditReservation::STATUS_RESERVED,
                CobrosReferralCreditReservation::STATUS_SETTLED,
            ])
            ->sum('amount');

        return $this->normalize($sum);
    }

    /**
     * PRE-19B-4B — importe NETO a cobrar por un período: total vigente menos
     * crédito ya reservado/liquidado para ese período, con suelo en cero.
     * Es la única fuente del importe que usan Checkout/SINPE/webhook.
     */
    public function netAmountForPeriod(CobrosBillingContract $contract, int $periodYear, int $periodMonth): string
    {
        $total = $this->normalize($this->contracts->monthlyTotal($contract));
        $reserved = $this->reservedForPeriod($contract, $periodYear, $periodMonth);

        $net = bcsub($total, $reserved, self::SCALE);

        return bccomp($net, '0', self::SCALE) < 0 ? '0.'.str_repeat('0', self::SCALE) : $net;
    }

    /**
     * PRE-19B-4B — consumo idempotente de reservas al confirmar el pago
     * legítimo. Solo marca reserved→settled; los bonos siguen intactos y un
     * reintento no duplica nada. Devuelve cuántas reservas pasaron a settled.
     */
    public function settleReservations(CobrosBillingContract $contract, int $periodYear, int $periodMonth): int
    {
        return CobrosReferralCreditReservation::query()
            ->where('cobros_billing_contract_id', $contract->getKey())
            ->where('beneficiary_user_id', (int) $contract->payer_user_id)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->where('status', CobrosReferralCreditReservation::STATUS_RESERVED)
            ->update(['status' => CobrosReferralCreditReservation::STATUS_SETTLED]);
    }

    private function normalize(string $amount): string
    {
        return bcadd(trim($amount), '0', self::SCALE);
    }

    private function assertValidPeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2100) {
            throw ValidationException::withMessages([
                'period' => 'Período inválido para aplicar saldo a favor.',
            ]);
        }
    }
}
