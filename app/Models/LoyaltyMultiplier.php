<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyMultiplier extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'name', 'multiplier', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return ['multiplier' => 'decimal:4', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
