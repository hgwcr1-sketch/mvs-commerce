<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_APPLIED = 'partially_applied';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'sale_id',
        'sale_return_id',
        'credit_note_number',
        'currency_code',
        'issued_amount',
        'applied_amount',
        'balance',
        'status',
        'reason',
        'issued_by',
        'issued_at',
        'voided_by',
        'voided_at',
        'void_reason',
        'idempotency_key',
        'requires_ar_review',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'balance' => 'decimal:4',
            'requires_ar_review' => 'boolean',
            'issued_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CreditNoteApplication::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_ISSUED, self::STATUS_PARTIALLY_APPLIED])
            ->where('balance', '>', 0);
    }

    public function hasAvailableBalance(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIALLY_APPLIED], true)
            && bccomp((string) $this->balance, '0', 4) > 0;
    }
}
