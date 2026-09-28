<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_SENT = 'sent';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['company_id', 'branch_id', 'supplier_id', 'number', 'status', 'notes', 'requested_at', 'prepared_at', 'prepared_by', 'sent_at', 'sent_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'prepared_at' => 'datetime', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by'); }
    public function sentBy(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class); }

    public function scopeForCompany(Builder $query, int $companyId): Builder { return $query->where('company_id', $companyId); }
    public function scopeForBranch(Builder $query, int $branchId): Builder { return $query->where('branch_id', $branchId); }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Borrador', self::STATUS_PREPARED => 'Preparado',
            self::STATUS_SENT => 'Enviado', self::STATUS_RECEIVED => 'Recibido',
            self::STATUS_CANCELLED => 'Cancelado', default => ucfirst($this->status),
        };
    }

    public function getEstimatedTotalAttribute(): float
    {
        return (float) $this->items->sum(fn (PurchaseOrderItem $item) => (float) $item->ordered_quantity * (float) ($item->unit_cost_snapshot ?? 0));
    }
}
