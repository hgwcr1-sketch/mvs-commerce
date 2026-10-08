<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 10A - expediente de evaluación ligado a UNA solicitud (no a Customer). */
class CobrosCreditExpediente extends Model
{
    use BelongsToCobrosTenant;

    protected $fillable = [
        'company_id',
        'customer_id',
        'credit_request_id',
        'collector_user_id',
        'last_edited_by',
        'last_edited_at',
        'occupation',
        'workplace',
        'income_amount',
        'income_frequency',
        'activity_time',
        'address_reference',
        'has_guarantor',
        'guarantor_name',
        'guarantor_identification',
        'guarantor_phone',
        'guarantor_address',
        'guarantor_occupation',
        'guarantor_income',
    ];

    protected function casts(): array
    {
        return [
            'income_amount' => 'decimal:2',
            'guarantor_income' => 'decimal:2',
            'has_guarantor' => 'boolean',
            'last_edited_at' => 'datetime',
        ];
    }

    /** Cliente dueño del expediente permanente. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** Última solicitud relacionada (histórico; el expediente NO depende de ella). */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditRequest::class, 'credit_request_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(CobrosExpedienteReference::class, 'expediente_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CobrosExpedienteDocument::class, 'expediente_id');
    }
}
