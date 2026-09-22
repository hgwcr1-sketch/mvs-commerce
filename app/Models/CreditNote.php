<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_APPLIED = 'partially_applied';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'sale_id',
        'sale_return_id',
        'credit_note_number',
        'currency_code',
        'issued_amount',
        'offset_amount',
        'applied_amount',
        'balance',
        'status',
        'reason',
        'issued_by',
        'issued_at',
        'expires_at',
        'application_code_hash',
        'voided_by',
        'voided_at',
        'void_reason',
        'idempotency_key',
        'requires_ar_review',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_amount' => 'decimal:4',
            'offset_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'balance' => 'decimal:4',
            'requires_ar_review' => 'boolean',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CreditNoteApplication::class);
    }

    public function codeRotations(): HasMany
    {
        return $this->hasMany(CreditNoteCodeRotation::class);
    }

    public function arAdjustments(): HasMany
    {
        return $this->hasMany(AccountReceivableAdjustment::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_ISSUED, self::STATUS_PARTIALLY_APPLIED])
            ->where('balance', '>', 0)
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * La NC está vencida cuando tiene expires_at y ya pasó la fecha.
     * expires_at null significa vigencia ilimitada.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasAvailableBalance(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIALLY_APPLIED], true)
            && bccomp((string) $this->balance, '0', 4) > 0;
    }

    /**
     * NC Consumer Final: emitida a partir de una venta sin cliente
     * identificado (customer_id null). Es valor al portador: se autoriza
     * únicamente presentando número + código secreto (4B-2) y su único
     * secreto persistido es application_code_hash.
     */
    public function isConsumerFinal(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * Elegibilidad para regenerar el código secreto (Fase 4B-4).
     *
     * Únicamente NC Consumer Final con código emitido, vigente, no anulada,
     * no aplicada por completo y con saldo disponible. La regeneración NO
     * reactiva notas vencidas: se exige además que no haya expirado.
     */
    public function canRegenerateCode(): bool
    {
        return $this->isConsumerFinal()
            && $this->application_code_hash !== null
            && in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIALLY_APPLIED], true)
            && bccomp((string) $this->balance, '0', 4) > 0
            && ! $this->isExpired();
    }
}
