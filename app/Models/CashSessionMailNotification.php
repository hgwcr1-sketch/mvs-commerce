<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashSessionMailNotification extends Model
{
    public const TYPE_OPENED = 'opened';
    public const TYPE_CLOSED = 'closed';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'company_id', 'cash_session_id', 'notification_type', 'recipients', 'delivered_recipients',
        'status', 'attempts', 'last_error', 'available_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'delivered_recipients' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }

    public function scopeDispatchable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $available) => $available->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    public function isAdministrativelyRetriable(): bool
    {
        if ($this->attempts >= self::MAX_ATTEMPTS || ($this->available_at && $this->available_at->isFuture())) return false;
        if ($this->status === self::STATUS_FAILED) return true;

        return $this->status === self::STATUS_PENDING && $this->updated_at?->lte(now()->subMinutes(5));
    }
}
