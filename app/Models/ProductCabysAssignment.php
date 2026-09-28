<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación de un código CABYS a un producto, POR EMPRESA (MF04).
 *
 * `status = pending` es una propuesta NO confirmada: el producto tiene un
 * candidato pero nadie decidió contra el catálogo vigente. Nunca es un
 * código utilizable en venta.
 */
class ProductCabysAssignment extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BULK = 'bulk';

    public const SOURCE_IMPORT = 'import';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'company_id',
        'product_id',
        'code',
        'fiscal_catalog_version_id',
        'source',
        'status',
        'confidence',
        'candidates',
        'previous_code',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'candidates' => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FiscalCatalogVersion::class, 'fiscal_catalog_version_id');
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}
