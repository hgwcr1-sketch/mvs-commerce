<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CobrosRoute extends Model
{
    use BelongsToCobrosTenant;

    protected $fillable = ['company_id', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cobros_route_user')->using(CobrosRouteUser::class)
            ->withPivot(['id', 'company_id', 'active'])->withTimestamps()
            ->withPivotValue('company_id', $this->relationCompanyId());
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CobrosAssignment::class)->where('company_id', $this->relationCompanyId());
    }

    public function accountsReceivable(): HasMany
    {
        return $this->hasMany(AccountReceivable::class)->where('company_id', $this->relationCompanyId());
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CobrosReceipt::class)->where('company_id', $this->relationCompanyId());
    }

    public function license(): HasOne
    {
        $relation = $this->hasOne(CobrosRouteLicense::class);

        return $this->company_id ? $relation->forCompany((int) $this->company_id) : $relation;
    }

    private function relationCompanyId(): int
    {
        $active = (int) session('active_company_id', 0);

        return $active && $this->company_id && $active !== (int) $this->company_id
            ? 0 : ($active ?: (int) $this->company_id);
    }
}
