<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransfer extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_RECEIVED_WITH_DIFFERENCES = 'received_with_differences';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PREPARED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_IN_REVIEW,
        self::STATUS_RECEIVED,
        self::STATUS_RECEIVED_WITH_DIFFERENCES,
        self::STATUS_CANCELLED,
        self::STATUS_COMPLETED,
    ];

    public const TRANSIT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PREPARED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_IN_REVIEW,
    ];

    public const RECEIVED_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_RECEIVED_WITH_DIFFERENCES,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'company_id',
        'from_branch_id',
        'to_branch_id',
        'user_id',
        'created_by',
        'prepared_by',
        'dispatched_by',
        'received_by',
        'confirmed_by',
        'transfer_number',
        'status',
        'notes',
        'transferred_at',
        'prepared_at',
        'dispatched_at',
        'received_at',
        'confirmed_at',
        'received_quantity_total',
        'differences_notes',
        'is_multiproduct',
    ];

    protected $casts = [
        'transferred_at' => 'datetime',
        'prepared_at' => 'datetime',
        'received_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'is_multiproduct' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPrepared(): bool
    {
        return $this->status === self::STATUS_PREPARED;
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    public function isInReview(): bool
    {
        return $this->status === self::STATUS_IN_REVIEW;
    }

    public function isReceived(): bool
    {
        return in_array($this->status, [
            self::STATUS_RECEIVED,
            self::STATUS_RECEIVED_WITH_DIFFERENCES,
            self::STATUS_COMPLETED,
        ], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function hasDifferences(): bool
    {
        return $this->status === self::STATUS_RECEIVED_WITH_DIFFERENCES;
    }

    public function canReceive(): bool
    {
        return in_array($this->status, [
            self::STATUS_IN_TRANSIT,
            self::STATUS_IN_REVIEW,
        ], true);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $current = $this->status;

        // Validar transiciones permitidas
        switch ($current) {
            case self::STATUS_PENDING:
                return $newStatus === self::STATUS_PREPARED;
            case self::STATUS_PREPARED:
                return $newStatus === self::STATUS_IN_TRANSIT;
            case self::STATUS_IN_TRANSIT:
                return $newStatus === self::STATUS_IN_REVIEW || $newStatus === self::STATUS_CANCELLED;
            case self::STATUS_IN_REVIEW:
                return $newStatus === self::STATUS_RECEIVED || $newStatus === self::STATUS_CANCELLED;
            case self::STATUS_RECEIVED:
            case self::STATUS_RECEIVED_WITH_DIFFERENCES:
            case self::STATUS_COMPLETED:
            case self::STATUS_CANCELLED:
                return false; // Estados finales, no hay transición
            default:
                return false;
        }
    }

    public function canBeCompleted(): bool
    {
        // Un traslado puede completarse si:
        // 1. Tiene estatus completed históricamente (compatibilidad)
        // 2. O todos los items han sido recibidos exactamente
        return $this->isCompleted() || 
               ($this->isReceived() && $this->items->every(fn($item) => $item->received_quantity !== null && bccomp((string) $item->received_quantity, (string) $item->sent_quantity, 4) === 0));
    }

    public function canBePrepared(): bool
    {
        return $this->isPending();
    }

    public function canBeDispatched(): bool
    {
        return $this->isPrepared();
    }

    public function canBeReviewed(): bool
    {
        return $this->isInTransit();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PREPARED,
        ], true);
    }
}