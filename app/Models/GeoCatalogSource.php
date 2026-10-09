<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoCatalogSource extends Model
{
    protected $fillable = [
        'country_id',
        'source',
        'source_url',
        'version',
        'source_date',
        'imported_at',
        'division_count',
        'notes',
    ];

    protected $casts = [
        'source_date' => 'date',
        'imported_at' => 'datetime',
        'division_count' => 'integer',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
