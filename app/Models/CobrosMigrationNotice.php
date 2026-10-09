<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 13-1A — «Migración pendiente de revisar» por importación directa de cobrador. */
class CobrosMigrationNotice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REVIEWED = 'reviewed';

    public const SOURCE_CUSTOMER_IMPORT = 'customer_import';

    public const SOURCE_CREDIT_IMPORT = 'credit_import';

    protected $fillable = [
        'company_id', 'cobros_route_id', 'collector_user_id', 'source', 'source_id',
        'details', 'summary', 'status', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['summary' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function route()
    {
        return $this->belongsTo(CobrosRoute::class, 'cobros_route_id');
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
