<?php

namespace App\Services\Cobros;

use App\Models\CobrosLaborInvitation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectorInvitationAcceptanceService
{
    public function __construct(private CollectorManagementService $management, private LaborInvitationService $invitations) {}

    public function accept(User $user, array $reference): Company
    {
        if (! isset($reference['id'], $reference['token_hash'], $reference['email']) || ! is_int($reference['id']) || preg_match('/\A[a-f0-9]{64}\z/', (string) $reference['token_hash']) !== 1) {
            throw ValidationException::withMessages(['invitation' => 'La referencia de invitación no es válida.']);
        }
        return DB::transaction(function () use ($user, $reference) {
            $invitation = CobrosLaborInvitation::query()->whereKey($reference['id'])->where('token_hash', $reference['token_hash'])->first();
            if ($invitation === null || $invitation->revoked_at !== null || $invitation->used_at !== null || ! $invitation->expires_at->isFuture()) throw ValidationException::withMessages(['invitation' => 'La invitación no es válida, fue revocada o caducó.']);
            $email = mb_strtolower(trim((string) $reference['email']));
            if ($email !== mb_strtolower(trim((string) $invitation->recipient_email)) || $email !== mb_strtolower(trim((string) $user->email))) throw ValidationException::withMessages(['invitation' => 'La invitación fue emitida para otro correo electrónico.']);
            $company = $invitation->company;
            $lender = $invitation->lender;
            if (! $company || ! $lender) throw ValidationException::withMessages(['invitation' => 'La invitación ya no está disponible.']);
            $this->invitations->assertLender($company, $lender);
            $this->management->acceptInvitationLink($user, $company);
            $claimed = CobrosLaborInvitation::query()->whereKey($invitation->id)->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->update(['used_at' => now()]);
            if ($claimed !== 1) throw ValidationException::withMessages(['invitation' => 'La invitación ya fue utilizada.']);
            return $company;
        });
    }
}
