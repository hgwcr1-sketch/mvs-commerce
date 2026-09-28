<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseVerification extends Model
{
    public const OPEN_STATUSES = ['pending', 'in_review', 'conform', 'differences'];

    protected $fillable = ['company_id', 'branch_id', 'purchase_id', 'created_by', 'assigned_by', 'assigned_to', 'verified_by', 'resolved_by', 'status', 'assigned_at', 'started_at', 'verified_at', 'resolved_at', 'resolution_notes'];

    protected $casts = ['assigned_at' => 'datetime', 'started_at' => 'datetime', 'verified_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function purchase(): BelongsTo { return $this->belongsTo(Purchase::class); }
    public function items(): HasMany { return $this->hasMany(PurchaseVerificationItem::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
