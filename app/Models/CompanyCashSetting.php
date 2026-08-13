<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyCashSetting extends Model
{
    public const SESSION_MODE_INDIVIDUAL = 'individual';
    public const SESSION_MODE_SHARED = 'shared';
    public const USD_CHANGE_CRC_ONLY = 'crc_only';
    public const USD_CHANGE_USD_ONLY = 'usd_only';
    public const USD_CHANGE_EITHER = 'either';

    protected $fillable = ['company_id', 'require_open_session', 'allow_multiple_registers', 'session_mode', 'difference_tolerance', 'require_difference_authorization', 'auto_print_closure', 'blind_closing', 'accepts_usd', 'usd_exchange_rate_min', 'usd_exchange_rate_max', 'usd_change_policy', 'closure_email_recipients'];

    protected function casts(): array
    {
        return ['require_open_session' => 'boolean', 'allow_multiple_registers' => 'boolean', 'difference_tolerance' => 'decimal:4', 'require_difference_authorization' => 'boolean', 'auto_print_closure' => 'boolean', 'blind_closing' => 'boolean', 'accepts_usd' => 'boolean', 'usd_exchange_rate_min' => 'decimal:4', 'usd_exchange_rate_max' => 'decimal:4', 'closure_email_recipients' => 'array'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
