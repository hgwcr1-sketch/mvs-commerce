<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GeoCatalogLevel extends Model
{
    protected $fillable = [
        'country_id',
        'level',
        'name',
        'parent_level',
        'is_active',
    ];

    protected $casts = [
        'level' => 'integer',
        'parent_level' => 'integer',
        'is_active' => 'boolean',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry(Builder $query, string $iso2): Builder
    {
        return $query->whereHas('country', fn (Builder $q) => $q->where('iso2', $iso2));
    }
}
