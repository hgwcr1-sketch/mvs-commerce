@extends('cobros.layout')

@section('title', $customer->name.' — Mi cartera — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        ← Volver a mi cartera
    </a>

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-slate-900">{{ $customer->name }}</h1>
                <p class="mt-1 break-words text-sm text-slate-500">
                    {{ $customer->identification ?: 'Sin identificación' }}
                    @if($customer->phone || $customer->mobile)
                        · {{ $customer->phone ?: $customer->mobile }}
                    @endif
                </p>
            </div>
            <div class="text-right">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo total</span>
                <span class="text-2xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totalBalance, 'currency' => $company->currency])</span>
            </div>
        </div>
    </div>

    <section aria-labelledby="cxc-open">
        <h2 id="cxc-open" class="text-lg font-semibold text-slate-900">Cuentas por cobrar abiertas ({{ $openAccounts->count() }})</h2>
        @if($openAccounts->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">No hay cuentas por cobrar abiertas para este cliente.</p>
        @endif
        <ul class="mt-3 space-y-3">
            @foreach($openAccounts as $account)
                <li class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $account->sale?->sale_number ?: ('CxC #'.$account->id) }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $account->branch?->name ?: '—' }} · Emitida {{ $account->issued_at->format('d/m/Y') }}</p>
                        </div>
                        <div class="text-right">
                            <span class="block text-xs uppercase tracking-wide text-slate-400">Saldo</span>
                            <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $account->balance_due, 'currency' => $account->currency_code])</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm {{ $account->due_date->isPast() ? 'font-semibold text-red-700' : 'text-slate-600' }}">
                        Vence {{ $account->due_date->format('d/m/Y') }}{{ $account->due_date->isPast() ? ' · vencida' : '' }}
                    </p>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="flex flex-wrap gap-2">
        <a href="{{ route('cobros.visits.customer', $customer) }}"
           class="mt-3 inline-flex min-h-[44px] flex-1 items-center justify-center rounded-xl border border-primary px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-primary/10">Agenda / historial de visitas</a>
    </div>
    <section aria-labelledby="pagos-historicos" class="mt-6">
        <h2 id="pagos-historicos" class="text-lg font-semibold text-slate-900">Pagos históricos</h2>
        @if($payments->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Sin pagos registrados con fuente fiable.</p>
        @else
            <ul class="mt-3 space-y-2">
                @foreach($payments as $payment)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
                        <span class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $payment->amount, 'currency' => $company->currency])</span>
                        <span class="text-slate-500">{{ $payment->paid_at->format('d/m/Y H:i') }} · {{ $payment->paymentMethod?->name ?: '—' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
