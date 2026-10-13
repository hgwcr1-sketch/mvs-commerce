<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;

trait HasCobrosLicenseLifecycle
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** Estados efectivos para UI/control de acceso. */
    public const STATE_ACTIVE = 'active';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_EXPIRED = 'expired';

    public const STATE_PENDING = 'pending';

    public const STATE_NONE = 'none';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'suspended_at' => 'datetime',
            'renewed_at' => 'datetime',
        ];
    }

    /**
     * Estado efectivo: suspendida > pendiente (aún no inicia) > vencida > activa.
     */
    public function effectiveState(CarbonInterface $now): string
    {
        if ($this->status === self::STATUS_SUSPENDED) {
            return self::STATE_SUSPENDED;
        }

        if ($this->starts_at !== null && $this->starts_at->greaterThan($now)) {
            return self::STATE_PENDING;
        }

        if ($this->expires_at !== null && $this->expires_at->lte($now)) {
            return self::STATE_EXPIRED;
        }

        return self::STATE_ACTIVE;
    }

    public function isValidAt(CarbonInterface $now): bool
    {
        return $this->effectiveState($now) === self::STATE_ACTIVE;
    }
}
