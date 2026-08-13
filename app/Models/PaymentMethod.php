<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    public const TYPE_CASH = 'cash';
    public const TYPE_CARD = 'card';
    public const TYPE_SINPE = 'sinpe';
    public const TYPE_BANK_TRANSFER = 'bank_transfer';
    public const TYPE_CREDIT = 'credit';
    public const TYPE_LOYALTY_POINTS = 'loyalty_points';
    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'is_system',
        'is_active',
        'affects_cash',
        'requires_reference',
        'allows_change',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'affects_cash' => 'boolean',
            'requires_reference' => 'boolean',
            'allows_change' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cashPaymentReconciliations(): HasMany
    {
        return $this->hasMany(CashPaymentReconciliation::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
