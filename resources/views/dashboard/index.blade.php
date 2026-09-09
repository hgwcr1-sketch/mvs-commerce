@extends('layouts.app')

@section('title', 'Dashboard')

@section('description', 'Resumen general del sistema')

@section('content')

<div class="space-y-8">
    @if(session('warning'))<div role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">{{ session('warning') }}</div>@endif
    <section class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="text-xl font-semibold">Resumen administrativo</h2><p class="text-sm text-slate-600">{{ $dashboardSummary['branch'] }} · {{ $dashboardSummary['from'] }} – {{ $dashboardSummary['to'] }}</p></div>
            <nav aria-label="Período del dashboard" class="flex gap-2">
                @foreach(['today'=>'Hoy','week'=>'Semana','month'=>'Mes'] as $period=>$label)
                    <a href="{{ route('dashboard', ['period'=>$period, 'branch_id'=>request('branch_id', 'all')]) }}" @if($dashboardSummary['period']===$period) aria-current="page" @endif class="inline-flex min-h-11 items-center rounded-xl border px-4 py-2 {{ $dashboardSummary['period']===$period ? 'border-amber-500 bg-amber-100 text-amber-900' : 'border-slate-300 bg-white' }}">{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        <form method="GET" action="{{ route('dashboard') }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <input type="hidden" name="period" value="{{ $dashboardSummary['period'] }}">
            <div class="min-w-0">
                <label for="dashboard-branch" class="block text-sm font-medium">Sucursal para consulta</label>
                <select id="dashboard-branch" name="branch_id" class="min-h-11 w-full rounded-lg border-slate-300 sm:max-w-xs">
                    <option value="all">Todas las sucursales</option>
                    @foreach(\App\Models\Company::findOrFail(session('active_company_id'))->branches()->where('is_active', true)->orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="min-h-11 rounded-lg border border-slate-300 px-4 py-2">Consultar</button>
        </form>
        <p class="text-sm text-slate-500">Este filtro solo cambia la consulta del Dashboard. La sucursal operativa se selecciona en POS o Caja.</p>
        <p class="text-sm text-slate-500">Ventas emitidas en {{ $dashboardSummary['currency'] }}, incluidos históricos. Excluye anuladas y borradores; los importes son anteriores a devoluciones. Semana de lunes a domingo. Las alertas de cuentas y apartados muestran su estado actual.</p>
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
        <div class="rounded-xl border border-slate-200 bg-white">
            <h3 class="p-4 font-semibold">Últimas ventas del período</h3>
            <div class="overflow-x-auto"><table class="min-w-full text-sm">
                <thead class="bg-slate-50"><tr><th class="p-3 text-left">Venta</th><th class="hidden p-3 text-left md:table-cell">Sucursal</th><th class="hidden p-3 text-left md:table-cell">Cliente</th><th class="p-3 text-right">Total</th></tr></thead>
                <tbody>@forelse($dashboardSummary['recent_sales'] as $sale)
                    <tr class="border-t"><td class="p-3">{{ $sale->sale_number }}<small class="block text-slate-500">{{ $sale->completed_at->timezone($dashboardSummary['timezone'])->format('d/m/Y H:i') }}</small></td><td class="hidden p-3 md:table-cell">{{ $sale->branch?->name }}</td><td class="hidden p-3 md:table-cell">{{ $sale->customer?->name ?? 'Consumidor final' }}</td><td class="p-3 text-right">{{ number_format($sale->total,2,',','.') }}</td></tr>
                @empty<tr><td colspan="4" class="p-6 text-center text-slate-500">No hay ventas en este período.</td></tr>@endforelse</tbody>
            </table></div>
        </div>
    </section>
    @can('cuentas_pagar.ver')
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-lg font-semibold text-slate-800">Cuentas por pagar</h2><p class="text-sm text-slate-500">Resumen de obligaciones con proveedores.</p></div><a href="{{route('cuentas-por-pagar.index')}}" class="text-sm font-semibold text-amber-700">Ver cuentas por pagar</a></div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <a href="{{route('cuentas-por-pagar.index')}}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-sm font-semibold text-slate-700">CxP pendientes</p><p class="mt-1 text-xl font-bold">₡{{number_format($payableSummary['pending_amount'],0,',','.')}}</p><p class="text-sm text-slate-500">{{$payableSummary['pending_count']}} cuentas</p></a>
                <a href="{{route('cuentas-por-pagar.index',['status'=>'overdue'])}}" class="rounded-xl border border-red-200 bg-white p-4 shadow-sm"><p class="text-sm font-semibold text-red-700">CxP vencidas</p><p class="mt-1 text-xl font-bold">₡{{number_format($payableSummary['overdue_amount'],0,',','.')}}</p><p class="text-sm text-slate-500">{{$payableSummary['overdue_count']}} cuentas</p></a>
                <a href="{{route('cuentas-por-pagar.index',['due_from'=>today()->toDateString(),'due_to'=>today()->addDays($payableSummary['alert_days'])->toDateString()])}}" class="rounded-xl border border-amber-200 bg-white p-4 shadow-sm"><p class="text-sm font-semibold text-amber-700">CxP próximas a vencer</p><p class="mt-1 text-xl font-bold">₡{{number_format($payableSummary['upcoming_amount'],0,',','.')}}</p><p class="text-sm text-slate-500">{{$payableSummary['upcoming_count']}} cuentas · Próximos {{$payableSummary['alert_days']}} días</p></a>
            </div>
        </section>
    @endcan
    @can('apartados.ver')<section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><div><h2 class="font-semibold text-slate-800">Apartados</h2><p class="text-sm text-slate-500">{{ $layawaySummary['active_count'] }} activos · Pendiente ₡{{ number_format($layawaySummary['pending_amount'],0,',','.') }}</p></div><a href="{{ route('apartados.index') }}" class="text-sm font-semibold text-amber-700">Ver Apartados</a></div><div class="mt-3 grid grid-cols-2 gap-3 text-sm md:grid-cols-4"><div>Activos<br><strong>{{ $layawaySummary['active_count'] }}</strong></div><div>Monto pendiente<br><strong>₡{{ number_format($layawaySummary['pending_amount'],0,',','.') }}</strong></div><div>Próximos a vencer<br><strong>{{ $layawaySummary['upcoming_count'] }}</strong></div><div>Vencidos<br><strong>{{ $layawaySummary['expired_count'] }}</strong></div></div></section>@endcan
    @can('cuentas_cobrar.ver')
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-lg font-semibold text-slate-800">Créditos</h2><p class="text-sm text-slate-500">Resumen de cuentas por cobrar.</p></div><a href="{{ route('cuentas-por-cobrar.index') }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800">Ver cuentas por cobrar</a></div>
            <div class="grid gap-4 md:grid-cols-2">
                <a href="{{ route('cuentas-por-cobrar.index', ['due' => 'overdue']) }}" class="rounded-xl border border-red-200 bg-white p-4 shadow-sm hover:bg-red-50/40"><p class="text-sm font-semibold text-red-700">Créditos vencidos</p><p class="mt-1 text-xl font-bold text-slate-900">₡{{ number_format($creditSummary['overdue_amount'], 0, ',', '.') }}</p><p class="text-sm text-slate-500">{{ $creditSummary['overdue_count'] }} cuentas · Ver vencidas</p></a>
                <a href="{{ route('cuentas-por-cobrar.index', ['due' => 'upcoming']) }}" class="rounded-xl border border-amber-200 bg-white p-4 shadow-sm hover:bg-amber-50/40"><p class="text-sm font-semibold text-amber-700">Próximos a vencer</p><p class="mt-1 text-xl font-bold text-slate-900">₡{{ number_format($creditSummary['upcoming_amount'], 0, ',', '.') }}</p><p class="text-sm text-slate-500">{{ $creditSummary['upcoming_count'] }} cuentas · Próximos {{ $creditSummary['alert_days'] }} días</p></a>
            </div>
        </section>
    @endcan

</div>
@endsection
