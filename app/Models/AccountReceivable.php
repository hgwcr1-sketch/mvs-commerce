<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountReceivable extends Model
{
    protected $table = 'accounts_receivable';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'sale_id', 'issued_at', 'due_date', 'original_amount', 'balance_due', 'status', 'currency_code'];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'due_date' => 'date', 'original_amount' => 'decimal:4', 'balance_due' => 'decimal:4'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function payments(): HasMany { return $this->hasMany(AccountReceivablePayment::class); }
    public function scopeForCompany(Builder $query, int $id): Builder { return $query->where('company_id', $id); }
    public function scopeForBranch(Builder $query, int $id): Builder { return $query->where('branch_id', $id); }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->balance_due > 0 && $this->due_date->isBefore(today()) && !in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true)
            ? self::STATUS_OVERDUE : $this->status;
    }
}
