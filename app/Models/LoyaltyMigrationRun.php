<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyMigrationRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'user_id',
        'source_key',
        'status',
        'preview_payload',
        'valid_count',
        'pending_count',
        'consolidated_count',
        'imported_count',
        'attempts',
        'last_error',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'preview_payload' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
