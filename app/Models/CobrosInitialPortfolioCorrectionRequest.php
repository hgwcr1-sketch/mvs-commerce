<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de corrección de un dato inicial congelado (append-only).
 * El valor original SIEMPRE se conserva aquí; la aprobación aplica el nuevo
 * valor de forma auditable (reviewed_by/at + applied_at) sin borrar historia.
 */
class CobrosInitialPortfolioCorrectionRequest extends Model
{
    public const STATUS_PENDING = 'pendiente';

    public const STATUS_APPROVED = 'aprobada';

    public const STATUS_REJECTED = 'rechazada';

    protected $table = 'cobros_initial_portfolio_correction_requests';

    protected $fillable = [
        'migration_id', 'record_id', 'company_id', 'collector_user_id',
        'field', 'original_value', 'requested_value', 'reason', 'status',
        'requested_by_user_id', 'requested_at', 'reviewed_by_user_id',
        'reviewed_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CobrosInitialPortfolioRecord::class, 'record_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
