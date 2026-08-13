<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegister extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'code', 'name', 'is_active', 'is_default'];
    protected function casts(): array { return ['is_active' => 'boolean', 'is_default' => 'boolean']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function sessions(): HasMany { return $this->hasMany(CashSession::class); }
    public function movements(): HasMany { return $this->hasMany(CashMovement::class); }
    public function scopeForCompany(Builder $query, int $companyId): Builder { return $query->where('company_id', $companyId); }
    public function scopeForBranch(Builder $query, int $branchId): Builder { return $query->where('branch_id', $branchId); }
    public function scopeActive(Builder $query): Builder { return $query->where('is_active', true); }
}
