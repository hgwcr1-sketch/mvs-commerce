<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyMovement extends Model
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_NEW_CUSTOMER = 'new_customer';
    public const TYPE_BIRTHDAY = 'birthday';
    public const TYPE_RETURN_CUSTOMER = 'return_customer';
    public const TYPE_PROMOTION = 'promotion';
    public const TYPE_REDEMPTION = 'redemption';
    public const TYPE_REWARD = 'reward';
    public const TYPE_RETURN = 'return';
    public const TYPE_VOID = 'void';
    public const TYPE_EXPIRATION = 'expiration';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_PURCHASE,
        self::TYPE_NEW_CUSTOMER,
        self::TYPE_BIRTHDAY,
        self::TYPE_RETURN_CUSTOMER,
        self::TYPE_PROMOTION,
        self::TYPE_REDEMPTION,
        self::TYPE_REWARD,
        self::TYPE_RETURN,
        self::TYPE_VOID,
        self::TYPE_EXPIRATION,
        self::TYPE_ADJUSTMENT,
    ];

    public const LABELS = [
        self::TYPE_PURCHASE => 'Compra',
        self::TYPE_NEW_CUSTOMER => 'Cliente nuevo',
        self::TYPE_BIRTHDAY => 'Cumpleaños',
        self::TYPE_RETURN_CUSTOMER => 'Cliente que retorna',
        self::TYPE_PROMOTION => 'Promoción',
        self::TYPE_REDEMPTION => 'Canje',
        self::TYPE_REWARD => 'Premio',
        self::TYPE_RETURN => 'Devolución',
        self::TYPE_VOID => 'Anulación',
        self::TYPE_EXPIRATION => 'Vencimiento',
        self::TYPE_ADJUSTMENT => 'Ajuste',
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'loyalty_account_id',
        'customer_id',
        'user_id',
        'type',
        'points',
        'balance_before',
        'balance_after',
        'base_amount',
        'earning_percentage',
        'point_value',
        'description',
        'source_type',
        'source_id',
        'related_movement_id',
        'event_key',
        'effective_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'base_amount' => 'decimal:4',
            'earning_percentage' => 'decimal:4',
            'point_value' => 'decimal:4',
            'effective_at' => 'datetime',
            'metadata' => 'array',
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

    public function loyaltyAccount(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_movement_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LoyaltyMovementLine::class);
    }
}
