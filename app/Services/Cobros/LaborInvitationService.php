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
