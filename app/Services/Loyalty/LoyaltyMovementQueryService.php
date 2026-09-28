<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LoyaltyMovementQueryService
{
    public function paginate(int $companyId, array $filters): LengthAwarePaginator
    {
        return LoyaltyMovement::query()
            ->where('company_id', $companyId)
            ->with([
                'customer:id,company_id,name,identification',
                'branch:id,company_id,name',
                'user:id,name',
                'loyaltyAccount:id,company_id,customer_id,balance',
                'relatedMovement' => fn ($query) => $query->where('company_id', $companyId)
                    ->select(['id', 'company_id', 'type', 'points', 'event_key']),
            ])
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('effective_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('effective_at', '<=', $date))
            ->when(trim((string) ($filters['search'] ?? '')), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhere('event_key', 'like', "%{$search}%")
                        ->orWhere('source_type', 'like', "%{$search}%")
                        ->orWhereRaw('CAST(source_id AS CHAR) LIKE ?', ["%{$search}%"]);
                });
            })
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function filterOptions(int $companyId): array
    {
        return [
            'branches' => Branch::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    public function customersWithAccounts(int $companyId)
    {
        return Customer::query()
            ->where('company_id', $companyId)
            ->whereIn('id', LoyaltyAccount::query()->where('company_id', $companyId)->select('customer_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'identification']);
    }

    public function detail(int $companyId, int $movementId): LoyaltyMovement
    {
        return LoyaltyMovement::query()
            ->where('company_id', $companyId)
            ->with([
                'company:id,trade_name',
                'customer:id,company_id,name,identification',
                'branch:id,company_id,name',
                'user:id,name',
                'loyaltyAccount:id,company_id,customer_id,balance,total_earned,total_redeemed,total_expired',
                'relatedMovement' => fn ($query) => $query->where('company_id', $companyId)
                    ->select(['id', 'company_id', 'type', 'points', 'description', 'effective_at', 'event_key']),
                'lines',
            ])
            ->findOrFail($movementId);
    }
}
