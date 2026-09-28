<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada del catálogo CABYS de una versión concreta (MF04).
 *
 * GLOBAL por diseño: no tiene `company_id`. El catálogo oficial es el mismo
 * para todos los tenants; lo por-empresa es la asignación a producto
 * (`ProductCabysAssignment`).
 *
 * `code` es texto, nunca entero: los códigos CABYS empiezan con ceros
 * (`0111100000100`) y un cast numérico los destruiría.
 */
class CabysCatalogEntry extends Model
{
    protected $table = 'cabys_catalog_entries';

    protected $fillable = [
        'fiscal_catalog_version_id',
        'code',
        'description',
        'tax_rate_raw',
        'tax_rate_pct',
        'note_include',
        'note_exclude',
        'is_active',
        'position',
    ];

    protected $casts = [
        'tax_rate_pct' => 'decimal:2',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(FiscalCatalogVersion::class, 'fiscal_catalog_version_id');
    }

    /**
     * Traducción numérica de `tax_rate_raw`, o null si el texto oficial no es
     * inequívoco ("Exento", "0", "1%" sí; "Según ley" no).
     *
     * Se resuelve al LEER, no al importar: una interpretación equivocada
     * puede corregirse sin recargar el catálogo completo.
     */
    public function taxRatePercent(): ?float
    {
        if ($this->tax_rate_pct !== null) {
            return (float) $this->tax_rate_pct;
        }

        $raw = mb_strtolower(trim((string) $this->tax_rate_raw));

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})(?:[.,](\d{1,2}))?\s*%$/', $raw, $m) === 1) {
            $decimals = isset($m[2]) && $m[2] !== '' ? (float) ('0.'.$m[2]) : 0.0;

            return (float) $m[1] + $decimals;
        }

        if (in_array($raw, ['exento', 'exenta', '0', '0%'], true)) {
            return 0.0;
        }

        /** Cualquier otro texto queda en null a propósito: afirmar una tarifa
         *  fiscal que la fuente no afirma es peor que no afirmarla. */
        return null;
    }
}
