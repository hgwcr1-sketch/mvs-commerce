<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * REN-03C1 — Solicitud de renovación de crédito (solo estado y cálculo
 * congelado; NUNCA crea ni cierra créditos en esta fase).
 *
 * status: pending (requiere aprobación del prestamista) | approved
 * (aprobación directa autorizada en servidor). La activación posterior
 * exige consentimiento y nueva firma del cliente en fase aparte.
 */
class CobrosCreditRenewalRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'company_id',
        'credit_id',
        'account_receivable_id',
        'collector_user_id',
        'new_capital',
        'previous_pending',
        'previous_principal',
        'previous_interest',
        'new_interest',
        'consolidated_total',
        'disbursement',
        'installment_count',
        'frequency',
        'first_due_date',
        'schedule_snapshot',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'schedule_snapshot' => 'array',
            'first_due_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function credit()
    {
        return $this->belongsTo(CobrosCredit::class, 'credit_id');
    }

    public function account()
    {
        return $this->belongsTo(AccountReceivable::class, 'account_receivable_id');
    }
}
