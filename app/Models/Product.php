<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'category_id',
        'brand_id',
        'unit_id',
        'name',
        'internal_code',
        'barcode',
        'product_type',
        'cabys_code',
        'short_description',
        'description',
        'cost',
        'sale_price',
        'wholesale_price',
        'special_price',
        'stock',
        'track_inventory',
        'minimum_stock',
        'maximum_stock',
        'allow_negative_stock',
        'tax_rate',
        'image',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'special_price' => 'decimal:2',
        'stock' => 'decimal:2',
        'track_inventory' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Empresa propietaria del producto.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Categoría del producto.
     */
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Marca del producto.
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * Unidad de medida.
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Sucursales donde existe el producto.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_product')
            ->withPivot([
                'stock',
                'minimum_stock',
                'maximum_stock',
            ])
            ->withTimestamps();
    }

        /**
     * Códigos de barras adicionales del producto.
     */
    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }
}