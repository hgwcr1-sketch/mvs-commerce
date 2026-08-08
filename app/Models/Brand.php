<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Empresa propietaria de la marca.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Productos de la marca.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}