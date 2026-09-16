<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OfflineAuthorization extends Model
{
    public const RESULT_GRANTED = 'granted';
    public const RESULT_DENIED = 'denied';

    public const RESULTS = [self::RESULT_GRANTED, self::RESULT_DENIED];

    protected $fillable = [
        'offline_terminal_id',
        'company_id',
        'branch_id',
        'user_id',
        'authorization_id',
        'issued_at',
        'valid_until',
        'result',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OfflineAuthorization $auth) {
            if (empty($auth->authorization_id)) {
                $auth->authorization_id = (string) Str::uuid();
            }
        });
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(OfflineTerminal::class, 'offline_terminal_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isGranted(): bool
    {
        return $this->result === self::RESULT_GRANTED;
    }

    public function isDenied(): bool
    {
        return $this->result === self::RESULT_DENIED;
    }
}
