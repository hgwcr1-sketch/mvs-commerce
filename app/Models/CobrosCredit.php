<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BLOQUE 9 — un crédito independiente por solicitud aprobada.
 * terms_snapshot es inmutable desde la aprobación: desembolso y calendario
 * se derivan SIEMPRE de él, nunca de edits posteriores.
 */
class CobrosCredit extends Model
{
    use BelongsToCobrosTenant;

    public const STATUS_APPROVED = 'approved';
    public const STATUS_DISBURSED = 'disbursed';

    public const INTEREST_NONE = 'none';
    public const INTEREST_FIXED = 'fixed';
    public const INTEREST_PERCENT = 'percent';

    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_BIWEEKLY = 'biweekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    public const LATE_FEE_NONE = 'none';
    public const LATE_FEE_FIXED = 'fixed';
    public const LATE_FEE_PERCENT = 'percent';

    protected $fillable = [
        'company_id',
        'credit_request_id',
        'customer_id',
        'collector_user_id',
        'cobros_route_id',
        'account_receivable_id',
        'principal',
        'interest_type',
        'interest_value',
        'total_due',
        'installment_count',
        'frequency',
        'disbursement_date',
        'first_due_date',
        'late_fee_type',
        'late_fee_value',
        'late_fee_grace_days',
        'status',
        'terms_snapshot',
        'approved_at',
        'approved_by',
        'disbursed_at',
        'disbursed_by',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:4',
            'interest_value' => 'decimal:4',
            'total_due' => 'decimal:4',
            'late_fee_value' => 'decimal:4',
            'terms_snapshot' => 'array',
            'disbursement_date' => 'date',
            'first_due_date' => 'date',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
        ];
    }

    /**
     * 9C referencia PÚBLICA estable del préstamo (ej. CR-7K4M92).
     * No deriva del id secuencial ni de contadores; entropía aleatoria con
     * retry ante colisión e índice UNIQUE. Solo para mostrar al cliente —
     * ids/FKs internos siguen intactos.
     */
    protected static function booted(): void
    {
        static::creating(function (self $credit): void {
            if ($credit->public_reference === null || $credit->public_reference === '') {
                $credit->public_reference = self::generateUniquePublicReference();
            }
        });
    }

    public static function generateUniquePublicReference(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'CR-'.strtoupper(\Illuminate\Support\Str::random(6));
            // Al menos una letra y un dígito: nunca un entero puro (no secuencial).
            if (! preg_match('/[A-Z]/', $code) || ! preg_match('/[0-9]/', $code)) {
                continue;
            }
            if (! self::query()->where('public_reference', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('No fue posible generar la referencia pública del préstamo.');
    }

    /** Referencia segura para pantallas/PDF/WhatsApp; la persiste si falta. */
    public function publicReference(): string
    {
        if ($this->public_reference === null || $this->public_reference === '') {
            $this->forceFill(['public_reference' => self::generateUniquePublicReference()])->save();
        }

        return $this->public_reference;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditRequest::class, 'credit_request_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CobrosRoute::class, 'cobros_route_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class, 'account_receivable_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(CobrosCreditInstallment::class, 'credit_id')->orderBy('number');
    }

    public function acceptance(): HasOne
    {
        return $this->hasOne(CobrosCreditAcceptance::class, 'credit_id');
    }
}
