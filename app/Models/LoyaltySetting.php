<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltySetting extends Model
{
    protected $fillable = [
        'company_id',
        'is_active',
        'earning_percentage',
        'point_value',
        'minimum_redemption_points',
        'redemption_minimum_enabled',
        'redemption_minimum_amount',
        'maximum_redemption_percent',
        'earn_on_offers',
        'birthday_enabled',
        'birthday_points',
        'returning_customer_enabled',
        'returning_customer_days',
        'returning_customer_points',
        'redeem_on_offers',
        'expiration_enabled',
        'expiration_months',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'earning_percentage' => 'decimal:4',
            'point_value' => 'decimal:4',
            'minimum_redemption_points' => 'decimal:4',
            'redemption_minimum_enabled' => 'boolean',
            'redemption_minimum_amount' => 'decimal:4',
            'maximum_redemption_percent' => 'decimal:4',
            'earn_on_offers' => 'boolean',
            'birthday_enabled' => 'boolean',
            'birthday_points' => 'decimal:4',
            'returning_customer_enabled' => 'boolean',
            'returning_customer_days' => 'integer',
            'returning_customer_points' => 'decimal:4',
            'redeem_on_offers' => 'boolean',
            'expiration_enabled' => 'boolean',
            'expiration_months' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
