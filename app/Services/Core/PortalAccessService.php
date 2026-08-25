<?php

namespace App\Services\Core;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Servicio genérico de acceso seguro a portal del cliente.
 *
 * Mecanismo único de token opaco para cualquier módulo (Fidelidad, BeautyOS, etc.):
 * - Token CSPRNG de 60 caracteres asociado a (empresa, cliente)
 * - Solo hash SHA-256 en base de datos
 * - Regeneración revoca acceso anterior
 * - Resolución valida empresa activa y cliente no borrado
 * - last_used_at para auditoría
 */
class PortalAccessService
{
    public const TOKEN_LENGTH = 60;

    /** Genera (o regenera) el acceso activo del cliente: revoca el anterior y devuelve el token una sola vez. */
    public function generate(Customer $customer, Company $company, ?User $user): array
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }

        return DB::transaction(function () use ($customer, $company, $user) {
            LoyaltyPortalAccess::query()
                ->where('company_id', $company->id)
                ->where('customer_id', $customer->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->update(['revoked_at' => now()]);

            $token = Str::random(self::TOKEN_LENGTH);
            $access = LoyaltyPortalAccess::query()->create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'user_id' => $user?->id,
                'token_hash' => hash('sha256', $token),
            ]);

            return ['access' => $access, 'token' => $token, 'url' => $this->url($token)];
        });
    }

    public function url(string $token): string
    {
        return route('portal.access', ['token' => $token]);
    }

    /** Revoca los accesos activos del cliente dentro de la empresa. */
    public function revoke(Customer $customer, Company $company): int
    {
        return LoyaltyPortalAccess::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function activeFor(Customer $customer, Company $company): ?LoyaltyPortalAccess
    {
        return LoyaltyPortalAccess::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Resuelve un token público a su empresa y cliente, validando que el acceso esté
     * vigente y que empresa y cliente sigan activos. Null cuando algo no coincide.
     *
     * @return array{access:LoyaltyPortalAccess,company:Company,customer:Customer}|null
     */
    public function resolve(string $token): ?array
    {
        $token = trim($token);

        if ($token === '' || strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9]+$/', $token)) {
            return null;
        }

        $access = LoyaltyPortalAccess::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->with(['company', 'customer'])
            ->first();

        if ($access === null
            || $access->company === null
            || ! $access->company->is_active
            || $access->customer === null
            || $access->customer->trashed()) {
            return null;
        }

        $access->forceFill(['last_used_at' => now()])->save();

        return ['access' => $access, 'company' => $access->company, 'customer' => $access->customer];
    }
}
