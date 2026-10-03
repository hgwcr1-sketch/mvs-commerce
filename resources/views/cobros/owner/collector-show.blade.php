@extends('cobros.layout')

@section('title', 'Cartera de '.$collector->name.' — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.owner.collectors') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Cobradores</a>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-slate-900">{{ $collector->name }}</h1>
                <p class="mt-1 break-words text-sm text-slate-500">{{ $collector->email }}</p>
                <span class="mt-2 inline-block rounded-full {{ $collector->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }} px-3 py-1 text-xs font-bold">
                    {{ $collector->is_active ? 'Activo' : 'Inactivo' }}
                </span>
            </div>
            <div class="text-right">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo pendiente total</span>
                <span class="text-2xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totalBalance, 'currency' => $company->currency])</span>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Clientes con cartera</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">{{ $customers->total() }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Cobrado en {{ $monthLabel }}</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $collected, 'currency' => $company->currency])</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3 col-span-2 sm:col-span-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Recibos en {{ $monthLabel }}</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">{{ $receiptsCount }}</dd>
            </div>
        </dl>
    </div>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Operación del mes">
        <h2 class="text-lg font-semibold text-slate-900">Operación de {{ $monthLabel }}</h2>
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Visitas hoy</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">{{ $visitsTodayTotal }}</dd>
                <dd class="text-xs text-slate-500">{{ $visitsTodayPending }} pendientes</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Visitas del mes</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">{{ $visitsMonth }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Promesas de pago</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">{{ $promisesMonth }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Clientes nuevos</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">{{ $newCustomersMonth }}</dd>
            </div>
        </dl>

        <h3 class="mt-5 text-sm font-semibold uppercase tracking-wide text-slate-500">Medios de pago del mes</h3>
        <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach(['cash' => 'Efectivo', 'sinpe' => 'SINPE', 'card' => 'Datáfono', 'bank_transfer' => 'Depósito', 'other' => 'Otros'] as $bucket => $label)
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                    <dd class="mt-1 text-base font-bold text-slate-900">@include('cobros.components.money', ['amount' => $methodTotals[$bucket], 'currency' => $company->currency])</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Cierres diarios">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-slate-900">Cierres diarios</h2>
            <span class="text-sm text-slate-500">Últimos {{ $closings->count() }}</span>
        </div>

        @forelse($closings as $entry)
            @php($closing = $entry['closing'])
            @php($diff = $entry['differences'])
            <article class="mt-3 rounded-xl border border-slate-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">{{ $closing->business_date->format('d/m/Y') }}</h3>
                    <span class="rounded-full {{ $diff['has_differences'] ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }} px-3 py-1 text-xs font-bold">
                        {{ $diff['has_differences'] ? 'Con diferencias' : 'Conforme' }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-slate-500">
                    Enviado {{ $closing->submitted_at?->format('d/m/Y H:i') }} por {{ $closing->collector?->name }}
                    @if($closing->reviewed_at) · Revisado {{ $closing->reviewed_at->format('d/m/Y H:i') }} por {{ $closing->reviewedBy?->name }} @endif
                </p>

                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Declarado</dt>
                        <dd class="mt-1 font-bold text-slate-900">@include('cobros.components.money', ['amount' => $diff['total']['declared'], 'currency' => $company->currency])</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Sistema</dt>
                        <dd class="mt-1 font-bold text-slate-900">@include('cobros.components.money', ['amount' => $diff['total']['computed'], 'currency' => $company->currency])</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Diferencia</dt>
                        <dd class="mt-1 font-bold {{ $diff['total']['difference'] !== '0.0000' ? 'text-amber-700' : 'text-slate-600' }}">@include('cobros.components.money', ['amount' => $diff['total']['difference'], 'currency' => $company->currency])</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pagos</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ $closing->payments_count }}</dd>
                    </div>
                </dl>

                @if($closing->notes)
                    <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700"><strong>Notas:</strong> {{ $closing->notes }}</p>
                @endif
                @if($closing->review_notes)
                    <p class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><strong>Observaciones:</strong> {{ $closing->review_notes }}</p>
                @endif

                @unless($closing->reviewed_at)
                    <form method="POST" action="{{ route('cobros.owner.closings.review', ['closing' => $closing]) }}" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                        @csrf
                        <div class="flex-1">
                            <label for="review-{{ $closing->id }}" class="block text-sm font-semibold text-slate-700">Revisar cierre: conformidad o diferencias</label>
                            <input id="review-{{ $closing->id }}" name="review_notes" maxlength="500" required
                                   placeholder="Ej.: conforme / diferencia de 50.00 en efectivo verificada"
                                   class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base">
                        </div>
                        <button type="submit" class="min-h-[44px] rounded-xl bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover">Registrar revisión</button>
                    </form>
                @endunless

                @if($closing->evidence_deposit_path || $closing->evidence_dataphone_path)
                    <div class="mt-3 flex flex-wrap gap-3 text-sm font-semibold">
                        @if($closing->evidence_deposit_path)
                            <a class="text-amber-700 underline min-h-[44px] inline-flex items-center"
                               href="{{ route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'deposito']) }}" target="_blank" rel="noopener">Comprobante de depósito</a>
                        @endif
                        @if($closing->evidence_dataphone_path)
                            <a class="text-amber-700 underline min-h-[44px] inline-flex items-center"
                               href="{{ route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'dataphone']) }}" target="_blank" rel="noopener">Cierre de datáfono</a>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <div class="mt-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                Este cobrador aún no ha enviado cierres diarios.
            </div>
        @endforelse
    </section>

    <form method="GET" action="{{ route('cobros.owner.collectors.show', $collector) }}" role="search" class="cobros-search">
        <label for="collector-search" class="sr-only">Buscar cliente en la cartera</label>
        <input id="collector-search" type="search" name="q" value="{{ $search }}"
               placeholder="Buscar por nombre, identificación o teléfono"
               autocomplete="off" class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base">
        <button type="submit" class="mt-2 min-h-[44px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-semibold text-white hover:bg-primary-hover sm:mt-0 sm:w-auto">Buscar</button>
    </form>

    <section class="space-y-3" aria-label="Clientes de la cartera">
        <h2 class="text-lg font-semibold text-slate-900">Clientes de su cartera ({{ $customers->total() }})</h2>
        @forelse($customers as $customer)
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <h3 class="break-words text-base font-semibold text-slate-900">{{ $customer->name }}</h3>
                        <p class="mt-0.5 break-words text-sm text-slate-500">
                            {{ $customer->identification ?: 'Sin identificación' }}
                            @if($customer->phone || $customer->mobile)
                                · {{ $customer->phone ?: $customer->mobile }}
                            @endif
                        </p>
                        <p class="mt-1 text-sm text-slate-600">
                            CxC abiertas: <strong>{{ $customer->portfolio_open_count }}</strong>
                            @if($customer->portfolio_next_due)
                                · Próximo vencimiento: <strong>{{ $customer->portfolio_next_due->format('d/m/Y') }}</strong>
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo</span>
                        <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $customer->portfolio_balance, 'currency' => $company->currency])</span>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
                @if($search !== '')
                    No hay clientes en esta cartera que coincidan con la búsqueda.
                @else
                    Este cobrador no tiene clientes asignados todavía.
                @endif
            </div>
        @endforelse
    </section>

    <div class="flex flex-wrap items-center gap-2">
        {{ $customers->links() }}
    </div>
</div>
@endsection
