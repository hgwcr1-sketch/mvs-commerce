<?php

namespace App\Services\Cobros;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * PRE-19B-1 — enlace personal de referido (?ref= propio).
 *
 * El código vive en users.referral_code (único); resolve() solo devuelve
 * usuarios activos. No otorga beneficios por sí mismo: la bonificación
 * exige atribución persistida y pago confirmado (ReferralBonusService).
 */
class ReferralCodeService
{
    /** Mismo formato del ?ref= ya aceptado en la landing pública. */
    public const PATTERN = '/\A[A-Za-z0-9_-]{4,16}\z/';

    /** Genera (una vez) y devuelve el código personal del usuario. */
    public function codeFor(User $user): string
    {
        if (filled($user->referral_code)) {
            return (string) $user->referral_code;
        }

        do {
            $code = 'MVS'.Str::upper(Str::random(9));
        } while (User::query()->where('referral_code', $code)->exists());

        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /** Resuelve el dueño activo del código; null si no existe o es inválido. */
    public function resolve(?string $code): ?User
    {
        if (! is_string($code) || preg_match(self::PATTERN, $code) !== 1) {
            return null;
        }

        return User::query()
            ->where('referral_code', $code)
            ->where('is_active', true)
            ->first();
    }
}
