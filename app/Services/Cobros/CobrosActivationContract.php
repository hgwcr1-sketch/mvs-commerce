<?php

namespace App\Services\Cobros;

/**
 * BLOQUE 16C-2B f2 — contrato inmutable de la activación FUTURA del borrador.
 *
 * Es solo el plan que la fase de activación (bloque 17, tras la confirmación
 * de pago) deberá ejecutar transaccionalmente. No crea ni modifica empresas,
 * usuarios, membresías, rutas, licencias ni invitaciones: es un valor
 * derivado del borrador validado por CobrosRegistrationActivationService.
 */
final class CobrosActivationContract
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    /**
     * @param  list<array{draft_route_id: string, name: string, code: ?string, lender: ?string, uploads: list<array<string, mixed>>}>  $routes
     * @param  array{invitation_id: int, company_id: int, lender_user_id: int, lender_email: string, lender_name: string, recipient_email: string}|null  $linkage
     * @param  array{code: string, captured_at: ?string}|null  $referral
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly int $draftId,
        public readonly string $email,
        public readonly string $countryIso,
        public readonly string $modality,
        public readonly string $status,
        public readonly bool $paymentConfirmed,
        public readonly array $routes,
        public readonly ?array $linkage,
        public readonly ?array $referral,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'draft_id' => $this->draftId,
            'email' => $this->email,
            'country_iso' => $this->countryIso,
            'modality' => $this->modality,
            'status' => $this->status,
            'payment_confirmed' => $this->paymentConfirmed,
            'routes' => $this->routes,
            'linkage' => $this->linkage,
            'referral' => $this->referral,
        ];
    }
}
