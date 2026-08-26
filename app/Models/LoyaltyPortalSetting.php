<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPortalSetting extends Model
{
    protected $fillable = ['company_id', 'is_active', 'show_active_offers', 'welcome_message'];

    protected $casts = ['is_active' => 'boolean', 'show_active_offers' => 'boolean'];
}
