<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 7B — evidencia de identidad referenciando el storage privado de 7A.
 * Documento PRIVADO por origen: solo la company que lo aportó puede leerlo.
 */
class CobrosBureauIdentityDocument extends Model
{
    public const TYPE_FRONT = 'front';

    public const TYPE_BACK = 'back';

    public const TYPES = [self::TYPE_FRONT, self::TYPE_BACK];

    protected $table = 'cobros_bureau_identity_documents';

    public const UPDATED_AT = null;

    protected $fillable = [
        'bureau_identity_id',
        'source_credit_request_id',
        'source_company_id',
        'source_collector_user_id',
        'document_type',
        'private_path',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CobrosBureauIdentity::class, 'bureau_identity_id');
    }

    public function creditRequest(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditRequest::class, 'source_credit_request_id');
    }

    public function scopeFromCompany($query, int $companyId)
    {
        return $query->where('source_company_id', $companyId);
    }
}
