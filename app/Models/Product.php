<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
'price_a',
'price_b',
'price_c',
'stock',
        'track_inventory',
        'minimum_stock',
        'maximum_stock',
        'allow_negative_stock',
        'tax_rate',
        'image',
        'is_active',
        'prints_label',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
'special_price' => 'decimal:2',
'price_a' => 'decimal:2',
'price_b' => 'decimal:2',
'price_c' => 'decimal:2',
'stock' => 'decimal:2',
        'track_inventory' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'is_active' => 'boolean',
        'prints_label' => 'boolean',
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

    /**
     * Lotes de inventario del producto por sucursal.
     */
    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function productSuppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers')
            ->withPivot(['company_id', 'supplier_product_code', 'current_cost', 'is_primary', 'is_active', 'notes'])
            ->withTimestamps();
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
