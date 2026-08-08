<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'phone',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Productos disponibles en esta sucursal.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'branch_product')
            ->withPivot([
                'stock',
                'minimum_stock',
                'maximum_stock',
            ])
            ->withTimestamps();
    }
}