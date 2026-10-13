<?php

namespace App\Services\Cobros;

use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\User;

/**
 * BLOQUE 17B-6A — lógica interna de vigencia y renovaciones mensuales.
 *
 * Alcance: solo servicios y pruebas. Sin cron, rutas, interfaces ni cobros
 * automáticos todavía. Reutiliza MVS Billing existente:
 *  - períodos pagados: CobrosPaymentConfirmation (confirmado por contrato/período);
 *  - vigencia: contrato valid_from/valid_until + licencia CobrosCollectorLicense;
 *  - renovación: CobrosPaymentConfirmationService::applyRenewal (solo tras
 *    pago legítimo: Stripe webhook verificado o SINPE aprobado por Maestro);
 *  - bloqueo: CollectorLicenseService (estados de licencia).
 *
 * Visibilidad (17B-6A r7): precios, vencimientos y membresías solo del
 * pagador; el cobrador cuya ruta paga el prestamista recibe únicamente un
 * aviso genérico de bloqueo, sin montos ni fechas financieras.
 */
class CobrosMembershipLifecycleService
{
    /** Días de anticipación para el estado «próxima a vencer». */
    public const EXPIRY_WARNING_DAYS = 7;

    public const STATUS_VIGENTE = 'vigente';

    public const STATUS_PROXIMA_A_VENCER = 'proxima_a_vencer';

    public const STATUS_VENCIDA = 'vencida';

    public const STATUS_PENDIENTE_DE_PAGO = 'pendiente_de_pago';

    public function __construct(
        private readonly CollectorLicenseService $licenses,
    ) {
    }

    /**
     * Evaluación de membresía de un contrato. Devuelve:
     *  - status: vigente | proxima_a_vencer | vencida | pendiente_de_pago;
     *  - expires_on: vencimiento efectivo (contrato ∩ licencia) o null;
     *  - has_pending_request: existe solicitud de pago pendiente.
     *
     * Nunca incluye montos ni datos financieros.
     */
    public function evaluate(CobrosBillingContract $contract): array
    {
        $today = now()->startOfDay();
        $validUntil = $contract->valid_until?->endOfDay();

        // 1) Vencida: contrato cerrado/cancelado o fuera de vigencia contractual.
        if (! $contract->isOpen() || ($validUntil !== null && $today->gt($validUntil))) {
            return [
                'status' => self::STATUS_VENCIDA,
                'expires_on' => $contract->valid_until?->toDateString(),
                'has_pending_request' => false,
            ];
        }

        // 2) Último período con pago confirmado legítimamente.
        $lastConfirmed = CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', CobrosPaymentConfirmation::STATUS_CONFIRMED)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        $coversCurrent = $lastConfirmed !== null && (
            $lastConfirmed->period_year > now()->year
            || ($lastConfirmed->period_year === now()->year && $lastConfirmed->period_month >= now()->month)
        );

        // 3) Pendiente de pago: período actual sin confirmar.
        if (! $coversCurrent) {
            return [
                'status' => self::STATUS_PENDIENTE_DE_PAGO,
                'expires_on' => null,
                'has_pending_request' => CobrosPaymentConfirmation::query()
                    ->where('cobros_billing_contract_id', $contract->id)
                    ->where('status', CobrosPaymentConfirmation::STATUS_PENDING)
                    ->exists(),
            ];
        }

        // 4) Vigencia efectiva: mínima entre contrato y licencia (individual).
        $expiry = $validUntil;
        $payer = $contract->payer;
        $license = ($contract->billing_mode === CobrosBillingContract::MODE_INDIVIDUAL && $payer !== null)
            ? $this->licenses->licenseFor($payer, $contract->company)
            : null;

        if ($license?->expires_at !== null) {
            $expiry = $expiry === null ? $license->expires_at : $expiry->min($license->expires_at);
        }

        if ($expiry !== null && $expiry->lte($today)) {
            return [
                'status' => self::STATUS_VENCIDA,
                'expires_on' => $expiry->toDateString(),
                'has_pending_request' => false,
            ];
        }

        if ($expiry !== null && $expiry->lte($today->copy()->addDays(self::EXPIRY_WARNING_DAYS))) {
            return [
                'status' => self::STATUS_PROXIMA_A_VENCER,
                'expires_on' => $expiry->toDateString(),
                'has_pending_request' => false,
            ];
        }

        return [
            'status' => self::STATUS_VIGENTE,
            'expires_on' => $expiry?->toDateString(),
            'has_pending_request' => false,
        ];
    }

    /**
     * 17B-6A r8 — Bloqueo operativo ante vencimiento/suspensión: aviso
     * genérico SIN montos, fechas ni datos financieros. null = puede operar.
     */
    public function blockingStateFor(User $user, Company $company): ?array
    {
        if ($this->licenses->hasValidLicense($user, $company)) {
            return null;
        }

        return [
            'blocked' => true,
            'reason' => $this->licenses->stateOf($this->licenses->licenseFor($user, $company), $company),
            'message' => 'Tu membresía CobrOS no está vigente. Contacta a tu prestamista para continuar.',
        ];
    }

    /**
     * 17B-6A r7 — Visibilidad: precios, vencimientos y membresías solo para el
     * pagador del contrato. El cobrador cuya ruta paga el prestamista nunca
     * los ve (no se infiere de permisos ordinarios: es el payer persistido).
     */
    public function canViewMembership(User $user, CobrosBillingContract $contract): bool
    {
        return (int) $contract->payer_user_id === (int) $user->id;
    }
}
