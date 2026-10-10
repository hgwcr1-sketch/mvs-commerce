<?php

namespace App\Services\Cobros;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosRegistrationDraft;
use App\Models\User;
use App\Services\Billing\Contracts\PaymentConfirmedHandlerInterface;
use App\Services\Billing\DTOs\PaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 17B-3A — handler de pagos confirmados para CobrOS.
 *
 * Conecta los eventos Stripe confirmados (vía Billing genérico) con la
 * activación automática de CobrOS (CobrosPostPaymentActivationService).
 * NO introduce modelos CobrOS en el motor genérico de Billing.
 */
class CobrosPaymentConfirmedHandler implements PaymentConfirmedHandlerInterface
{
    public function __construct(
        private readonly CobrosPaymentConfirmationService $confirmationService,
        private readonly CobrosPostPaymentActivationService $activationService,
    ) {
    }

    public function supports(string $product): bool
    {
        return $product === 'cobros';
    }

    public function handle(PaymentEvent $event): void
    {
        // Solo procesar eventos confirmados de Stripe para CobrOS
        if ($event->provider !== 'stripe' || ! $event->isConfirmed()) {
            return;
        }

        // Validar datos mínimos requeridos
        if ($event->companyId === '' || $event->contractReference === '' || $event->externalId === '') {
            return;
        }

        // Buscar el contrato de facturación CobrOS por company_id + contract_reference
        $contract = CobrosBillingContract::query()
            ->where('company_id', $event->companyId)
            ->where('id', $event->contractReference)
            ->whereIn('status', CobrosBillingContract::OPEN_STATUSES)
            ->first();

        if ($contract === null) {
            return;
        }

        // Verificar que el importe y moneda coinciden con el contrato
        $expectedAmount = $this->getContractMonthlyAmount($contract);
        if (bccomp($event->amount, $expectedAmount, 4) !== 0) {
            return;
        }

        if (strtoupper($event->currency) !== strtoupper($contract->currency)) {
            return;
        }

        // Buscar el borrador de registro correspondiente
        $draft = $this->findDraftForContract($contract);
        if ($draft === null) {
            return;
        }

        // Obtener o crear confirmación de pago CobrOS (idempotente por transaction_id)
        // Usamos el payer del contrato como actor (responsable de pago)
        $actor = $contract->payer;
        if ($actor === null) {
            return;
        }

        $confirmation = $this->confirmPaymentForContract(
            $contract,
            $actor,
            $event->externalId, // payment_intent.id de Stripe
            $event->amount,
            $event->periodYear ?? 0,
            $event->periodMonth ?? 0,
        );

        if ($confirmation === null) {
            return;
        }

        // Activar el borrador desde la confirmación de pago
        try {
            $this->activationService->activateFromPayment($confirmation, $draft);
        } catch (ValidationException) {
            // Si falla la activación, la confirmación de pago queda CONFIRMADA
            // y el estado es recuperable para reintento interno.
            // No lanzamos excepción para no romper el webhook Stripe.
        }
    }

    /** Busca el borrador de registro asociado al contrato. */
    private function findDraftForContract(CobrosBillingContract $contract): ?CobrosRegistrationDraft
    {
        // 1) Vínculo directo persistente (billing_contract_id en el borrador)
        $draft = CobrosRegistrationDraft::query()
            ->where('billing_contract_id', $contract->id)
            ->latest('last_saved_at')
            ->first();

        if ($draft !== null) {
            return $draft;
        }

        // 2) Fallback seguro: modalidad cobrador_vinculado -> join con invitaciones por company_id
        //    El borrador guarda labor_invitation.id en payload; la invitación tiene company_id.
        //    Validamos aislamiento: la invitación debe pertenecer a la misma empresa del contrato.
        $draft = CobrosRegistrationDraft::query()
            ->whereNull('billing_contract_id')
            ->whereRaw(
                "EXISTS (
                    SELECT 1 FROM cobros_labor_invitations i
                    WHERE i.id = CAST(json_extract(cobros_registration_drafts.payload, '$.labor_invitation.id') AS INTEGER)
                    AND i.company_id = ?
                )",
                [$contract->company_id]
            )
            ->latest('last_saved_at')
            ->first();

        if ($draft !== null) {
            // Establecer vínculo persistente para próximas consultas
            $draft->update(['billing_contract_id' => $contract->id]);
            return $draft;
        }

        // Sin asociación verificable: bloquear activación (requiere migración segura).
        // No usamos trade_name: no es único ni verificable entre empresas.
        return null;
    }

    /** Crea o recupera la confirmación de pago CobrOS (idempotente por transaction_id). */
    private function confirmPaymentForContract(
        CobrosBillingContract $contract,
        User $actor,
        string $transactionId,
        string $amount,
        int $periodYear,
        int $periodMonth,
    ): ?CobrosPaymentConfirmation {
        try {
            // Usar el método confirm (no confirmInternal) para evitar la restricción de platform admin
            // El actor es el payer del contrato (responsable de pago legítimo)
            return $this->confirmationService->confirm(
                $contract,
                $actor,
                $transactionId,
                $amount,
                $periodYear,
                $periodMonth,
                'stripe',
            );
        } catch (ValidationException $e) {
            // Si ya existe una confirmación para este transaction_id, buscarla
            if (str_contains($e->getMessage(), 'transacción')) {
                return CobrosPaymentConfirmation::query()
                    ->where('transaction_id', $transactionId)
                    ->first();
            }
            // Re-lanzar otras ValidationException para que no fallen silenciosamente
            throw $e;
        }
    }

    /** Obtiene el total mensual vigente del contrato (string decimal 4 dígitos). */
    private function getContractMonthlyAmount(CobrosBillingContract $contract): string
    {
        return app(\App\Services\Cobros\CobrosBillingContractService::class)
            ->monthlyTotal($contract);
    }
}