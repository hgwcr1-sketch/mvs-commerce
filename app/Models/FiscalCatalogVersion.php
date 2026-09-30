<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versión de un catálogo fiscal (MF04).
 *
 * `kind` separa familias que comparten la tabla: hoy solo existe `cabys`;
 * `tax` queda reservado para perfiles fiscales. Una versión es la que
 * autoriza cada entrada de `cabys_catalog_entries` y cada asignación
 * confirmada.
 */
class FiscalCatalogVersion extends Model
{
    public const KIND_CABYS = 'cabys';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'kind',
        'source',
        'source_version',
        'published_at',
        'valid_from',
        'valid_until',
        'checksum',
        'row_count',
        'imported_at',
        'activated_at',
        'status',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'imported_at' => 'datetime',
        'activated_at' => 'datetime',
        'row_count' => 'integer',
    ];

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * ¿Está vigente en la fecha dada? Sin rangos declarados se asume vigencia
     * abierta: una versión activada sin fechas sigue siendo la vigente.
     */
    public function isEffectiveOn(string $date): bool
    {
        if ($this->valid_from !== null && $date < $this->valid_from->toDateString()) {
            return false;
        }

        if ($this->valid_until !== null && $date > $this->valid_until->toDateString()) {
            return false;
        }

        return true;
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(FiscalProfile::class);
    }
}
