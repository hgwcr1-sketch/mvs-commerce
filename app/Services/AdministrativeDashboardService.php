<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use DateTimeZone;

class AdministrativeDashboardService
{
    public function summarize(Company $company, ?int $branchId, string $period): array
    {
        $timezone = in_array($company->timezone, DateTimeZone::listIdentifiers(), true)
            ? $company->timezone : config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $start = match ($period) {
            'week' => $now->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $now->startOfMonth(),
            default => $now->startOfDay(),
        };
        $end = match ($period) {
            'week' => $start->addWeek(),
            'month' => $start->addMonth(),
            default => $start->addDay(),
        };
        $sales = Sale::forCompany($company->id)
            ->when($branchId, fn ($query) => $query->forBranch($branchId))
            ->where('currency_code', $company->currency)
            ->whereIn('status', [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIALLY_RETURNED, Sale::STATUS_RETURNED])
            ->where('completed_at', '>=', $start->utc())
            ->where('completed_at', '<', $end->utc());
        $row = (clone $sales)->selectRaw('COUNT(*) as quantity, COALESCE(SUM(total), 0) as amount')->first();

        return [
            'period' => $period,
            'from' => $start->format('d/m/Y'),
            'to' => $end->subDay()->format('d/m/Y'),
            'timezone' => $timezone,
            'currency' => $company->currency,
            'branch' => $branchId ? $company->branches()->findOrFail($branchId)->name : 'Todas las sucursales',
            'sales_count' => (int) $row->quantity,
            'sales_total' => bcadd((string) $row->amount, '0', 4),
            'average_sale' => $row->quantity ? bcdiv((string) $row->amount, (string) $row->quantity, 4) : '0.0000',
            'recent_sales' => (clone $sales)->with(['customer:id,name', 'branch:id,name'])->latest('completed_at')->latest('id')->limit(10)->get(),
        ];
    }
}
