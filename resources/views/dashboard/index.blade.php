@extends('layouts.app')

@section('title', 'Dashboard')

@section('description', 'Resumen general del sistema')

@section('content')

<div class="space-y-8">
    @if(session('warning'))<div role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">{{ session('warning') }}</div>@endif
    <section class="space-y-4" x-data="{ customOpen: {{ $dashboardSummary['period'] === 'custom' ? 'true' : 'false' }} }">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="text-xl font-semibold">Resumen administrativo</h2><p class="text-sm text-slate-600">{{ $dashboardSummary['branch'] }} · {{ $dashboardSummary['from'] }} – {{ $dashboardSummary['to'] }}</p></div>
            <nav aria-label="Período del dashboard" class="flex flex-wrap gap-2">
                @foreach(['today'=>'Hoy','week'=>'Semana','month'=>'Mes','year'=>'Año'] as $period=>$label)
                    <a href="{{ route('dashboard', ['period'=>$period, 'branch_id'=>request('branch_id', 'all')]) }}" @if($dashboardSummary['period']===$period) aria-current="page" @endif class="inline-flex min-h-11 items-center rounded-xl border px-4 py-2 {{ $dashboardSummary['period']===$period ? 'border-amber-500 bg-amber-100 text-amber-900' : 'border-slate-300 bg-white' }}">{{ $label }}</a>
                @endforeach
                <button type="button" @click="customOpen = !customOpen" :aria-current="customOpen ? 'page' : null" :class="customOpen ? 'border-amber-500 bg-amber-100 text-amber-900' : 'border-slate-300 bg-white'" class="inline-flex min-h-11 items-center rounded-xl border px-4 py-2">Personalizado</button>
            </nav>
        </div>
        <form method="GET" action="{{ route('dashboard') }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <input type="hidden" name="period" :value="customOpen ? 'custom' : '{{ $dashboardSummary['period'] }}'">
            <div class="min-w-0">
                <label for="dashboard-branch" class="block text-sm font-medium">Sucursal para consulta</label>
                <select id="dashboard-branch" name="branch_id" class="min-h-11 w-full rounded-lg border-slate-300 sm:max-w-xs">
                    <option value="all">Todas las sucursales</option>
                    @foreach(\App\Models\Company::findOrFail(session('active_company_id'))->branches()->where('is_active', true)->orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="customOpen" class="contents">
                <div class="min-w-0">
                    <label for="date_from" class="block text-sm font-medium">Desde</label>
                    <input type="date" id="date_from" name="date_from" value="{{ old('date_from', $dashboardSummary['date_from']) }}" class="min-h-11 w-full rounded-lg border-slate-300 sm:max-w-xs">
                </div>
                <div class="min-w-0">
                    <label for="date_to" class="block text-sm font-medium">Hasta</label>
                    <input type="date" id="date_to" name="date_to" value="{{ old('date_to', $dashboardSummary['date_to']) }}" class="min-h-11 w-full rounded-lg border-slate-300 sm:max-w-xs">
                </div>
            </div>
            <button class="min-h-11 rounded-lg border border-slate-300 px-4 py-2">Consultar</button>
        </form>
        @if($errors->has('date_from') || $errors->has('date_to') || $errors->has('period'))
            <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                {{ $errors->first('period') ?? $errors->first('date_from') ?? $errors->first('date_to') }}
            </div>
        @endif
        <p class="text-sm text-slate-500">Este filtro solo cambia la consulta del Dashboard. La sucursal operativa se selecciona en POS o Caja.</p>
        <p class="text-sm text-slate-500">Ventas emitidas en {{ $dashboardSummary['currency'] }}, incluidos históricos. Excluye anuladas y borradores; los importes son anteriores a devoluciones. Las notas de crédito se muestran en movimientos y no se suman al importe de ventas. Las alertas de cuentas y apartados muestran su estado actual.</p>
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach(['Ventas emitidas'=>$dashboardSummary['sales_count'], 'Importe de ventas'=>number_format($dashboardSummary['sales_total'],2,',','.').' '.$dashboardSummary['currency'], 'Promedio por venta'=>number_format($dashboardSummary['average_sale'],2,',','.').' '.$dashboardSummary['currency']] as $label=>$value)
                <div class="min-w-0 rounded-xl border border-slate-200 bg-white p-4"><p class="text-sm text-slate-600">{{ $label }}</p><strong class="mt-2 block break-words text-2xl">{{ $value }}</strong></div>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-3">
            @can('pos.acceder')<a href="{{ route('pos.index') }}" class="inline-flex min-h-11 items-center rounded-xl bg-amber-500 px-4 py-3">Nueva venta</a>@endcan
            @can('caja.ver')<a href="{{ route('cash.history.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 py-3">Historial de caja</a>@endcan
            @can('caja.abrir')<a href="{{ route('cash.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 py-3">Ir a Caja</a>@endcan
        </div>
@php
    $ccCompany = \App\Models\Company::find(session('active_company_id'));
    $ccIsAdmin = $ccCompany && auth()->user()->hasPermission('dashboard.admin', $ccCompany);
@endphp
@if($ccIsAdmin)
        <a href="{{ route('control-center.index') }}" class="group block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-amber-300 hover:shadow-md sm:p-6">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-bold text-slate-800">Centro de Control Inteligente</h3>
                    <p class="mt-1 text-sm text-slate-500">Compras, traslados y clientes que requieren atención.</p>
                </div>
                <span class="mt-1 text-sm font-semibold text-amber-700 group-hover:text-amber-800 shrink-0">Abrir →</span>
            </div>
        </a>
@endif
        <div class="rounded-xl border border-slate-200 bg-white">
            <h3 class="p-4 font-semibold">Movimientos del período</h3>
            <div class="overflow-x-auto"><table class="min-w-full text-sm">
                <thead class="bg-slate-50"><tr><th class="p-3 text-left">Tipo</th><th class="p-3 text-left">Referencia</th><th class="hidden p-3 text-left md:table-cell">Sucursal</th><th class="hidden p-3 text-left md:table-cell">Cliente</th><th class="p-3 text-right">Monto</th></tr></thead>
                <tbody>@forelse($dashboardSummary['movements'] as $movement)
                    <tr class="border-t">
                        <td class="p-3"><span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $movement['type'] === 'sale' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">{{ $movement['type_label'] }}</span></td>
                        <td class="p-3">{{ $movement['reference'] }}<small class="block text-slate-500">{{ $movement['occurred_at']->format('d/m/Y H:i') }}</small><small class="block text-slate-400">{{ $movement['status_label'] }}</small></td>
                        <td class="hidden p-3 md:table-cell">{{ $movement['branch'] }}</td>
                        <td class="hidden p-3 md:table-cell">{{ $movement['customer'] }}</td>
                        <td class="p-3 text-right">{{ number_format((float) $movement['amount'], 2, ',', '.') }}</td>
                    </tr>
                @empty<tr><td colspan="5" class="p-6 text-center text-slate-500">No hay movimientos en este período.</td></tr>@endforelse</tbody>
            </table></div>
        </div>
    </section>
    <div class="grid gap-4 md:grid-cols-3">
        @can('cuentas_pagar.ver')
            <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="font-semibold text-slate-800">Cuentas por pagar</h2><p class="text-sm text-slate-500">Obligaciones con proveedores.</p></div><a href="{{route('cuentas-por-pagar.index')}}" class="text-sm font-semibold text-amber-700">Ver</a></div>
                <div class="grid gap-2 text-sm">
                    <a href="{{route('cuentas-por-pagar.index')}}" class="block rounded-lg border border-slate-200 bg-white p-3"><p class="font-semibold text-slate-700">CxP pendientes</p><p class="font-bold">₡{{number_format($payableSummary['pending_amount'],0,',','.')}} · {{$payableSummary['pending_count']}} cuentas</p></a>
                    <a href="{{route('cuentas-por-pagar.index',['status'=>'overdue'])}}" class="block rounded-lg border border-red-200 bg-white p-3"><p class="font-semibold text-red-700">CxP vencidas</p><p class="font-bold">₡{{number_format($payableSummary['overdue_amount'],0,',','.')}} · {{$payableSummary['overdue_count']}} cuentas</p></a>
                    <a href="{{route('cuentas-por-pagar.index',['due_from'=>today()->toDateString(),'due_to'=>today()->addDays($payableSummary['alert_days'])->toDateString()])}}" class="block rounded-lg border border-amber-200 bg-white p-3"><p class="font-semibold text-amber-700">CxP próximas a vencer</p><p class="font-bold">₡{{number_format($payableSummary['upcoming_amount'],0,',','.')}} · {{$payableSummary['upcoming_count']}} cuentas · Próximos {{$payableSummary['alert_days']}} días</p></a>
                </div>
            </section>
        @endcan
        @can('cuentas_cobrar.ver')
            <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="font-semibold text-slate-800">Cuentas por cobrar</h2><p class="text-sm text-slate-500">Resumen de créditos.</p></div><a href="{{ route('cuentas-por-cobrar.index') }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800">Ver</a></div>
                <div class="grid gap-2 text-sm">
                    <a href="{{ route('cuentas-por-cobrar.index', ['due' => 'overdue']) }}" class="block rounded-lg border border-red-200 bg-white p-3"><p class="font-semibold text-red-700">Créditos vencidos</p><p class="font-bold text-slate-900">₡{{ number_format($creditSummary['overdue_amount'], 0, ',', '.') }} · {{ $creditSummary['overdue_count'] }} cuentas</p></a>
                    <a href="{{ route('cuentas-por-cobrar.index', ['due' => 'upcoming']) }}" class="block rounded-lg border border-amber-200 bg-white p-3"><p class="font-semibold text-amber-700">Próximos a vencer</p><p class="font-bold text-slate-900">₡{{ number_format($creditSummary['upcoming_amount'], 0, ',', '.') }} · {{ $creditSummary['upcoming_count'] }} cuentas · Próximos {{ $creditSummary['alert_days'] }} días</p></a>
                </div>
            </section>
        @endcan
        @can('apartados.ver')
            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between"><div><h2 class="font-semibold text-slate-800">Apartados</h2><p class="text-sm text-slate-500">{{ $layawaySummary['active_count'] }} activos · Pendiente ₡{{ number_format($layawaySummary['pending_amount'],0,',','.') }}</p></div><a href="{{ route('apartados.index') }}" class="text-sm font-semibold text-amber-700">Ver</a></div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-2">Activos<br><strong>{{ $layawaySummary['active_count'] }}</strong></div>
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-2">Monto pendiente<br><strong>₡{{ number_format($layawaySummary['pending_amount'],0,',','.') }}</strong></div>
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-2">Próximos a vencer<br><strong>{{ $layawaySummary['upcoming_count'] }}</strong></div>
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-2">Vencidos<br><strong>{{ $layawaySummary['expired_count'] }}</strong></div>
                </div>
            </section>
        @endcan
    </div>

</div>
@endsection
