<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CobrosAssignmentLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_ASSIGN = 'assign';
    public const ACTION_REASSIGN = 'reassign';
    public const ACTION_UNASSIGN = 'unassign';

    protected $fillable = [
        'company_id',
        'customer_id',
        'from_collector_user_id',
        'to_collector_user_id',
        'action',
        'actor_user_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fromCollector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_collector_user_id');
    }

    public function toCollector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_collector_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
