<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIX 5A — evento histórico APPEND-ONLY del período de migración inicial.
 * Reconstruye cronológicamente todos los ciclos:
 * apertura → cierre → reapertura → nuevo cierre (quién, cuándo, motivo).
 */
class CobrosInitialPortfolioEvent extends Model
{
    public const TYPE_OPEN = 'abierta';

    public const TYPE_CLOSE = 'cerrada';

    public const TYPE_REOPEN = 'reabierta';

    public const TYPE_REVIEW = 'revisada';

    public const TYPE_CORRECTION = 'correccion';

    protected $table = 'cobros_initial_portfolio_events';

    protected $fillable = [
        'migration_id',
        'company_id',
        'type',
        'actor_user_id',
        'reason',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function migration(): BelongsTo
    {
        return $this->belongsTo(CobrosInitialPortfolioMigration::class, 'migration_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
