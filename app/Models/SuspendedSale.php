<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SuspendedSale extends Model
{
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_RECOVERING = 'recovering';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['company_id', 'branch_id', 'user_id', 'customer_id', 'suspension_number', 'status', 'currency_code', 'estimated_subtotal', 'estimated_tax_total', 'estimated_rounding_total', 'estimated_total', 'suspended_at', 'recovery_token', 'recovery_started_at', 'recovery_by', 'recovered_sale_id', 'recovered_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['estimated_subtotal' => 'decimal:4', 'estimated_tax_total' => 'decimal:4', 'estimated_rounding_total' => 'decimal:4', 'estimated_total' => 'decimal:4', 'suspended_at' => 'datetime', 'recovery_started_at' => 'datetime', 'recovered_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(SuspendedSaleItem::class); }
    public function recoveryBy(): BelongsTo { return $this->belongsTo(User::class, 'recovery_by'); }
    public function recoveredSale(): BelongsTo { return $this->belongsTo(Sale::class, 'recovered_sale_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function scopeForCompany(Builder $query, int $id): Builder { return $query->where('company_id', $id); }
    public function scopeForBranch(Builder $query, int $id): Builder { return $query->where('branch_id', $id); }
    public function scopeSuspended(Builder $query): Builder { return $query->whereIn('status', [self::STATUS_SUSPENDED, self::STATUS_RECOVERING]); }
}
