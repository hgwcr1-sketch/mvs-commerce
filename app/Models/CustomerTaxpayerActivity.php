<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actividad económica oficial de un cliente, según Hacienda.
 *
 * Una fila por actividad. El `code` es único por cliente. `is_primary` queda
 * disponible si la API oficial llega a indicar la actividad principal.
 */
class CustomerTaxpayerActivity extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'code',
        'description',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Etiqueta para UI: "código descripción".
     */
    public function getLabelAttribute(): string
    {
        return trim($this->code.' '.($this->description ?? ''));
    }
}
