<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría e idempotencia del envío de comprobantes electrónicos al
 * receptor. Una fila por documento + tipo de entrega: como máximo UN
 * envío automático exitoso por ElectronicDocument (unique compuesto).
 */
class ElectronicDocumentMailDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const TYPE_AUTOMATIC = 'automatic';

    public const TYPE_MANUAL = 'manual';

    public const MAX_ATTEMPTS = 5;

    protected $table = 'electronic_document_mail_deliveries';

    protected $fillable = [
        'company_id',
        'electronic_document_id',
        'recipient',
        'delivery_type',
        'status',
        'attempts',
        'last_error',
        'message',
        'attachments',
        'user_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function document()
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
