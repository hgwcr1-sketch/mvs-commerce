<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'alert_id',
    'user_id',
    'read_at',
    'dismissed_at',
])]

class AlertRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
