<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSING = 'closing';
    public const STATUS_CLOSED = 'closed';
    public const OPEN_GUARD = 'OPEN';

    protected $fillable = ['company_id', 'branch_id', 'cash_register_id', 'session_number', 'opened_by', 'closed_by', 'difference_authorized_by', 'status', 'open_guard', 'currency_code', 'opening_amount', 'expected_cash', 'counted_cash', 'difference_amount', 'tolerance_snapshot', 'accepts_usd_snapshot', 'usd_exchange_rate', 'exchange_rate_entered_by', 'opening_amount_usd', 'expected_cash_usd', 'counted_cash_usd', 'difference_amount_usd', 'blind_closing_snapshot', 'usd_change_policy_snapshot', 'opened_at', 'closing_started_at', 'closing_started_by', 'closing_request_token', 'closing_confirmation_token', 'closing_submitted_at', 'closed_at', 'difference_authorized_at', 'closing_notes'];

    protected function casts(): array
    {
        return ['opening_amount' => 'decimal:4', 'expected_cash' => 'decimal:4', 'counted_cash' => 'decimal:4', 'difference_amount' => 'decimal:4', 'tolerance_snapshot' => 'decimal:4', 'accepts_usd_snapshot' => 'boolean', 'usd_exchange_rate' => 'decimal:4', 'opening_amount_usd' => 'decimal:4', 'expected_cash_usd' => 'decimal:4', 'counted_cash_usd' => 'decimal:4', 'difference_amount_usd' => 'decimal:4', 'blind_closing_snapshot' => 'boolean', 'opened_at' => 'datetime', 'closing_started_at' => 'datetime', 'closing_submitted_at' => 'datetime', 'closed_at' => 'datetime', 'difference_authorized_at' => 'datetime'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashRegister(): BelongsTo { return $this->belongsTo(CashRegister::class); }
    public function openedBy(): BelongsTo { return $this->belongsTo(User::class, 'opened_by'); }
    public function closingStartedBy(): BelongsTo { return $this->belongsTo(User::class, 'closing_started_by'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function differenceAuthorizedBy(): BelongsTo { return $this->belongsTo(User::class, 'difference_authorized_by'); }
    public function exchangeRateEnteredBy(): BelongsTo { return $this->belongsTo(User::class, 'exchange_rate_entered_by'); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
    public function salePayments(): HasMany { return $this->hasMany(SalePayment::class); }
    public function movements(): HasMany { return $this->hasMany(CashMovement::class); }
    public function events(): HasMany { return $this->hasMany(CashSessionEvent::class); }
    public function countDetails(): HasMany { return $this->hasMany(CashCountDetail::class); }
    public function paymentReconciliations(): HasMany { return $this->hasMany(CashPaymentReconciliation::class); }
    public function scopeForCompany(Builder $query, int $companyId): Builder { return $query->where('company_id', $companyId); }
    public function scopeForBranch(Builder $query, int $branchId): Builder { return $query->where('branch_id', $branchId); }
    public function scopeOpen(Builder $query): Builder { return $query->where('status', self::STATUS_OPEN); }
}
