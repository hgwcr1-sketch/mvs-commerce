<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCountDetail extends Model
{
    public const TYPE_OPENING = 'opening'; public const TYPE_CLOSING = 'closing';
    protected $fillable = ['cash_session_id', 'cash_denomination_id', 'count_type', 'quantity', 'denomination_value', 'total_amount', 'counted_by', 'counted_at'];
    protected function casts(): array { return ['quantity' => 'integer', 'denomination_value' => 'decimal:4', 'total_amount' => 'decimal:4', 'counted_at' => 'datetime']; }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
    public function cashDenomination(): BelongsTo { return $this->belongsTo(CashDenomination::class); }
    public function countedBy(): BelongsTo { return $this->belongsTo(User::class, 'counted_by'); }
    public function scopeClosing($query) { return $query->where('count_type', self::TYPE_CLOSING); }
}
