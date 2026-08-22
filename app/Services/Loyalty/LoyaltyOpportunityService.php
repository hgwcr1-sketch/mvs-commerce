<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class LoyaltyOpportunityService
{
    public function localToday(Company $company): CarbonImmutable
    {
        return CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfDay();
    }

    public function baseQuery(Company $company): Builder
    {
        return Customer::query()
            ->where('customers.company_id', $company->id)
            ->where('customers.is_active', true)
            ->leftJoin('loyalty_accounts', function ($join) use ($company) {
                $join->on('loyalty_accounts.customer_id', '=', 'customers.id')->where('loyalty_accounts.company_id', $company->id);
            })
            ->select('customers.*')
            ->selectRaw('COALESCE(loyalty_accounts.balance, 0) as loyalty_balance')
            ->addSelect('loyalty_accounts.last_qualifying_purchase_at');
    }

    public function dashboard(Company $company): array
    {
        $today = $this->localToday($company);
        $birthdays = (clone $this->baseQuery($company))->whereMonth('birth_date', $today->month)->whereDay('birth_date', $today->day)->count();

        $ranges = collect(['inactive_30' => [30, 59], 'inactive_60' => [60, 89], 'inactive_90' => [90, null]])
            ->map(fn ($range) => $this->inactivityQuery($company, $range[0], $range[1], $today)->count())->all();

        $movements = LoyaltyMovement::query()->where('company_id', $company->id)
            ->whereBetween('effective_at', [$today->utc(), $today->endOfDay()->utc()])
            ->selectRaw('type, COUNT(*) as total, COALESCE(SUM(points), 0) as points')->groupBy('type')->get()->keyBy('type');

        return [
            'birthdays' => $birthdays,
            ...$ranges,
            'birthday_awards' => (int) ($movements[LoyaltyMovement::TYPE_BIRTHDAY]->total ?? 0),
            'return_awards' => (int) ($movements[LoyaltyMovement::TYPE_RETURN_CUSTOMER]->total ?? 0),
            'purchase_points' => (string) ($movements[LoyaltyMovement::TYPE_PURCHASE]->points ?? '0'),
        ];
    }

    public function inactivityQuery(Company $company, int $minimum, ?int $maximum, CarbonImmutable $today): Builder
    {
        $query = $this->baseQuery($company)->whereNotNull('loyalty_accounts.last_qualifying_purchase_at')
            ->where('loyalty_accounts.last_qualifying_purchase_at', '<=', $today->subDays($minimum)->endOfDay()->utc());

        if ($maximum !== null) {
            $query->where('loyalty_accounts.last_qualifying_purchase_at', '>', $today->subDays($maximum + 1)->endOfDay()->utc());
        }

        return $query;
    }

    public function opportunities(Company $company, Request $request)
    {
        $today = $this->localToday($company);
        $type = $request->string('type')->toString();
        $query = $this->baseQuery($company)->withCount(['loyaltyContacts as contacts_count' => function ($query) use ($company) {
            $query->where('company_id', $company->id);
        }]);

        if ($type === 'birthday') {
            $query->whereMonth('birth_date', $today->month)->whereDay('birth_date', $today->day);
        } elseif (in_array($type, ['inactive_30', 'inactive_60', 'inactive_90'], true)) {
            [$minimum, $maximum] = match ($type) {
                'inactive_30' => [30, 59],
                'inactive_60' => [60, 89],
                default => [90, null],
            };
            $query = $this->inactivityQuery($company, $minimum, $maximum, $today)->withCount(['loyaltyContacts as contacts_count' => fn ($query) => $query->where('company_id', $company->id)]);
        } else {
            $query->where(function ($query) use ($today) {
                $query->where(fn ($birthday) => $birthday->whereMonth('birth_date', $today->month)->whereDay('birth_date', $today->day))
                    ->orWhere('loyalty_accounts.last_qualifying_purchase_at', '<=', $today->subDays(30)->endOfDay()->utc());
            });
        }

        if ($request->filled('contacted')) {
            $request->boolean('contacted') ? $query->has('loyaltyContacts') : $query->doesntHave('loyaltyContacts');
        }

        $paginator = $query->orderBy('customers.name')->paginate(20)->withQueryString();
        $paginator->getCollection()->each(function (Customer $customer) use ($today): void {
            $days = $customer->last_qualifying_purchase_at
                ? CarbonImmutable::parse($customer->last_qualifying_purchase_at)->diffInDays($today)
                : 0;
            $birthday = $customer->birth_date && $customer->birth_date->month === $today->month && $customer->birth_date->day === $today->day;
            $customer->setAttribute('days_inactive', $days);
            $customer->setAttribute('opportunity_type', $birthday ? 'birthday' : ($days >= 90 ? 'inactive_90' : ($days >= 60 ? 'inactive_60' : 'inactive_30')));
        });

        return $paginator;
    }
}
