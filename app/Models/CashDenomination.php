<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashDenomination extends Model
{
    public const TYPE_BILL = 'bill'; public const TYPE_COIN = 'coin';
    protected $fillable = ['company_id', 'currency_code', 'value', 'label', 'type', 'sort_order', 'is_active'];
    protected function casts(): array { return ['value' => 'decimal:4', 'sort_order' => 'integer', 'is_active' => 'boolean']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function countDetails(): HasMany { return $this->hasMany(CashCountDetail::class); }
    public function scopeForCompany(Builder $query, int $companyId): Builder { return $query->where('company_id', $companyId); }
    public function scopeForCurrency(Builder $query, string $currency): Builder { return $query->where('currency_code', $currency); }
    public function scopeActive(Builder $query): Builder { return $query->where('is_active', true); }
}
