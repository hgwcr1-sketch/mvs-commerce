<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalConsumption extends Model
{
    public const CLASSIFICATION_INCLUDED = 'included';
    public const CLASSIFICATION_OVERAGE = 'overage';

    /** Ledger inmutable: solo creación, jamás actualización ni borrado. */
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'electronic_document_id',
        'document_type',
        'period',
        'classification',
        'unit_price',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['period' => 'date', 'unit_price' => 'decimal:4'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function electronicDocument(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class);
    }
}
