<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPayableAlert extends Model
{
    public const TYPE_UPCOMING = 'upcoming';
    public const TYPE_OVERDUE = 'overdue';

    protected $fillable = ['account_payable_id', 'company_id', 'type', 'notified_at'];
    protected function casts(): array { return ['notified_at' => 'datetime']; }
    public function accountPayable(): BelongsTo { return $this->belongsTo(AccountPayable::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
