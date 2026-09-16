<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSyncOperation extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_PROCESSING,
        self::STATUS_SYNCED,
        self::STATUS_CONFLICT,
        self::STATUS_FAILED,
    ];

    public const OPERATION_TYPE_OFFLINE_SALE = 'offline_sale';

    protected $table = 'offline_sync_operations';

    public $timestamps = false;

    protected $fillable = [
        'operation_uuid',
        'operation_type',
        'company_id',
        'branch_id',
        'terminal_uuid',
        'user_id',
        'payload_version',
        'payload',
        'payload_hash',
        'created_at_local',
        'received_at',
        'processed_at',
        'status',
        'sale_id',
        'attempts',
        'last_error',
        'conflict_reason',
    ];

    protected function casts(): array
    {
        return [
            'created_at_local' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload' => 'array',
            'attempts' => 'integer',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    public function isConflict(): bool
    {
        return $this->status === self::STATUS_CONFLICT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'processed_at' => now(),
        ]);
    }

    public function markSynced(Sale $sale): void
    {
        $this->update([
            'status' => self::STATUS_SYNCED,
            'sale_id' => $sale->id,
            'processed_at' => now(),
        ]);
    }

    public function markConflict(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_CONFLICT,
            'conflict_reason' => $reason,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->increment('attempts');
        $this->update([
            'status' => self::STATUS_FAILED,
            'last_error' => $error,
        ]);
    }
}