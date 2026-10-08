<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque 5A — período de migración inicial de cartera (una fila por empresa).
 *
 * Estados: en_progreso -> finalizada (bloqueada). El prestamista no puede
 * reabrirla; solo Platform Admin/MVS de forma excepcional y auditable.
 *
 * Etiqueta permanente para TODO registro migrado en 5B+:
 * los datos cargados aquí son CARTERA INICIAL / INFORMACIÓN DECLARADA por el
 * prestamista, nunca historial verificado por CobrOS.
 */
class CobrosInitialPortfolioMigration extends Model
{
    /** Fuente permanente de los registros migrados (5B la aplicará por fila). */
    public const SOURCE_TAG = 'cartera_inicial';

    /** Enrolado en el flujo, sin comenzar (señal persistida de incorporación). */
    public const STATUS_AVAILABLE = 'disponible';

    public const STATUS_IN_PROGRESS = 'en_progreso';

    public const STATUS_FINALIZED = 'finalizada';

    protected $table = 'cobros_initial_portfolio_migrations';

    protected $fillable = [
        'company_id',
        'collector_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'status',
        'started_at',
        'started_by_user_id',
        'finalized_at',
        'finalized_by_user_id',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
        'reopen_count',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finalized_at' => 'datetime',
            'reopened_at' => 'datetime',
            'reopen_count' => 'integer',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
