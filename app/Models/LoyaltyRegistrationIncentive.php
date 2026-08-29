<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRegistrationIncentive extends Model
{
    public const TYPE_POINTS = 'points';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const TYPES = [self::TYPE_POINTS, self::TYPE_PERCENTAGE, self::TYPE_FIXED];

    protected $fillable = ['company_id', 'is_enabled', 'benefit_type', 'benefit_value'];

    protected $casts = ['is_enabled' => 'boolean', 'benefit_value' => 'decimal:4'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
