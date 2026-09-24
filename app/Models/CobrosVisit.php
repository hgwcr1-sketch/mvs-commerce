<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CobrosVisit extends Model
{
    public const STATUS_PENDING = 'pendiente';
    public const STATUS_VISITED = 'visitado';
    public const STATUS_NOT_FOUND = 'no_localizado';
    public const STATUS_PROMISE = 'promesa_pago';
    public const STATUS_RESCHEDULED = 'reprogramada';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VISITED,
        self::STATUS_NOT_FOUND,
        self::STATUS_PROMISE,
        self::STATUS_RESCHEDULED,
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'collector_user_id',
        'account_receivable_id', 'scheduled_for', 'visited_at', 'status',
        'promise_date', 'promise_amount', 'notes', 'rescheduled_from_visit_id',
        'latitude', 'longitude', 'location_captured_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'visited_at' => 'date',
            'promise_date' => 'date',
            'promise_amount' => 'decimal:4',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'location_captured_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_user_id'); }
    public function accountReceivable(): BelongsTo { return $this->belongsTo(AccountReceivable::class); }
    public function rescheduledFrom(): BelongsTo { return $this->belongsTo(self::class, 'rescheduled_from_visit_id'); }
    public function logs(): HasMany { return $this->hasMany(CobrosVisitLog::class); }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForCollector(Builder $query, int $userId): Builder
    {
        return $query->where('collector_user_id', $userId);
    }
}
