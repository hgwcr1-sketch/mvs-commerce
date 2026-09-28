<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPortalLink extends Model
{
    public const TYPES = ['buy', 'store', 'catalog', 'whatsapp', 'other'];

    protected $fillable = ['company_id', 'type', 'label', 'url', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
