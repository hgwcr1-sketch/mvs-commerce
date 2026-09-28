<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyMessageTemplate extends Model
{
    protected $fillable = ['company_id', 'opportunity_type', 'body'];
}
