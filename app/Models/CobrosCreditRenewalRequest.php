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

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONSENTED = 'consented';

    public const STATUS_ACTIVATED = 'activated';

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
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'consented_at',
        'signature_path',
        'terms_hash',
        'consent_snapshot',
        'activated_credit_id',
    ];

    protected function casts(): array
    {
        return [
            'schedule_snapshot' => 'array',
            'consent_snapshot' => 'array',
            'first_due_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    public function readyForActivation(): bool
    {
        if ($this->status !== self::STATUS_CONSENTED || ! $this->approved_by || ! $this->approved_at
            || ! $this->consented_at || ! $this->signature_path || ! $this->terms_hash || ! $this->consent_snapshot) {
            return false;
        }
        $consent = app(\App\Services\Cobros\CreditRenewalConsentService::class);
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! hash_equals($this->terms_hash, $consent->hashSnapshot($consent->consentSnapshot($this)))
            || ! hash_equals($this->terms_hash, $consent->hashSnapshot($this->consent_snapshot))
            || ! str_starts_with($this->signature_path, 'cobros/renewal-consent/'.$this->id.'/')
            || str_contains($this->signature_path, '..') || ! $disk->exists($this->signature_path)) {
            return false;
        }
        $image = @getimagesizefromstring($disk->get($this->signature_path));

        return $image !== false && $image[2] === IMAGETYPE_PNG;
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
