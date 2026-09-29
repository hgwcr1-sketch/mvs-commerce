@extends('cobros.layout')

@section('title', 'Mi cartera — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Mi cartera</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $company->trade_name }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-right">
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo pendiente total</span>
            <span class="text-xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totalBalance, 'currency' => $company->currency])</span>
        </div>
    </div>

    <form method="GET" action="{{ route('cobros.collector.portfolio') }}" role="search" class="cobros-search">
        <label for="portfolio-search" class="sr-only">Buscar cliente</label>
        <input id="portfolio-search" type="search" name="q" value="{{ $search }}"
               placeholder="Buscar por nombre, identificación o teléfono"
               autocomplete="off" class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base">
        <button type="submit" class="mt-2 min-h-[44px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-semibold text-white hover:bg-primary-hover sm:mt-0 sm:w-auto">Buscar</button>
    </form>

    @if($customers->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            @if($search !== '')
                No hay clientes de su cartera que coincidan con la búsqueda.
            @else
                No tiene clientes asignados con saldo pendiente por ahora.
            @endif
        </div>
    @endif

    <ul class="space-y-3">
        @foreach($customers as $customer)
            <li class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-semibold text-slate-900">{{ $customer->name }}</h2>
                        <p class="mt-0.5 truncate text-sm text-slate-500">
                            {{ $customer->identification ?: 'Sin identificación' }}
                            @if($customer->phone || $customer->mobile)
                                · {{ $customer->phone ?: $customer->mobile }}
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo</span>
                        <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $customer->portfolio_balance, 'currency' => $company->currency])</span>
                    </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-slate-600 sm:grid-cols-3">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">CxC abiertas</dt><dd class="font-semibold">{{ $customer->portfolio_open_count }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Sucursal(es)</dt><dd class="truncate font-semibold">{{ $customer->portfolio_branches->implode(', ') ?: '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Próximo vencimiento</dt><dd class="font-semibold">{{ $customer->portfolio_next_due ? $customer->portfolio_next_due->format('d/m/Y') : '—' }}</dd></div>
                </dl>
                @if($customer->portfolio_last_payment)
                    <p class="mt-2 text-xs text-slate-500">Último pago: {{ $customer->portfolio_last_payment->paid_at->format('d/m/Y') }} · @include('cobros.components.money', ['amount' => $customer->portfolio_last_payment->amount, 'currency' => $company->currency])</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('cobros.collector.portfolio.show', $customer) }}"
                       class="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-xl border border-primary px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-primary/10">Ver cliente</a>
                    {{-- 3A: el botón solo existe cuando el Gate permite iniciar el cobro. --}}
                    @if(isset($chargeableAccounts[$customer->id]))
                        <a href="{{ route('cobros.collector.payments.create', $chargeableAccounts[$customer->id]) }}"
                           class="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-xl bg-primary px-4 py-2 text-sm font-bold text-slate-950 hover:bg-primary-hover">COBRAR</a>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    @if($customers->hasPages())
        <nav aria-label="Paginación de cartera" class="flex justify-center">{{ $customers->links() }}</nav>
    @endif
</div>
@endsection
