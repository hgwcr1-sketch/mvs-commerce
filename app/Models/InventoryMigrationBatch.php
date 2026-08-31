<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMigrationBatch extends Model
{
    protected $fillable = ['company_id', 'user_id', 'source_key', 'row_count', 'imported_at'];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
