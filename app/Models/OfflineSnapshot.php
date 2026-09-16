<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSnapshot extends Model
{
    public const SCHEMA_VERSION = 1;

    protected $table = 'offline_snapshots';

    protected $fillable = [
        'company_id',
        'branch_id',
        'terminal_uuid',
        'schema_version',
        'generated_at',
        'snapshot_data',
        'snapshot_size_bytes',
        'product_count',
        'customer_count',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'snapshot_data' => 'array',
            'snapshot_size_bytes' => 'integer',
            'product_count' => 'integer',
            'customer_count' => 'integer',
            'schema_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
