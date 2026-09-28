<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashMovement extends Model
{
    public const TYPE_ENTRY = 'entry'; public const TYPE_EXIT = 'exit'; public const TYPE_WITHDRAWAL = 'withdrawal'; public const TYPE_REFUND = 'refund'; public const TYPE_PAYMENT_REVERSAL = 'payment_reversal'; public const TYPE_ADJUSTMENT = 'adjustment'; public const TYPE_REVERSAL = 'reversal';
    public const DIRECTION_IN = 'in'; public const DIRECTION_OUT = 'out';
    protected $fillable = ['company_id', 'branch_id', 'cash_register_id', 'cash_session_id', 'type', 'direction', 'amount', 'concept', 'reason', 'notes', 'request_token', 'source_type', 'source_id', 'reversed_movement_id', 'created_by', 'authorized_by', 'occurred_at'];
    protected function casts(): array { return ['amount' => 'decimal:4', 'occurred_at' => 'datetime']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashRegister(): BelongsTo { return $this->belongsTo(CashRegister::class); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
    public function source(): MorphTo { return $this->morphTo(); }
    public function reversedMovement(): BelongsTo { return $this->belongsTo(self::class, 'reversed_movement_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function authorizedBy(): BelongsTo { return $this->belongsTo(User::class, 'authorized_by'); }
    public function scopeForSession(Builder $query, int $sessionId): Builder { return $query->where('cash_session_id', $sessionId); }
}
