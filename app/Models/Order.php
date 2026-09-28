<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_IN_PURCHASE = 'in_purchase';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['company_id', 'branch_id', 'user_id', 'number', 'status', 'notes', 'reviewed_at', 'reviewed_by', 'rejected_at', 'rejected_by', 'rejection_reason', 'cancelled_at', 'cancelled_by', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function rejectedBy(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pendiente', self::STATUS_APPROVED => 'Aprobado',
            self::STATUS_PARTIAL => 'Parcial', self::STATUS_REJECTED => 'Rechazado',
            self::STATUS_IN_PURCHASE => 'En compra', self::STATUS_COMPLETED => 'Completado',
            self::STATUS_CANCELLED => 'Cancelado', default => ucfirst($this->status),
        };
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder { return $query->where('company_id', $companyId); }
    public function scopeForBranch(Builder $query, int $branchId): Builder { return $query->where('branch_id', $branchId); }
    public function scopePending(Builder $query): Builder { return $query->where('status', self::STATUS_PENDING); }
    public function scopeActive(Builder $query): Builder { return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PARTIAL, self::STATUS_IN_PURCHASE]); }
}
