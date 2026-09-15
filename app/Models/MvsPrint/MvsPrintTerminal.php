<?php

namespace App\Models\MvsPrint;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Terminal POS con configuración de impresión local (MVS Print / QZ Tray).
 *
 * Representa un punto de venta físico (o virtual) dentro de una empresa y una
 * sucursal. La entidad NO es solo una impresora: reúne la identidad de la
 * terminal (terminal_uuid), la impresora seleccionada, el ancho de papel y el
 * comportamiento de impresión (auto_print, auto_cut, open_drawer) para
 * evolucionar hacia otros periféricos sin reescribir la base.
 */
class MvsPrintTerminal extends Model
{
    use HasFactory;

    public const PAPER_WIDTH_58 = '58';

    public const PAPER_WIDTH_80 = '80';

    protected $fillable = [
        'company_id',
        'branch_id',
        'terminal_uuid',
        'name',
        'printer_name',
        'paper_width',
        'auto_print',
        'auto_cut',
        'open_drawer',
        'drawer_command',
        'enabled',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'paper_width' => 'string',
            'auto_print' => 'boolean',
            'auto_cut' => 'boolean',
            'open_drawer' => 'boolean',
            'drawer_command' => 'array',
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}