<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CobrosAssignment extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'collector_user_id',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
