<?php

namespace App\Services\Cobros;

use App\Models\CobrosLaborInvitation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LaborInvitationService
{
    /** The plaintext is returned once, never persisted. */
    public function issue(Company $company, User $lender, string $recipientEmail, int $ttlMinutes = 1440): array
    {
        $email = $this->email($recipientEmail);
        if ($ttlMinutes < 1 || $ttlMinutes > 525600) {
            throw ValidationException::withMessages(['expires_at' => 'Vigencia no válida.']);
        }

        return DB::transaction(function () use ($company, $lender, $email, $ttlMinutes) {
            // Serialize issuance for this company, including the first invitation.
            $this->assertLender($company, $lender);
            if ($this->pending(CobrosLaborInvitation::query()
                ->where('company_id', $company->id)->where('recipient_email', $email))->exists()) {
                throw ValidationException::withMessages(['recipient_email' => 'Ya existe una invitación vigente.']);
            }

            $plain = bin2hex(random_bytes(32));
            $invitation = CobrosLaborInvitation::create([
                'company_id' => $company->id,
                'lender_user_id' => $lender->id,
                'recipient_email' => $email,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            return ['invitation' => $invitation, 'plain' => $plain];
        });
    }

    /** One-use validation only; this does not accept or provision employment. */
    public function validate(Company $company, User $lender, string $recipientEmail, string $plain): CobrosLaborInvitation
    {
        $email = $this->email($recipientEmail);

        return DB::transaction(function () use ($company, $lender, $email, $plain) {
            $this->assertLender($company, $lender);
            $query = $this->pending($this->scope($company, $lender)
                ->where('recipient_email', $email)->where('token_hash', hash('sha256', $plain)));
            $invitation = (clone $query)->first();
            if (! $invitation || (clone $query)->whereKey($invitation->id)->update(['used_at' => now()]) !== 1) {
                throw ValidationException::withMessages(['token' => 'Invitación no válida o no disponible.']);
            }

            return $invitation->refresh();
        });
    }

    /**
     * BLOQUE 16C-2B f1 — verificación de solo lectura del enlace público de
     * invitación. NO marca used_at: el registro completo no consume la
     * invitación, solo comprueba que siga vigente (sin revocar ni vencida)
     * y que el prestamista y la empresa sigan autorizados.
     */
    public function inspect(string $plain): CobrosLaborInvitation
    {
        if (! is_string($plain) || $plain === '' || strlen($plain) > 256) {
            throw ValidationException::withMessages(['token' => 'Invitación no válida.']);
        }

        return DB::transaction(function () use ($plain) {
            $invitation = $this->pending(
                CobrosLaborInvitation::query()->where('token_hash', hash('sha256', $plain))
            )->first();

            $company = $invitation?->company;
            $lender = $invitation?->lender;

            if (! $invitation || ! $company || ! $lender) {
                throw ValidationException::withMessages(['token' => 'Invitación no válida, revocada o caducada.']);
            }

            $this->assertLender($company, $lender);

            return $invitation;
        });
    }

    /**
     * BLOQUE 16C-2B f1 — re-verificación de la referencia guardada en el
     * borrador de registro (id + huella + correo del destinatario). Comprueba
     * vigencia, destinatario y prestamista SIN consumir la invitación: el
     * consumo definitivo pertenece a la fase de activación.
     */
    public function verifyReference(int $invitationId, string $tokenHash, string $recipientEmail): CobrosLaborInvitation
    {
        $email = $this->email($recipientEmail);

        if ($invitationId < 1 || preg_match('/\A[a-f0-9]{64}\z/', $tokenHash) !== 1) {
            throw ValidationException::withMessages(['labor_invitation' => 'La referencia de invitación no es válida.']);
        }

        return DB::transaction(function () use ($invitationId, $tokenHash, $email) {
            $invitation = CobrosLaborInvitation::query()
                ->whereKey($invitationId)->where('token_hash', $tokenHash)->first();

            if (! $invitation) {
                throw ValidationException::withMessages(['labor_invitation' => 'La referencia de invitación no es válida.']);
            }

            if ($invitation->revoked_at !== null) {
                throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral fue revocada por el prestamista.']);
            }

            if (! $invitation->expires_at->isFuture()) {
                throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral caducó; solicite un enlace nuevo.']);
            }

            if ($invitation->used_at !== null) {
                throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral ya fue utilizada.']);
            }

            if (mb_strtolower((string) $invitation->recipient_email) !== $email) {
                throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral fue emitida para otro correo electrónico.']);
            }

            $company = $invitation->company;
            $lender = $invitation->lender;

            if (! $company || ! $lender) {
                throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral ya no está disponible.']);
            }

            $this->assertLender($company, $lender);

            return $invitation;
        });
    }

    public function revoke(Company $company, User $lender, int $invitationId): void
    {
        DB::transaction(function () use ($company, $lender, $invitationId) {
            $this->assertLender($company, $lender);
            $invitation = $this->scope($company, $lender)->findOrFail($invitationId);
            if ($invitation->revoked_at === null) {
                $invitation->update(['revoked_at' => now()]);
            }
        });
    }

    private function assertLender(Company $company, User $lender): void
    {
        $stored = Company::query()->lockForUpdate()->find($company->id);
        if (! $stored || ! $stored->is_active || (int) $stored->owner_user_id !== (int) $lender->id
            || ! User::query()->whereKey($lender->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['lender' => 'Prestamista no autorizado para esta empresa.']);
        }
    }

    private function scope(Company $company, User $lender): Builder
    {
        return CobrosLaborInvitation::query()->where('company_id', $company->id)
            ->where('lender_user_id', $lender->id);
    }

    private function pending(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    private function email(string $value): string
    {
        $email = mb_strtolower(trim($value));
        Validator::make(['recipient_email' => $email], [
            'recipient_email' => ['required', 'email:rfc', 'max:254'],
        ])->validate();

        return $email;
    }
}
