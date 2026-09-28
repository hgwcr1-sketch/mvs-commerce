<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'phone',
        'address',
        'is_active',
        'receipt_format',
        'receipt_auto_print',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'receipt_auto_print' => 'boolean',
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

    /**
     * Lotes almacenados en esta sucursal.
     */
    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
