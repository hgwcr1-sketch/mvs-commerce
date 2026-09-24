<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CobrosVisitLog extends Model
{
    protected $fillable = ['company_id', 'visit_id', 'from_status', 'to_status', 'actor_user_id', 'notes'];

    public function visit(): BelongsTo { return $this->belongsTo(CobrosVisit::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
