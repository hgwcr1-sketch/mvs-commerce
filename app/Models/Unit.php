<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'abbreviation',
        'slug',
        'allows_decimals',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'allows_decimals' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Empresa propietaria de la unidad.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Productos asociados a la unidad.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}