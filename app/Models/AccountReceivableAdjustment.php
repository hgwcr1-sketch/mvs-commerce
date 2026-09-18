<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccountReceivableAdjustment extends Model
{
    protected $table = 'accounts_receivable_adjustments';

    public const TYPE_CREDIT_NOTE_OFFSET = 'credit_note_offset';

    public const TYPE_CREDIT_NOTE_OFFSET_REVERSAL = 'credit_note_offset_reversal';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'company_id',
        'branch_id',
        'account_receivable_id',
        'credit_note_id',
        'type',
        'amount',
        'reversed_amount',
        'reversal_adjustment_id',
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
            'reversed_amount' => 'decimal:4',
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

    public function reversalAdjustment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_adjustment_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_adjustment_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isFullyReversed(): bool
    {
        return bccomp((string) $this->reversed_amount, (string) $this->amount, 4) >= 0;
    }

    public function remainingAmount(): string
    {
        return bcsub((string) $this->amount, (string) $this->reversed_amount, 4);
    }
}
