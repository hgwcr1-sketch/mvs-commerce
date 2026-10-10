<?php

namespace App\Services\Cobros;

use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosReferralAttribution;
use App\Models\CobrosReferralBonusCredit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PRE-19B-1 — bonificaciones del programa (V1), SIN conexión a Stripe/SINPE:
 * se invocan cuando exista un pago legítimamente confirmado (webhook o
 * aprobación Maestro), nunca antes.
 *
 * Reglas:
 *  - 10 % (config cobros.referrals.bonus_percent) sobre el importe confirmado.
 *  - Registradas SOLO como saldo a favor (status=credit); jamás desembolso
 *    automático.
 *  - Referido comercial: beneficiario = dueño del código atribuido al alta.
 *  - Crecimiento: beneficiario SIEMPRE el titular del equipo (owner_user_id
 *    de la empresa del colaborador), nunca el emisor de la invitación.
 *  - Anti-duplicado global: una misma alta + mismo pago confiere UNA sola
 *    bonificación (índice único referred+confirmation y event_key).
 */
class ReferralBonusService
{
    public function percent(): string
    {
        return (string) config('cobros.referrals.bonus_percent', 10);
    }

    /** 10 % del importe confirmado, con precisión decimal (sin floats). */
    public function bonusAmount(string $confirmedAmount): string
    {
        return bcdiv(bcmul($confirmedAmount, $this->percent(), 8), '100', 4);
    }

    /** Evento canónico: beneficiario + alta + pago confirmado. */
    public function eventKey(string $type, int $beneficiaryId, int $referredId, int $confirmationId): string
    {
        return hash('sha256', implode('|', [$type, $beneficiaryId, $referredId, $confirmationId]));
    }

    /**
     * PRE-19B-3 — punto único de emisión tras un pago CONFIRMADO (Stripe o
     * SINPE). Solo el primer pago confirmado y elegible del contrato emite
     * bonificaciones; renovaciones y solicitudes pendientes jamás. Idempotente
     * por claves únicas persistidas (event_key y alta+pago).
     */
    public function onConfirmedPayment(CobrosPaymentConfirmation $confirmation): void
    {
        if ($confirmation->status !== CobrosPaymentConfirmation::STATUS_CONFIRMED
            || ! $this->isFirstConfirmedPayment($confirmation)) {
            return;
        }

        $payer = $confirmation->contract?->payer;

        // Referido comercial externo: beneficiario = dueño del enlace personal.
        $this->grantFirstPaymentBonus($confirmation);

        // Crecimiento del equipo: beneficiario = titular del equipo que
        // incorporó al colaborador (owner_user_id de la empresa).
        if ($payer !== null) {
            $this->grantGrowthCollaboratorBonus($payer, $confirmation);
        }
    }

    /**
     * Primer pago efectivamente confirmado del contrato: no existen otras
     * confirmaciones CONFIRMADAS anteriores (renovaciones incluidas).
     */
    public function isFirstConfirmedPayment(CobrosPaymentConfirmation $confirmation): bool
    {
        return ! CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $confirmation->cobros_billing_contract_id)
            ->where('status', CobrosPaymentConfirmation::STATUS_CONFIRMED)
            ->where('id', '<', $confirmation->id)
            ->exists();
    }

    /**
     * Referido comercial: bonifica al referente del pagador del contrato
     * (el alta premiada) en SU primer pago confirmado. null = no aplica o
     * ya bonificado (idempotente / anti-duplicado).
     */
    public function grantFirstPaymentBonus(CobrosPaymentConfirmation $confirmation): ?CobrosReferralBonusCredit
    {
        if ($confirmation->status !== CobrosPaymentConfirmation::STATUS_CONFIRMED
            || ! $this->isFirstConfirmedPayment($confirmation)) {
            return null;
        }

        $referred = $confirmation->contract?->payer;
        if ($referred === null) {
            return null;
        }

        $attribution = CobrosReferralAttribution::query()
            ->where('referred_user_id', $referred->id)
            ->first();
        if ($attribution === null) {
            return null;
        }

        return $this->persistCredit(
            CobrosReferralBonusCredit::TYPE_REFERRAL_FIRST_PAYMENT,
            (int) $attribution->referrer_user_id,
            $referred,
            $confirmation,
        );
    }

    /**
     * Crecimiento del cobrador independiente: bonifica al TITULAR del equipo
     * (owner_user_id de la empresa del colaborador) por la primera licencia
     * que el colaborador paga por sí mismo. El emisor de la invitación
     * laboral NUNCA es el beneficiario.
     */
    public function grantGrowthCollaboratorBonus(
        User $collaborator,
        CobrosPaymentConfirmation $confirmation,
    ): ?CobrosReferralBonusCredit {
        if ($confirmation->status !== CobrosPaymentConfirmation::STATUS_CONFIRMED
            || ! $this->isFirstConfirmedPayment($confirmation)) {
            return null;
        }

        $contract = $confirmation->contract;
        if ($contract === null
            || (int) $contract->payer_user_id !== (int) $collaborator->id) {
            return null;
        }

        $company = $contract->company;
        $beneficiaryId = (int) ($company?->owner_user_id ?? 0);
        if ($beneficiaryId <= 0 || $beneficiaryId === (int) $collaborator->id) {
            return null;
        }

        return $this->persistCredit(
            CobrosReferralBonusCredit::TYPE_GROWTH_COLLABORATOR,
            $beneficiaryId,
            $collaborator,
            $confirmation,
        );
    }

    private function persistCredit(
        string $type,
        int $beneficiaryId,
        User $referred,
        CobrosPaymentConfirmation $confirmation,
    ): ?CobrosReferralBonusCredit {
        $eventKey = $this->eventKey($type, $beneficiaryId, (int) $referred->id, (int) $confirmation->id);

        return DB::transaction(function () use ($type, $beneficiaryId, $referred, $confirmation, $eventKey) {
            $sameEvent = CobrosReferralBonusCredit::query()
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();
            if ($sameEvent !== null) {
                // Reintento del MISMO evento: idempotente, sin duplicar.
                return $sameEvent;
            }

            // Anti-duplicado global: la misma alta + el mismo pago ya confirió
            // OTRA bonificación (referido comercial O crecimiento, no ambas).
            $otherForSameAltaPayment = CobrosReferralBonusCredit::query()
                ->where('referred_user_id', $referred->id)
                ->where('source_confirmation_id', $confirmation->id)
                ->exists();
            if ($otherForSameAltaPayment) {
                return null;
            }

            return CobrosReferralBonusCredit::create([
                'company_id' => $confirmation->company_id,
                'beneficiary_user_id' => $beneficiaryId,
                'referred_user_id' => $referred->id,
                'type' => $type,
                'source_contract_id' => $confirmation->cobros_billing_contract_id,
                'source_confirmation_id' => $confirmation->id,
                'percent' => $this->percent(),
                'amount' => $this->bonusAmount((string) $confirmation->amount),
                'currency' => $confirmation->currency,
                'status' => CobrosReferralBonusCredit::STATUS_CREDIT,
                'event_key' => $eventKey,
            ]);
        });
    }
}
