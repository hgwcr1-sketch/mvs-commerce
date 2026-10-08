<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 10A - referencia personal de la solicitud (máx. 2; NO es Customer). */
class CobrosExpedienteReference extends Model
{
    use BelongsToCobrosTenant;

    protected $fillable = [
        'company_id',
        'expediente_id',
        'name',
        'phone',
        'relationship',
    ];

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditExpediente::class, 'expediente_id');
    }
}
