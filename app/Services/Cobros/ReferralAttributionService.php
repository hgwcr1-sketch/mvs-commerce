<?php

namespace App\Services\Cobros;

use App\Models\CobrosReferralAttribution;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * PRE-19B-1 — atribución permanente del alta a su referente comercial.
 *
 * Reglas: un solo atributo por usuario referido (nunca se pisa ni duplica),
 * sin auto-referido y solo con código resoluble a un usuario activo. La
 * atribución es SOLO comercial: la incorporación laboral (invitación del
 * prestamista) se resuelve aparte y jamás crea esta fila.
 */
class ReferralAttributionService
{
    public function __construct(
        private readonly ReferralCodeService $codes,
    ) {
    }

    /**
     * Atribuye el alta al dueño del código. Idempotente: la primera
     * atribución de un referido es definitiva.
     */
    public function attribute(
        User $referred,
        string $code,
        ?Company $company = null,
        ?\DateTimeInterface $capturedAt = null,
    ): CobrosReferralAttribution {
        $referrer = $this->codes->resolve($code);
        if ($referrer === null) {
            throw ValidationException::withMessages(['referral' => 'El código de referido no es válido.']);
        }

        if ((int) $referrer->id === (int) $referred->id) {
            throw ValidationException::withMessages(['referral' => 'No es posible auto-referirse.']);
        }

        $existing = CobrosReferralAttribution::query()
            ->where('referred_user_id', $referred->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return CobrosReferralAttribution::create([
            'company_id' => $company?->id,
            'referred_user_id' => $referred->id,
            'referrer_user_id' => $referrer->id,
            'code' => $code,
            'captured_at' => $capturedAt ?? now(),
        ]);
    }
}
