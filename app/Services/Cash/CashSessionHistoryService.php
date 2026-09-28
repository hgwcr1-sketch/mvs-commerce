<?php

namespace App\Services\Cash;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CashSessionHistoryService
{
    public function paginate(int $companyId, int $branchId, bool $viewAll, array $filters, string $timezone): LengthAwarePaginator
    {
        $query = CashSession::query()->forCompany($companyId)
            ->when(! $viewAll, fn (Builder $query) => $query->forBranch($branchId))
            ->with([
                'branch:id,name',
                'cashRegister:id,name,code',
                'openedBy:id,name',
                'mailNotifications:id,cash_session_id,notification_type,status,attempts,available_at,sent_at,updated_at',
            ]);

        $query->when($filters['date_from'] ?? null, fn (Builder $query, string $date) =>
            $query->where('opened_at', '>=', CarbonImmutable::createFromFormat('Y-m-d H:i:s', "$date 00:00:00", $timezone)->utc()));
        $query->when($filters['date_to'] ?? null, fn (Builder $query, string $date) =>
            $query->where('opened_at', '<=', CarbonImmutable::createFromFormat('Y-m-d H:i:s', "$date 23:59:59", $timezone)->utc()));
        $query->when($filters['branch_id'] ?? null, fn (Builder $query, int $id) => $query->where('branch_id', $id));
        $query->when($filters['cash_register_id'] ?? null, fn (Builder $query, int $id) => $query->where('cash_register_id', $id));
        $query->when($filters['cashier_id'] ?? null, fn (Builder $query, int $id) => $query->where('opened_by', $id));
        $query->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));
        $query->when($filters['session_number'] ?? null, function (Builder $query, string $number) {
            $escaped = addcslashes(trim($number), '\\%_');
            $query->whereRaw("session_number LIKE ? ESCAPE '\\'", ["%$escaped%"]);
        });
        $query->when($filters['mail_status'] ?? null, function (Builder $query, string $status) use ($filters) {
            $query->whereHas('mailNotifications', fn (Builder $mail) => $mail->where('status', $status)
                ->when($filters['mail_type'] ?? null, fn (Builder $mail, string $type) => $mail->where('notification_type', $type)));
        }, function (Builder $query) use ($filters) {
            $query->when($filters['mail_type'] ?? null, fn (Builder $query, string $type) =>
                $query->whereHas('mailNotifications', fn (Builder $mail) => $mail->where('notification_type', $type)));
        });
        $query->when(($filters['difference'] ?? null) === 'with', fn (Builder $query) => $query
            ->whereNotNull('closing_submitted_at')
            ->where(fn (Builder $difference) => $difference->where('difference_amount', '!=', 0)
                ->orWhereHas('paymentReconciliations', fn (Builder $items) => $items->where('difference_amount', '!=', 0))));
        $query->when(($filters['difference'] ?? null) === 'without', fn (Builder $query) => $query
            ->whereNotNull('closing_submitted_at')
            ->where('difference_amount', 0)
            ->whereDoesntHave('paymentReconciliations', fn (Builder $items) => $items->where('difference_amount', '!=', 0)));

        return $query->orderByDesc('opened_at')->orderByDesc('id')->paginate(25)->withQueryString();
    }

    public function filterOptions(int $companyId, int $branchId, bool $viewAll): array
    {
        $sessionQuery = CashSession::query()->forCompany($companyId)->when(! $viewAll, fn (Builder $query) => $query->forBranch($branchId));
        $branchIds = (clone $sessionQuery)->select('branch_id')->distinct();
        $registerIds = (clone $sessionQuery)->select('cash_register_id')->distinct();
        $cashierIds = (clone $sessionQuery)->select('opened_by')->distinct();

        return [
            'branches' => Branch::query()->where('company_id', $companyId)->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'registers' => CashRegister::query()->where('company_id', $companyId)->whereIn('id', $registerIds)->orderBy('name')->get(['id', 'branch_id', 'name']),
            'cashiers' => User::query()->whereIn('id', $cashierIds)->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function loadDetail(CashSession $session, bool $sensitive): CashSession
    {
        $base = ['company:id,trade_name,timezone', 'branch:id,name', 'cashRegister:id,name,code', 'openedBy:id,name', 'closingStartedBy:id,name', 'closedBy:id,name'];
        if (! $sensitive) return $session->load($base);

        return $session->load(array_merge($base, [
            'differenceAuthorizedBy:id,name',
            'sales' => fn ($query) => $query->where('status', Sale::STATUS_COMPLETED)->with(['user:id,name', 'payments' => fn ($payments) =>
                $payments->where('status', SalePayment::STATUS_COMPLETED)->with('paymentMethod:id,name,code')]),
            'movements.createdBy:id,name',
            'countDetails' => fn ($query) => $query->closing()->with(['cashDenomination:id,label,sort_order', 'countedBy:id,name']),
            'paymentReconciliations.reconciledBy:id,name',
            'events.user:id,name',
            'mailNotifications' => fn ($query) => $query->orderBy('notification_type'),
        ]));
    }
}
