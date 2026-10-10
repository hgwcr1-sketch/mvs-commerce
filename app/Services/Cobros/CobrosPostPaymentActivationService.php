<?php

namespace App\Services\Cobros;

use App\Models\CobrosPaymentConfirmation;
use App\Models\CobrosRegistrationDraft;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 17A-2 f2 — activación posterior al pago (orquestación).
 *
 * Conecta EXCLUSIVAMENTE una confirmación de pago válida con la ejecución
 * del borrador correspondiente. Reutiliza CobrosPaymentConfirmationService
 * (confirmación), CobrosActivationContractService (contrato + execute) y
 * CompanyProvisioner (empresa) sin reconstruirlos.
 *
 * Reglas:
 *  - Solo una confirmación CONFIRMADA activa el borrador.
 *  - La activación es idempotente: un pago repetido no duplica usuarios,
 *    empresas, rutas, vínculos ni licencias.
 *  - Si el aprovisionamiento falla, la transacción revierte: el pago
 *    confirmado NO se pierde y el estado queda recuperable para reintento.
 *  - No se integra Stripe/SINPE ni se crean endpoints públicos aquí.
 */
class CobrosPostPaymentActivationService
{
    public function __construct(
        private CobrosPaymentConfirmationService $payments,
        private CobrosActivationContractService $activation,
    ) {
    }

    /**
     * Activa el borrador correspondiente a partir de una confirmación de
     * pago CONFIRMADA. Idempotente: un reintento no duplica nada.
     *
     * @return array{user: \App\Models\User, company: \App\Models\Company, route: \App\Models\CobrosRoute}
     */
    public function activateFromPayment(
        CobrosPaymentConfirmation $confirmation,
        CobrosRegistrationDraft $draft,
    ): array {
        // La confirmación debe estar CONFIRMADA (pagada).
        if ($confirmation->status !== CobrosPaymentConfirmation::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'payment' => 'La activación requiere una confirmación de pago confirmada; '
                    .'la referencia proporcionada no está en estado pagado.',
            ]);
        }

        // Idempotencia: si el borrador ya fue activado, devuelve el resultado
        // previo SIN re-validar (el correo ya existe es esperado en reintentos).
        $already = $this->activation->findAlreadyActivatedPublic($draft);
        if ($already !== null) {
            return $already;
        }

        // Prepara el contrato (valida borrador completo + invitación real).
        try {
            $contract = $this->activation->prepare($draft);
        } catch (\Throwable $e) {
            Log::warning('activateFromPayment: prepare failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Marca el contrato como pago confirmado (inmutable: nueva instancia).
        $confirmed = $contract->withPaymentConfirmed();

        // Ejecuta la activación (idempotente, transaccional).
        try {
            return $this->activation->execute($confirmed);
        } catch (\Throwable $e) {
            Log::warning('activateFromPayment: execute failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
