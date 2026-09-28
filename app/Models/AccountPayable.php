<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class AccountPayable extends Model
{
    protected $table = 'accounts_payable';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_CANCELLED];

    protected $fillable = ['company_id', 'branch_id', 'supplier_id', 'purchase_id', 'original_amount', 'paid_amount', 'balance_due', 'issue_date', 'due_date', 'status', 'currency_code', 'notes', 'created_by', 'cancelled_by', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:4', 'paid_amount' => 'decimal:4', 'balance_due' => 'decimal:4', 'issue_date' => 'date', 'due_date' => 'date', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AccountPayable $account): void {
            $purchase = Purchase::query()->find($account->purchase_id);

            if (! $purchase || $purchase->payment_type !== 'credit') {
                throw ValidationException::withMessages(['purchase_id' => 'Solo una compra a crédito puede generar una cuenta por pagar.']);
            }

            if ((int) $purchase->company_id !== (int) $account->company_id || (int) $purchase->branch_id !== (int) $account->branch_id || (int) $purchase->supplier_id !== (int) $account->supplier_id) {
                throw ValidationException::withMessages(['purchase_id' => 'La compra no pertenece a la empresa, sucursal y proveedor indicados.']);
            }

            $original = (float) $account->original_amount;
            $paid = (float) $account->paid_amount;
            $balance = (float) $account->balance_due;
            if ($original <= 0 || $paid < 0 || $paid > $original || abs(($original - $paid) - $balance) > 0.0001 || ! in_array($account->status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['original_amount' => 'Los importes y el estado de la cuenta por pagar no son válidos.']);
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function purchase(): BelongsTo { return $this->belongsTo(Purchase::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function payments(): HasMany { return $this->hasMany(AccountPayablePayment::class); }
    public function alerts(): HasMany { return $this->hasMany(AccountPayableAlert::class); }
    public function scopeForCompany(Builder $query, int $id): Builder { return $query->where('company_id', $id); }
    public function scopeForBranch(Builder $query, int $id): Builder { return $query->where('branch_id', $id); }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->balance_due > 0 && $this->due_date->isBefore(today()) && ! in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true)
            ? self::STATUS_OVERDUE : $this->status;
    }
}
