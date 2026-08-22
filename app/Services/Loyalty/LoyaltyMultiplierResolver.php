<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyMultiplier;
use Carbon\CarbonImmutable;

class LoyaltyMultiplierResolver
{
    public function resolve(Company $company, Branch|int|null $branch, mixed $effectiveAt): ?LoyaltyMultiplier
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;
        $instant = CarbonImmutable::parse($effectiveAt ?? now(), $company->timezone ?: config('app.timezone'))->utc();

        return LoyaltyMultiplier::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', $instant)
            ->where('ends_at', '>=', $instant)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchId, fn ($query) => $query->orWhere('branch_id', $branchId)))
            ->orderByDesc('multiplier')
            ->orderBy('id')
            ->first();
    }
}
