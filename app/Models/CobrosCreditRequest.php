<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BLOQUE 7A — solicitud de crédito del cobrador.
 * Solo nace en estado pendiente; la resolución (verificación/aprobación/
 * rechazo/cancelación) es 7B/7C y hoy no tiene endpoint.
 */
class CobrosCreditRequest extends Model
{
    use BelongsToCobrosTenant;

    public const STATUS_PENDING = 'pendiente';
    public const STATUS_IN_REVIEW = 'en_verificacion';
    public const STATUS_VERIFIED = 'verificada';
    public const STATUS_CORRECTION = 'requiere_correccion';
    public const STATUS_APPROVED = 'aprobada';
    public const STATUS_REJECTED = 'rechazada';
    public const STATUS_CANCELLED = 'cancelada';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_REVIEW,
        self::STATUS_VERIFIED,
        self::STATUS_CORRECTION,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente de revisión',
        self::STATUS_IN_REVIEW => 'En verificación',
        self::STATUS_VERIFIED => 'Verificada',
        self::STATUS_CORRECTION => 'Requiere corrección',
        self::STATUS_APPROVED => 'Aprobada',
            self::STATUS_REJECTED => 'No autorizada',
        self::STATUS_CANCELLED => 'Cancelada',
    ];

    /** Estados que cuentan como solicitud ABIERTA para el control de duplicados. */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_REVIEW,
        self::STATUS_CORRECTION,
    ];

    protected $fillable = [
        'company_id',
        'cobros_route_id',
        'collector_user_id',
        'customer_id',
        'bureau_identity_id',
        'applicant_type',
        'status',
        'identification_type',
        'identification',
        'name',
        'phone',
        'address',
        'amount_requested',
        'latitude',
        'longitude',
        'notes',
        'id_front_path',
        'id_back_path',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'decimal:4',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'submitted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CobrosRoute::class, 'cobros_route_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bureauIdentity(): BelongsTo
    {
        return $this->belongsTo(CobrosBureauIdentity::class, 'bureau_identity_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CobrosCreditRequestLog::class, 'credit_request_id');
    }

    public function scopeForCollector(Builder $query, int $userId): Builder
    {
        return $query->where('collector_user_id', $userId);
    }

    public function scopeForRoute(Builder $query, int $routeId): Builder
    {
        return $query->where('cobros_route_id', $routeId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Última entrada de bitácora (append-only) de la solicitud. */
    public function latestLog(): ?CobrosCreditRequestLog
    {
        return $this->logs->sortByDesc('id')->first();
    }
}
