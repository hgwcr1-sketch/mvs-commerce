<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashSessionEvent extends Model
{
    public const TYPE_OPENED = 'opened'; public const TYPE_ENTRY = 'entry'; public const TYPE_EXIT = 'exit'; public const TYPE_WITHDRAWAL = 'withdrawal'; public const TYPE_CLOSING_STARTED = 'closing_started'; public const TYPE_CLOSING_CANCELLED = 'closing_cancelled'; public const TYPE_DIFFERENCE_AUTHORIZED = 'difference_authorized'; public const TYPE_CLOSED = 'closed'; public const TYPE_EXCHANGE_RATE_CHANGED = 'exchange_rate_changed'; public const TYPE_REVERSAL = 'reversal'; public const TYPE_MAIL_RETRY_REQUESTED = 'mail_retry_requested';
    protected $fillable = ['cash_session_id', 'event_type', 'user_id', 'payload', 'occurred_at'];
    protected function casts(): array { return ['payload' => 'array', 'occurred_at' => 'datetime']; }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
