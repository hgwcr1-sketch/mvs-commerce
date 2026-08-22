<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyCustomerContact extends Model
{
    protected $fillable = ['company_id', 'customer_id', 'user_id', 'branch_id', 'opportunity_type', 'channel', 'contacted_at', 'notes'];

    protected function casts(): array
    {
        return ['contacted_at' => 'datetime'];
    }
}
