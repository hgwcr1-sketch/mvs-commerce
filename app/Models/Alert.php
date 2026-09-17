<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'branch_id',
    'type',
    'severity',
    'status',
    'actor_id',
    'responsible_user_id',
    'entity_type',
    'entity_id',
    'link',
    'dedupe_hash',
    'occurred_at',
    'resolved_at',
    'resolved_by',
    'metadata',
    'notes',
])]

class Alert extends Model
{
    public const SEVERITY_CRITICAL = 'CRITICA';

    public const SEVERITY_ATTENTION = 'ATENCION';

    public const SEVERITY_INFO = 'INFORMATIVA';

    public const STATUS_NEW = 'NUEVA';

    public const STATUS_VIEWED = 'VISTA';

    public const STATUS_IN_PROGRESS = 'EN_ATENCION';

    public const STATUS_RESOLVED = 'RESUELTA';

    public const SEVERITIES = [self::SEVERITY_CRITICAL, self::SEVERITY_ATTENTION, self::SEVERITY_INFO];

    public const STATUSES = [self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));
    }

    public function scopeUnread($query)
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_IN_PROGRESS]);
    }
}
