<?php

namespace App\Services\Cobros;

use App\Models\CobrosLaborInvitation;
use App\Models\CobrosRegistrationDraft;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 16C-2B f2 + 17A-2 f2 — servicio transaccional de la activación.
 *
 * Prepara (solo lectura) el contrato de un borrador listo, y lo EJECUTA
 * después de una confirmación de pago válida (17A-2 f2).
 *
 * Reglas:
 *  - Reutiliza CobrosRegistrationActivationService (validación + plan +
 *    clave idempotente por borrador) y LaborInvitationService (vigencia,
 *    destinatario y prestamista) sin reconstruirlos.
 *  - execute() SOLO aprovisiona cuando el contrato trae pago confirmado;
 *    sin pago lanza el error controlado SIN escribir.
 *  - La activación es idempotente por idempotencyKey: un reintento del
 *    mismo borrador no duplica usuarios, empresas, rutas ni licencias.
 *  - consumeInvitation() es atómica (update condicional con used_at IS NULL)
 *    e idempotente para reintentos del MISMO borrador; cualquier otro intento
 *    de reutilización, y toda invitación vencida o revocada, se rechaza.
 *  - La invitación laboral se consume SOLO después de confirmar el pago.
 *  - Si el aprovisionamiento falla, la transacción revierte: el pago
 *    confirmado NO se pierde y el estado queda recuperable para reintento.
 */
class CobrosActivationContractService
{
    public function __construct(
        private CobrosRegistrationActivationService $activation,
        private LaborInvitationService $invitations,
        private CompanyProvisioner $provisioner,
        private CobrosModalityService $modalityService,
        private CollectorLicenseService $collectorLicenses,
    ) {
    }

    /**
     * Contrato transaccional de un borrador listo. Solo LEE: revalida el
     * borrador completo (incluida la invitación laboral real) y resuelve el
     * vínculo con el prestamista correcto sin escribir nada.
     */
    public function prepare(CobrosRegistrationDraft $draft): CobrosActivationContract
    {
        $plan = $this->activation->plan($draft);
        $payload = is_array($draft->payload) ? $draft->payload : [];

        $linkage = null;
        if (($payload['modality'] ?? null) === CobrosModalityCatalog::COBRADOR_VINCULADO) {
            $record = $this->verifyInvitation($payload, (string) $plan['owner']['email']);

            $linkage = [
                'invitation_id' => $record->id,
                'company_id' => $record->company_id,
                'lender_user_id' => $record->lender_user_id,
                'lender_email' => (string) $record->lender->email,
                'lender_name' => (string) $record->lender->name,
                'recipient_email' => (string) $record->recipient_email,
            ];
        }

        $referral = $payload['referral'] ?? null;

        return new CobrosActivationContract(
            idempotencyKey: (string) $plan['idempotency_key'],
            draftId: (int) $draft->id,
            email: (string) $draft->email,
            countryIso: (string) $plan['country_iso'],
            modality: (string) $plan['modality'],
            status: CobrosActivationContract::STATUS_PENDING_PAYMENT,
            paymentConfirmed: false,
            routes: $plan['routes'],
            linkage: $linkage,
            referral: is_array($referral) && isset($referral['code'])
                ? ['code' => (string) $referral['code'], 'captured_at' => $referral['captured_at'] ?? null]
                : null,
        );
    }

    /**
     * Punto único de entrada de la activación real. Solo ejecuta cuando el
     * contrato trae pago confirmado; sin pago lanza el error controlado SIN
     * escribir. La activación es idempotente por idempotencyKey.
     */
    public function execute(CobrosActivationContract $contract): array
    {
        if (! $contract->paymentConfirmed) {
            throw ValidationException::withMessages([
                'payment' => 'La activación requiere la confirmación de pago (bloque 17); '
                    .'todavía no se ha aprobado ninguna cuenta.',
            ]);
        }

        $draft = CobrosRegistrationDraft::find($contract->draftId);
        if ($draft === null) {
            throw ValidationException::withMessages(['draft' => 'El borrador de registro ya no existe.']);
        }

        return DB::transaction(function () use ($contract, $draft) {
            // Idempotencia: un reintento del MISMO borrador no duplica nada.
            $existing = $this->findAlreadyActivated($draft);
            if ($existing !== null) {
                return $existing;
            }

            // La invitación laboral se consume SOLO después del pago.
            $linkage = null;
            if ($contract->linkage !== null) {
                $consumed = $this->consumeInvitation($draft);
                $linkage = [
                    'company_id' => $consumed['company_id'],
                    'lender_user_id' => $consumed['lender_user_id'],
                ];
            }

            // Aprovisionamiento: empresa + usuario + membresía + modalidad + ruta.
            $result = $this->provision($contract, $draft, $linkage);

            return $result;
        });
    }

