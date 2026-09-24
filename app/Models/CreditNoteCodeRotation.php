<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de auditoría de rotación del código secreto de una NC Consumer
 * Final (Fase 4B-4).
 *
 * Nunca almacena secretos: ni el código anterior ni el nuevo (en texto plano
 * ni hash). Solo evidencia la ocurrencia de una regeneración, su autor, la
 * fecha y el motivo. El hash vigente de la NC vive exclusivamente en
 * credit_notes.application_code_hash y es reemplazado en la misma transacción
 * que registra esta auditoría.
 */
class CreditNoteCodeRotation extends Model
{
    protected $fillable = [
        'company_id',
        'credit_note_id',
        'user_id',
        'reason',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}