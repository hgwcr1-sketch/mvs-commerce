<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 10A - documento adicional PRIVADO del expediente (disco local, 1 por tipo). */
class CobrosExpedienteDocument extends Model
{
    use BelongsToCobrosTenant;

    protected $fillable = [
        'company_id',
        'expediente_id',
        'doc_type',
        'path',
        'original_name',
        'uploaded_by',
    ];

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditExpediente::class, 'expediente_id');
    }
}
