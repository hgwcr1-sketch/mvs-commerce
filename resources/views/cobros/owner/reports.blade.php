@extends('cobros.layout')

@section('title', 'Resultados del período — MVS CobrOS')

@section('content')
{{-- Fase 4B: panel avanzado del dueño, solo lectura. --}}
<div class="mx-auto max-w-4xl space-y-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Resultados del período</h1>
        <p class="mt-1 break-words text-sm text-slate-500">
            {{ $company->trade_name }} · del {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d/m/Y') }}
            al {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d/m/Y') }}
        </p>
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4" role="alert">
            <ul class="list-inside list-disc space-y-1 text-sm font-semibold text-red-700">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Filtros: sucursal + período (solo lectura). --}}
    <form method="GET" action="{{ route('cobros.owner.reports') }}" class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
                <label for="branch_id" class="block text-sm font-semibold text-slate-700">Sucursal</label>
                <select id="branch_id" name="branch_id"
                        class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="from" class="block text-sm font-semibold text-slate-700">Desde</label>
                <input id="from" name="from" type="date" value="{{ old('from', $period['from']) }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
            <div>
                <label for="to" class="block text-sm font-semibold text-slate-700">Hasta</label>
                <input id="to" name="to" type="date" value="{{ old('to', $period['to']) }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
        </div>
        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
            <button type="submit"
                    class="min-h-[48px] w-full rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover sm:w-auto">
                Aplicar
            </button>
            <a href="{{ route('cobros.owner.reports') }}"
               class="inline-flex min-h-[48px] w-full items-center justify-center rounded-xl border border-slate-300 px-4 py-3 text-base font-semibold text-slate-800 hover:bg-slate-50 sm:w-auto">
                Periodo actual
            </a>
            <a href="{{ route('cobros.owner.audit') }}"
               class="inline-flex min-h-[48px] w-full items-center justify-center rounded-xl border border-slate-300 px-4 py-3 text-base font-semibold text-slate-800 hover:bg-slate-50 sm:w-auto">
                Auditoría
            </a>
        </div>
    </form>

    {{-- Indicadores del período. --}}
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-3 text-center sm:p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Cobrado en el período</p>
            <p class="mt-1 break-all text-lg font-bold text-slate-900 sm:text-xl">@include('cobros.components.money', ['amount' => $totals['collected'], 'currency' => $company->currency])</p>
            <p class="mt-1 text-xs text-slate-500">{{ $totals['payments_count'] }} pagos · {{ $totals['customers_served'] }} clientes</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 text-center sm:p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Recibos emitidos</p>
            <p class="mt-1 break-all text-lg font-bold text-slate-900 sm:text-xl">{{ $totals['receipts_count'] }}</p>
            <p class="mt-1 text-xs text-slate-500">@include('cobros.components.money', ['amount' => $totals['receipts_total'], 'currency' => $company->currency])</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 text-center sm:p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Cobros anulados</p>
            <p class="mt-1 break-all text-lg font-bold text-slate-900 sm:text-xl">{{ $totals['reversals_count'] }}</p>
            <p class="mt-1 text-xs text-slate-500">@include('cobros.components.money', ['amount' => $totals['reversals_total'], 'currency' => $company->currency])</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 text-center sm:p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Eventos de auditoría</p>
            <p class="mt-1 break-all text-lg font-bold text-slate-900 sm:text-xl">{{ $totals['audit_events'] }}</p>
            <p class="mt-1 text-xs text-slate-500">cobros y anulaciones</p>
        </div>
    </section>

    {{-- Custodia pendiente: dinero cobrado que todavía no entra a caja. --}}
    <section class="rounded-xl border border-primary/60 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Custodia pendiente</h2>
        <p class="mt-1 break-all text-2xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $custody['net'], 'currency' => $company->currency])</p>
        <p class="mt-1 text-sm text-slate-500">
            {{ $custody['payments_count'] }} cobros en custodia ·
            bruto @include('cobros.components.money', ['amount' => $custody['gross'], 'currency' => $company->currency]) ·
            anulado @include('cobros.components.money', ['amount' => $custody['reversed'], 'currency' => $company->currency])
        </p>
        <p class="mt-2 text-xs text-slate-500">
            Efectivo en manos de los cobradores que aún no se ha ingresado a una sesión de caja.
        </p>
    </section>

    {{-- Ranking de cobradores. --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Resultados por cobrador</h2>
        @empty($collectors)
            <p class="mt-3 text-sm text-slate-500">No hay cobradores registrados en esta empresa.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <th class="py-2 pr-3">Cobrador</th>
                            <th class="py-2 pr-3 text-right">Cobrado</th>
                            <th class="py-2 pr-3 text-right">Anulado</th>
                            <th class="py-2 pr-3 text-right">Saldo en cartera</th>
                            <th class="py-2 pr-3 text-right">Recibos</th>
                            <th class="py-2 text-right">Clientes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($collectors as $collector)
                            <tr class="border-b border-slate-100">
                                <td class="py-2 pr-3">
                                    <span class="block break-words font-semibold text-slate-900">{{ $collector['name'] }}</span>
                                    <span class="block text-xs {{ $collector['is_active'] ? 'text-green-700' : 'text-red-600' }}">{{ $collector['is_active'] ? 'Activo' : 'Inactivo' }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right font-bold text-slate-900">@include('cobros.components.money', ['amount' => $collector['collected'], 'currency' => $company->currency])</td>
                                <td class="py-2 pr-3 text-right text-red-700">@include('cobros.components.money', ['amount' => $collector['annulled'], 'currency' => $company->currency])</td>
                                <td class="py-2 pr-3 text-right text-slate-900">@include('cobros.components.money', ['amount' => $collector['balance'], 'currency' => $company->currency])</td>
                                <td class="py-2 pr-3 text-right text-slate-900">{{ $collector['receipts'] }}</td>
                                <td class="py-2 text-right text-slate-900">{{ $collector['assigned_customers'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endempty
    </section>

    {{-- Antigüedad de vencimientos. --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Antigüedad de vencimientos</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @foreach($aging as $label => $bucket)
                <li class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
                    <span class="text-slate-700">{{ $label }}</span>
                    <span class="text-right">
                        <span class="font-bold text-slate-900">@include('cobros.components.money', ['amount' => $bucket['amount'], 'currency' => $company->currency])</span>
                        <span class="ml-2 text-xs text-slate-500">{{ $bucket['count'] }} cuentas</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Cartera total (consistente con el dashboard del dueño 1C). --}}
    <section class="grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-3 text-center sm:p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total por cobrar</p>
            <p class="mt-1 break-all text-lg font-bold text-slate-900 sm:text-xl">@include('cobros.components.money', ['amount' => $summary['metrics']['total_due'], 'currency' => $company->currency])</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 text-center sm:p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Vencido</p>
            <p class="mt-1 break-all text-lg font-bold text-slate-900 sm:text-xl">@include('cobros.components.money', ['amount' => $summary['metrics']['total_overdue'], 'currency' => $company->currency])</p>
        </div>
    </section>

    <p class="text-center text-xs text-slate-500">
        Panel de solo lectura: no registra cobros, anulaciones ni cambios de cartera.
    </p>
</div>
@endsection
