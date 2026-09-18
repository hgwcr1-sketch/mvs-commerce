<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReceivableAdjustment extends Model
{
    protected $table = 'accounts_receivable_adjustments';

    public const TYPE_CREDIT_NOTE_OFFSET = 'credit_note_offset';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'company_id',
        'branch_id',
        'account_receivable_id',
        'credit_note_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reason',
        'status',
        'voided_by',
        'voided_at',
        'void_reason',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'voided_at' => 'datetime',
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

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
