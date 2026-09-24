<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalCatalogVersion extends Model
{
    protected $fillable = ['source', 'source_version', 'published_at', 'valid_from', 'valid_until', 'checksum', 'imported_at', 'status'];

    protected $casts = ['published_at' => 'datetime', 'valid_from' => 'date', 'valid_until' => 'date', 'imported_at' => 'datetime'];

    public function profiles(): HasMany
    {
        return $this->hasMany(FiscalProfile::class);
    }
}