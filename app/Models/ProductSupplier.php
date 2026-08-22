<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ProductSupplier extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'supplier_id',
        'supplier_product_code',
        'current_cost',
        'is_primary',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'current_cost' => 'decimal:4',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductSupplier $relation) {
            $product = Product::query()->find($relation->product_id);
            $supplier = Supplier::query()->find($relation->supplier_id);

            if (! $product || ! $supplier
                || (int) $product->company_id !== (int) $relation->company_id
                || (int) $supplier->company_id !== (int) $relation->company_id) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'El producto y el proveedor deben pertenecer a la empresa activa.',
                ]);
            }

            if ((! $relation->exists || $relation->isDirty('supplier_id')) && ! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'No se puede asociar un proveedor inactivo.',
                ]);
            }

            if ($relation->is_primary && ! $relation->is_active) {
                throw ValidationException::withMessages([
                    'is_primary' => 'El proveedor principal debe tener una relación activa.',
                ]);
            }
        });

        static::saved(function (ProductSupplier $relation) {
            if ($relation->is_primary && $relation->is_active) {
                static::query()
                    ->where('company_id', $relation->company_id)
                    ->where('product_id', $relation->product_id)
                    ->whereKeyNot($relation->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