    /**
     * Aprovisiona la cuenta del cobrador según la modalidad:
     *  - cobrador_vinculado: se une a la empresa del prestamista (linkage).
     *  - cobrador_independiente / prestamista: crea su propia empresa.
     *
     * @return array{user: User, company: Company, route: CobrosRoute}
     */
    private function provision(CobrosActivationContract $contract, CobrosRegistrationDraft $draft, ?array $linkage): array
    {
        $payload = is_array($draft->payload) ? $draft->payload : [];
        $personal = is_array($payload['personal'] ?? null) ? $payload['personal'] : [];

        $name = trim((string) ($personal['name'] ?? ''));
        $email = trim((string) ($personal['email'] ?? $draft->email));
        $phone = $personal['phone'] ?? null;

        if ($linkage !== null) {
            // Cobrador vinculado: empresa ya existe del prestamista.
            $company = Company::findOrFail($linkage['company_id']);
            $user = $this->findOrCreateUser($name, $email, $phone);

            // Asegura la membresía ANTES de asignar modalidad.
            $user->companies()->syncWithoutDetaching([$company->id]);

            $this->modalityService->assign($company, $user, $contract->modality);

            $route = $this->createRoute($company, $contract->routes[0] ?? null);

            $this->collectorLicenses->grantIfMissing($user, $company);

            return ['user' => $user, 'company' => $company, 'route' => $route];
        }

        // Independiente / prestamista: crea su propia empresa.
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'phone' => $phone,
            'is_active' => true,
        ]);

        $company = $this->provisioner->provision(
            $user,
            [
                'trade_name' => $name."'s Cartera",
                'currency' => $contract->countryIso === 'CO' ? 'COP' : 'CRC',
                'timezone' => $contract->countryIso === 'CO' ? 'America/Bogota' : 'America/Costa_Rica',
            ],
            'Principal',
            'PRINCIPAL',
            1,
            [],
            ['cobros'],
        );

        $this->modalityService->assign($company, $user, $contract->modality);

        $route = $this->createRoute($company, $contract->routes[0] ?? null);

        $this->collectorLicenses->grantIfMissing($user, $company);

        return ['user' => $user, 'company' => $company, 'route' => $route];
    }

    /** Usuario existente por correo, o lo crea. Una sola cuenta por cobrador. */
    private function findOrCreateUser(string $name, string $email, ?string $phone): User
    {
        $existing = User::where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    /** Crea la ruta del borrador en la empresa (una sola por cobrador). */
    private function createRoute(Company $company, ?array $routeData): CobrosRoute
    {
        $name = trim((string) ($routeData['name'] ?? 'Ruta Principal'));

        $existing = CobrosRoute::forCompany($company->id)->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return CobrosRoute::create([
            'company_id' => $company->id,
            'name' => $name,
            'active' => true,
        ]);
    }

    /** Verifica si el borrador ya fue activado (reintento). Público para la orquestación. */
    public function findAlreadyActivatedPublic(CobrosRegistrationDraft $draft): ?array
    {
        return $this->findAlreadyActivated($draft);
    }

    /**
     * Verifica si el borrador ya fue activado (reintento). Devuelve el
     * resultado previo o null si aún no se activó.
     */
    private function findAlreadyActivated(CobrosRegistrationDraft $draft): ?array
    {
        $payload = is_array($draft->payload) ? $draft->payload : [];
        $personal = is_array($payload['personal'] ?? null) ? $payload['personal'] : [];
        $email = trim((string) ($personal['email'] ?? $draft->email));

        $user = User::where('email', $email)->first();
        if ($user === null) {
            return null;
        }

        $company = $user->companies()->first();
        if ($company === null) {
            return null;
        }

        $route = CobrosRoute::forCompany($company->id)->first();

        return ['user' => $user, 'company' => $company, 'route' => $route];
    }

    /**
     * Consumo ATÓMICO de la invitación del borrador, reservado para la fase
     * de activación (bloque 17). El update condicional garantiza un único
     * éxito: reutilización, vencimiento y revocación quedan bloqueados.
     *
     * Reintentos del mismo borrador devuelven el mismo resultado (idempotente)
     * gracias al marcador `consumed_at` del payload; un borrador distinto
     * nunca puede reclamar una invitación ya consumida.
     *
     * @return array{invitation_id: int, company_id: int, lender_user_id: int, already_consumed: bool}
     */
    public function consumeInvitation(CobrosRegistrationDraft $draft): array
    {
        $payload = is_array($draft->payload) ? $draft->payload : [];
        $email = $payload['personal']['email'] ?? null;

        if (! is_string($email) || $email === '') {
            throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral no es aplicable a este borrador.']);
        }

        $reference = $payload['labor_invitation'] ?? null;

        if (! is_array($reference)) {
            throw ValidationException::withMessages(['labor_invitation' => 'La modalidad Cobrador vinculado requiere una invitación laboral válida del prestamista.']);
        }

        $row = CobrosLaborInvitation::query()
            ->whereKey((int) ($reference['id'] ?? 0))
            ->where('token_hash', (string) ($reference['token_hash'] ?? ''))
            ->first();

        if (! $row) {
            throw ValidationException::withMessages(['labor_invitation' => 'La referencia de invitación no es válida.']);
        }

        if (mb_strtolower((string) $row->recipient_email) !== mb_strtolower($email)) {
            throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral fue emitida para otro correo electrónico.']);
        }

        if (! $row->company || ! $row->lender) {
            throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral ya no está disponible.']);
        }

        $this->invitations->assertLender($row->company, $row->lender);

        // Reintento del MISMO borrador: idempotente, sin volver a consumir.
        if ($row->used_at !== null) {
            if ((int) ($reference['consumed_invitation_id'] ?? 0) === $row->id
                && $this->consumedMatches($this->consumedMarker($draft), $row->used_at)) {
                return [
                    'invitation_id' => $row->id,
                    'company_id' => $row->company_id,
                    'lender_user_id' => $row->lender_user_id,
                    'already_consumed' => true,
                ];
            }

            throw ValidationException::withMessages([
                'labor_invitation' => 'La invitación laboral ya fue utilizada y no puede reutilizarse.',
            ]);
        }

        if ($row->revoked_at !== null) {
            throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral fue revocada por el prestamista.']);
        }

        if (! $row->expires_at->isFuture()) {
            throw ValidationException::withMessages(['labor_invitation' => 'La invitación laboral caducó; solicite un enlace nuevo.']);
        }

        return DB::transaction(function () use ($draft, $row) {
            $claimed = CobrosLaborInvitation::query()
                ->whereKey($row->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now()]);

            if ($claimed !== 1) {
                // Otro proceso la reclamó primero: reutilización bloqueada.
                throw ValidationException::withMessages([
                    'labor_invitation' => 'La invitación laboral ya fue utilizada y no puede reutilizarse.',
                ]);
            }

            $this->markConsumed($draft, $row->id);

            return [
                'invitation_id' => $row->id,
                'company_id' => $row->company_id,
                'lender_user_id' => $row->lender_user_id,
                'already_consumed' => false,
            ];
        });
    }

    /** Verificación de solo lectura de la referencia guardada en el borrador. */
    private function verifyInvitation(array $payload, string $recipientEmail): CobrosLaborInvitation
    {
        $invitation = $payload['labor_invitation'] ?? null;

        if (! is_array($invitation)) {
            throw ValidationException::withMessages([
                'labor_invitation' => 'La modalidad Cobrador vinculado requiere una invitación laboral válida del prestamista.',
            ]);
        }

        return $this->invitations->verifyReference(
            (int) ($invitation['id'] ?? 0),
            (string) ($invitation['token_hash'] ?? ''),
            $recipientEmail
        );
    }

    /** Marca en el borrador el consumo ya realizado (para reintentos). */
    private function markConsumed(CobrosRegistrationDraft $draft, int $invitationId): void
    {
        $payload = is_array($draft->payload) ? $draft->payload : [];

        if (! is_array($payload['labor_invitation'] ?? null)) {
            return;
        }

        $payload['labor_invitation']['consumed_at'] = now()->toIso8601String();
        $payload['labor_invitation']['consumed_invitation_id'] = $invitationId;
        $draft->update(['payload' => $payload]);
    }

    /** ISO8601 del consumo registrado en el borrador, o null. */
    private function consumedMarker(CobrosRegistrationDraft $draft): ?string
    {
        $payload = is_array($draft->payload) ? $draft->payload : [];
        $invitation = $payload['labor_invitation'] ?? null;

        if (! is_array($invitation) || ! isset($invitation['consumed_at'])) {
            return null;
        }

        return (string) $invitation['consumed_at'];
    }

    /** El marcador del borrador corresponde al consumo real de esa invitación. */
    private function consumedMatches(?string $marker, \DateTimeInterface $usedAt): bool
    {
        if ($marker === null) {
            return false;
        }

        try {
            $parsed = \Illuminate\Support\Carbon::parse($marker);
        } catch (\Throwable) {
            return false;
        }

        return $parsed->format('Y-m-d H:i:s') === $usedAt->format('Y-m-d H:i:s');
    }
}
