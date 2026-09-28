<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPromotion extends Model
{
    protected $fillable = ['company_id', 'title', 'description', 'starts_at', 'ends_at', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
