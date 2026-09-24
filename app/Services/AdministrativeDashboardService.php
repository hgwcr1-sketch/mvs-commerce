<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use DateTimeZone;

class AdministrativeDashboardService
{
    public function summarize(Company $company, ?int $branchId, string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $timezone = in_array($company->timezone, DateTimeZone::listIdentifiers(), true)
            ? $company->timezone : config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        $start = match ($period) {
            'week' => $now->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $now->startOfMonth(),
            'year' => $now->startOfYear(),
            'custom' => $this->parseDate($dateFrom, $timezone)->startOfDay(),
            default => $now->startOfDay(),
        };

        $end = match ($period) {
            'week' => $start->addWeek(),
            'month' => $start->addMonth(),
            'year' => $start->addYear(),
            'custom' => $this->parseDate($dateTo, $timezone)->startOfDay()->addDay(),
            default => $start->addDay(),
        };

        $sales = Sale::forCompany($company->id)
            ->when($branchId, fn ($query) => $query->forBranch($branchId))
            ->where('currency_code', $company->currency)
            ->whereIn('status', [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIALLY_RETURNED, Sale::STATUS_RETURNED])
            ->where('completed_at', '>=', $start->utc())
            ->where('completed_at', '<', $end->utc());
        $row = (clone $sales)->selectRaw('COUNT(*) as quantity, COALESCE(SUM(total), 0) as amount')->first();

        $creditNotes = CreditNote::forCompany($company->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('currency_code', $company->currency)
            ->whereIn('status', [CreditNote::STATUS_ISSUED, CreditNote::STATUS_PARTIALLY_APPLIED, CreditNote::STATUS_APPLIED])
            ->where('issued_at', '>=', $start->utc())
            ->where('issued_at', '<', $end->utc());

        return [
            'period' => $period,
            'from' => $start->format('d/m/Y'),
            'to' => $end->subDay()->format('d/m/Y'),
            'date_from' => $start->format('Y-m-d'),
            'date_to' => $end->subDay()->format('Y-m-d'),
            'timezone' => $timezone,
            'currency' => $company->currency,
            'branch' => $branchId ? $company->branches()->findOrFail($branchId)->name : 'Todas las sucursales',
            'sales_count' => (int) $row->quantity,
            'sales_total' => bcadd((string) $row->amount, '0', 4),
            'average_sale' => $row->quantity ? bcdiv((string) $row->amount, (string) $row->quantity, 4) : '0.0000',
            'movements' => $this->movements($sales, $creditNotes, $timezone),
        ];
    }

    private function parseDate(?string $value, string $timezone): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return CarbonImmutable::now($timezone);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, $timezone)
            ?? CarbonImmutable::parse($value, $timezone);
    }

    private function movements($sales, $creditNotes, string $timezone): array
    {
        $statusLabels = [
            Sale::STATUS_COMPLETED => 'Completada',
            Sale::STATUS_PARTIALLY_RETURNED => 'Parcialmente devuelta',
            Sale::STATUS_RETURNED => 'Devuelta',
            CreditNote::STATUS_ISSUED => 'Emitida',
            CreditNote::STATUS_PARTIALLY_APPLIED => 'Aplicada parcialmente',
            CreditNote::STATUS_APPLIED => 'Aplicada',
        ];

        $saleRows = (clone $sales)
            ->with(['customer:id,name', 'branch:id,name'])
            ->latest('completed_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Sale $sale) => [
                'type' => 'sale',
                'type_label' => 'Venta',
                'reference' => $sale->sale_number,
                'occurred_at' => $sale->completed_at->setTimezone($timezone),
                'branch' => $sale->branch?->name,
                'customer' => $sale->customer?->name ?? 'Consumidor final',
                'amount' => $sale->total,
                'status' => $sale->status,
                'status_label' => $statusLabels[$sale->status] ?? $sale->status,
            ]);

        $noteRows = (clone $creditNotes)
            ->with(['customer:id,name', 'branch:id,name'])
            ->latest('issued_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (CreditNote $note) => [
                'type' => 'credit_note',
                'type_label' => 'Nota de crédito',
                'reference' => $note->credit_note_number,
                'occurred_at' => $note->issued_at->setTimezone($timezone),
                'branch' => $note->branch?->name,
                'customer' => $note->customer?->name ?? 'Consumidor final',
                'amount' => $note->issued_amount,
                'status' => $note->status,
                'status_label' => $statusLabels[$note->status] ?? $note->status,
            ]);

        return $saleRows
            ->concat($noteRows)
            ->sortByDesc(fn (array $m) => $m['occurred_at']->getTimestamp())
            ->values()
            ->take(10)
            ->all();
    }
}
