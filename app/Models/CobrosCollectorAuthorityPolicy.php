<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Política de AUTORIDAD de préstamos por company + cobrador.
 *
 * Una sola autoridad por decisión:
 *  - owner_required (default SEGURO): el dueño autoriza;
 *  - collector_authorized: ese cobrador autoriza los suyos.
 *
 * La política se resuelve SIEMPRE server-side (nunca desde frontend) y el
 * valor aplicado se congela en terms_snapshot al momento de aprobar, por lo
 * que un cambio posterior no reescribe créditos ni solicitudes anteriores.
 */
class CobrosCollectorAuthorityPolicy extends Model
{
    public const OWNER_REQUIRED = 'owner_required';

    public const COLLECTOR_AUTHORIZED = 'collector_authorized';

    public const POLICIES = [
        self::OWNER_REQUIRED,
        self::COLLECTOR_AUTHORIZED,
    ];

    protected $fillable = [
        'company_id',
        'collector_user_id',
        'authority_policy',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** Política vigente de ese cobrador en esa empresa (default seguro). */
    public static function policyFor(int $companyId, int $collectorUserId): string
    {
        if ($companyId <= 0 || $collectorUserId <= 0) {
            return self::OWNER_REQUIRED;
        }

        $row = self::query()
            ->where('company_id', $companyId)
            ->where('collector_user_id', $collectorUserId)
            ->first();

        return $row !== null && in_array($row->authority_policy, self::POLICIES, true)
            ? (string) $row->authority_policy
            : self::OWNER_REQUIRED;
    }

    public static function requiresOwner(int $companyId, int $collectorUserId): bool
    {
        return self::policyFor($companyId, $collectorUserId) === self::OWNER_REQUIRED;
    }

    /** Cambio de política (solo el dueño vía servidor): auditoría quién/cuándo. */
    public static function set(int $companyId, int $collectorUserId, string $policy, ?int $actorUserId = null): self
    {
        if (! in_array($policy, self::POLICIES, true)) {
            throw new InvalidArgumentException('Política de autoridad no válida.');
        }

        return self::query()->updateOrCreate(
            ['company_id' => $companyId, 'collector_user_id' => $collectorUserId],
            ['authority_policy' => $policy, 'changed_by' => $actorUserId, 'changed_at' => now()]
        );
    }
}
