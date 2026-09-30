@extends('cobros.layout')

@section('title', 'Panel del supervisor — MVS CobrOS')

@section('content')
{{-- Fase 4A: panel operativo del supervisor, solo lectura. --}}
<div class="mx-auto max-w-4xl space-y-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Panel del supervisor</h1>
        <p class="mt-1 break-words text-sm text-slate-500">{{ $company->trade_name }} · {{ $today->format('d/m/Y') }}</p>
    </div>

    {{-- Filtro por sucursal (solo de esta empresa y de su alcance). --}}
    <form method="GET" action="{{ route('cobros.supervisor.index') }}" class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4">
        <label for="branch_id" class="block text-sm font-semibold text-slate-700">Sucursal</label>
        <div class="mt-1 flex flex-col gap-2 sm:flex-row sm:items-center">
            <select id="branch_id" name="branch_id"
                    class="min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                <option value="">Todas las sucursales</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
            <button type="submit"
                    class="min-h-[48px] w-full rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover sm:w-auto">
                Aplicar
            </button>
        </div>
    </form>

    {{-- Indicadores del día (reutiliza PortfolioSummaryService, sin cálculos nuevos). --}}
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        @foreach([
            'Saldo por cobrar' => $summary['metrics']['total_due'],
            'Vencido' => $summary['metrics']['total_overdue'],
            'Cobrado hoy' => $summary['metrics']['collected_today'],
            'Clientes con cuenta' => $summary['metrics']['customers_with_balance'],
            'Próximos a vencer' => $summary['metrics']['upcoming_count'],
            'Cuentas vencidas' => $summary['metrics']['overdue_count'],
        ] as $label => $value)
            <div class="rounded-xl border border-slate-200 bg-white p-3 text-center sm:p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 break-all text-lg font-bold text-slate-900 sm:text-xl">
                    @if(str_contains($label, 'cuenta') || str_contains($label, 'vencer') || $label === 'Cuentas vencidas')
                        {{ $value }}
                    @else
                        @include('cobros.components.money', ['amount' => $value, 'currency' => $company->currency])
                    @endif
                </p>
            </div>
        @endforeach
    </section>

    {{-- Cartera por cobrador: asignaciones vigentes y saldo real de CxC. --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Cartera por cobrador</h2>
        @empty($collectors)
            <p class="mt-3 text-sm text-slate-500">No hay cobradores registrados en esta empresa.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach($collectors as $collector)
                    <li class="rounded-xl border border-slate-200 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block break-words text-base font-bold text-slate-900">{{ $collector['name'] }}</span>
                                <span class="block text-xs {{ $collector['is_active'] ? 'text-green-700' : 'text-red-600' }}">
                                    {{ $collector['is_active'] ? 'Activo' : 'Inactivo' }}
                                    @if($collector['branches']) · {{ $collector['branches']->implode(', ') }} @endif
                                </span>
                            </span>
                            <span class="text-right text-sm text-slate-500">
                                Agenda hoy: {{ $visitsByCollector->get($collector['id'], 0) }}
                            </span>
                        </div>
                        <dl class="mt-2 grid grid-cols-3 gap-2 text-center text-sm">
                            <div>
                                <dt class="text-xs text-slate-500">Clientes asignados</dt>
                                <dd class="font-bold text-slate-900">{{ $collector['assigned_customers'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Saldo</dt>
                                <dd class="font-bold text-slate-900">@include('cobros.components.money', ['amount' => $collector['balance'], 'currency' => $company->currency])</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Cobrado hoy</dt>
                                <dd class="font-bold text-slate-900">@include('cobros.components.money', ['amount' => $collector['collected_today'], 'currency' => $company->currency])</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>
        @endempty
    </section>

    {{-- Agenda del día e incidencias. --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Agenda de hoy</h2>
        <p class="mt-1 text-sm text-slate-500">Total de gestiones programadas: {{ $visitTotal }}</p>
        <ul class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
            <li class="rounded-lg bg-slate-50 p-2 text-center"><span class="block text-xs text-slate-500">Pendientes</span><span class="font-bold text-slate-900">{{ $visitCounts->get(\App\Models\CobrosVisit::STATUS_PENDING, 0) }}</span></li>
            <li class="rounded-lg bg-slate-50 p-2 text-center"><span class="block text-xs text-slate-500">Gestionadas</span><span class="font-bold text-slate-900">{{ $visitCounts->get(\App\Models\CobrosVisit::STATUS_VISITED, 0) }}</span></li>
            <li class="rounded-lg bg-slate-50 p-2 text-center"><span class="block text-xs text-slate-500">No localizados</span><span class="font-bold text-slate-900">{{ $visitCounts->get(\App\Models\CobrosVisit::STATUS_NOT_FOUND, 0) }}</span></li>
            <li class="rounded-lg bg-slate-50 p-2 text-center"><span class="block text-xs text-slate-500">Promesas</span><span class="font-bold text-slate-900">{{ $visitCounts->get(\App\Models\CobrosVisit::STATUS_PROMISE, 0) }}</span></li>
            <li class="rounded-lg bg-slate-50 p-2 text-center"><span class="block text-xs text-slate-500">Reprogramadas</span><span class="font-bold text-slate-900">{{ $visitCounts->get(\App\Models\CobrosVisit::STATUS_RESCHEDULED, 0) }}</span></li>
        </ul>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-slate-700">No localizados hoy</h3>
                @if($notFoundToday->isEmpty())
                    <p class="mt-1 text-sm text-slate-500">Sin incidencias de este tipo hoy.</p>
                @else
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach($notFoundToday as $visit)
                            <li class="rounded-lg border border-slate-200 p-2">
                                <span class="block break-words font-semibold text-slate-900">{{ $visit->customer?->name }}</span>
                                <span class="block text-xs text-slate-500">{{ $visit->collector?->name }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-700">Promesas vencidas</h3>
                @if($overduePromises->isEmpty())
                    <p class="mt-1 text-sm text-slate-500">Sin promesas vencidas.</p>
                @else
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach($overduePromises as $visit)
                            <li class="rounded-lg border border-slate-200 p-2">
                                <span class="block break-words font-semibold text-slate-900">{{ $visit->customer?->name }}</span>
                                <span class="block text-xs text-slate-500">Pagaría el {{ $visit->promise_date?->format('d/m/Y') }} · {{ $visit->collector?->name }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </section>

    {{-- Recibos y anulaciones del día. --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Recibos y anulaciones de hoy</h2>
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl bg-slate-50 p-3 text-center">
                <p class="text-xs text-slate-500">Recibos emitidos ({{ $receiptsToday['count'] }})</p>
                <p class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $receiptsToday['total'], 'currency' => $company->currency])</p>
            </div>
            <div class="rounded-xl bg-slate-50 p-3 text-center">
                <p class="text-xs text-slate-500">Cobros anulados ({{ $reversalsToday['count'] }})</p>
                <p class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $reversalsToday['total'], 'currency' => $company->currency])</p>
            </div>
        </div>
    </section>

    {{-- Auditoría reciente + consulta completa. --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold text-slate-900">Auditoría reciente</h2>
            <a href="{{ route('cobros.supervisor.audit') }}"
               class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                Ver auditoría completa
            </a>
        </div>
        @if($recentLogs->isEmpty())
            <p class="mt-3 text-sm text-slate-500">Sin eventos de cobro registrados.</p>
        @else
            <ul class="mt-3 space-y-2 text-sm">
                @foreach($recentLogs as $log)
                    @php($data = is_array($log->metadata) ? $log->metadata : (json_decode($log->metadata ?? '[]', true) ?: []))
                    <li class="rounded-lg border border-slate-200 p-2">
                        <span class="font-semibold {{ $log->action === 'cobros.payment.annulled' ? 'text-red-700' : 'text-slate-900' }}">
                            {{ $log->action === 'cobros.payment.annulled' ? 'Cobro anulado' : 'Cobro registrado' }}
                        </span>
                        <span class="block break-all text-xs text-slate-500">
                            @isset($data['receipt_number'])Recibo {{ $data['receipt_number'] }}@endisset
                            @isset($data['reversal_number']) · Reverso {{ $data['reversal_number'] }}@endisset
                            · {{ $actorNames[$log->actor_id] ?? 'Usuario del sistema' }}
                            · {{ \Illuminate\Support\Carbon::parse($log->occurred_at)->timezone($company->timezone)->format('d/m/Y H:i') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="text-center text-xs text-slate-500">
        Panel de solo lectura: no registra cobros, anulaciones ni cambios de cartera.
    </p>
</div>
@endsection
