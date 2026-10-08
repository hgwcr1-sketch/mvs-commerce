<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 7B — bitácora APPEND-ONLY de Buró: sin updated_at; los servicios normales
 * no actualizan ni eliminan historial.
 */
class CobrosBureauEvent extends Model
{
    public const TYPE_IDENTITY_CREATED = 'identity_created';

    public const TYPE_IDENTITY_LINKED = 'identity_linked';

    public const TYPE_REQUEST_SUBMITTED = 'request_submitted';

    public const TYPE_REQUEST_REJECTED = 'request_rejected';

    public const TYPE_REQUEST_VERIFIED = 'request_verified';

    public const TYPE_CREDIT_APPROVED = 'credit_approved';

    public const TYPE_CREDIT_DISBURSED = 'credit_disbursed';

    public const TYPE_REQUEST_CORRECTION_REQUESTED = 'request_correction_requested';

    public const TYPE_DOCUMENT_ATTACHED = 'document_attached';

    public const TYPE_IDENTITY_ACCESSED = 'identity_accessed';

    protected $table = 'cobros_bureau_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'bureau_identity_id',
        'event_type',
        'source_company_id',
        'source_credit_request_id',
        'actor_user_id',
        'purpose',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CobrosBureauIdentity::class, 'bureau_identity_id');
    }
}
