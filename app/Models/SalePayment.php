<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'sale_id',
        'payment_method_id',
        'created_by',
        'amount',
        'received_amount',
        'change_amount',
        'reference',
        'status',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'received_amount' => 'decimal:4',
            'change_amount' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
