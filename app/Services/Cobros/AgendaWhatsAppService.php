<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\Company;
use App\Models\User;
use App\Support\Cobros\WhatsApp;

final class AgendaWhatsAppService
{
    public function __construct(private readonly CreditInstallmentService $installments, private readonly CobrosPaymentGate $gate) {}

    /** Only consumes events resolved by AgendaService, never request-supplied amounts. */
    public function url(User $collector, Company $company, array $item): ?string
    {
        $today = today($company->timezone ?: config('app.timezone'))->toDateString();
        $visit = $item['visit'];
        if ($item['date'] !== $today || ($visit && ! in_array($visit->status, ['pendiente', 'promesa_pago'], true))) {
            return null;
        }

        $accounts = AccountReceivable::query()->forCompany($company->id)
            ->where('customer_id', $item['customer']->id)
            ->when($visit?->account_receivable_id, fn ($q, $id) => $q->whereKey($id))
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])->get();
        // A visit without a specific account must not guess between several debts.
        if ($accounts->count() !== 1) {
            return null;
        }
        $account = $accounts->first();
        if (! $this->gate->canStart($collector, $company, $account)) {
            return null;
        }
        $context = $this->installments->contextFor($company, $account);
        $credit = $context['credit'] ?? null;
        $activeRoute = (int) session('active_cobros_route_id', 0);
        if (! $credit || (int) $credit->collector_user_id !== (int) $collector->id
            || ($activeRoute > 0 && (int) $credit->cobros_route_id !== $activeRoute)) {
            return null;
        }
        $installment = $context['installment'] ?? null;
        if (! $installment || $installment->due_date->toDateString() > $today
            || $installment->status === 'paid' || bccomp($context['pending'], '0', 4) <= 0
            || bccomp($context['pending'], (string) $account->balance_due, 4) > 0) {
            return null;
        }

        return WhatsApp::visitUrl($company, $item['customer'], $collector->name, $context['pending'],
            $account->currency_code, $installment->due_date->toDateString() < $today);
    }
}
