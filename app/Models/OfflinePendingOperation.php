<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OfflinePendingOperation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCING = 'syncing';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SYNCING,
        self::STATUS_SYNCED,
        self::STATUS_FAILED,
    ];

    protected $table = 'offline_pending_operations';

    protected $fillable = [
        'operation_uuid',
        'operation_type',
        'company_id',
        'branch_id',
        'terminal_uuid',
        'user_id',
        'created_at_local',
        'payload',
        'status',
        'attempts',
        'last_attempt_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'created_at_local' => 'datetime',
            'payload' => 'array',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OfflinePendingOperation $op) {
            if (empty($op->operation_uuid)) {
                $op->operation_uuid = (string) Str::uuid();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
