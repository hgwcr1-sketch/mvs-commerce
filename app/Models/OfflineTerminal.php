<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OfflineTerminal extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_REVOKED];

    protected $fillable = [
        'company_id',
        'branch_id',
        'terminal_uuid',
        'name',
        'status',
        'secret_hash',
        'registered_by',
        'notes',
        'last_validated_at',
    ];

    protected function casts(): array
    {
        return [
            'last_validated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OfflineTerminal $terminal) {
            if (empty($terminal->terminal_uuid)) {
                $terminal->terminal_uuid = (string) Str::uuid();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(OfflineAuthorization::class, 'offline_terminal_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function revoke(): void
    {
        $this->update(['status' => self::STATUS_REVOKED]);
    }
}
