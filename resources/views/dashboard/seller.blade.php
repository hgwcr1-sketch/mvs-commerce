@extends('layouts.app')

@section('title', 'Mi Dashboard')

@section('content')

<div class="space-y-6">
    @can('cuentas_pagar.ver')
        <section class="space-y-3"><div class="flex items-center justify-between"><div><h2 class="text-lg font-semibold">Cuentas por pagar</h2><p class="text-sm text-slate-500">Resumen de obligaciones con proveedores.</p></div><a href="{{route('cuentas-por-pagar.index')}}" class="text-sm font-semibold text-amber-700">Ver CxP</a></div><div class="grid gap-4 sm:grid-cols-3"><a href="{{route('cuentas-por-pagar.index')}}" class="rounded-xl border bg-white p-4"><strong>CxP pendientes</strong><p>₡{{number_format($payableSummary['pending_amount'],0,',','.')}} · {{$payableSummary['pending_count']}} cuentas</p></a><a href="{{route('cuentas-por-pagar.index',['status'=>'overdue'])}}" class="rounded-xl border border-red-200 bg-white p-4"><strong class="text-red-700">CxP vencidas</strong><p>₡{{number_format($payableSummary['overdue_amount'],0,',','.')}} · {{$payableSummary['overdue_count']}} cuentas</p></a><a href="{{route('cuentas-por-pagar.index',['due_from'=>today()->toDateString(),'due_to'=>today()->addDays($payableSummary['alert_days'])->toDateString()])}}" class="rounded-xl border border-amber-200 bg-white p-4"><strong class="text-amber-700">CxP próximas a vencer</strong><p>₡{{number_format($payableSummary['upcoming_amount'],0,',','.')}} · {{$payableSummary['upcoming_count']}} cuentas</p><p class="text-xs text-slate-500">Próximos {{$payableSummary['alert_days']}} días</p></a></div></section>
    @endcan
    @can('apartados.ver')<a href="{{ route('apartados.index') }}" class="block rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><strong>Apartados</strong><p class="text-sm text-slate-500">{{ $layawaySummary['active_count'] }} activos · Monto pendiente ₡{{ number_format($layawaySummary['pending_amount'],0,',','.') }} · {{ $layawaySummary['upcoming_count'] }} próximos a vencer</p></a>@endcan
    @can('cuentas_cobrar.ver')
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-lg font-semibold text-slate-800">Créditos</h2><p class="text-sm text-slate-500">Resumen de cuentas por cobrar.</p></div><a href="{{ route('cuentas-por-cobrar.index') }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800">Ver cuentas por cobrar</a></div>
            <div class="grid gap-4 md:grid-cols-2">
                <a href="{{ route('cuentas-por-cobrar.index', ['due' => 'overdue']) }}" class="rounded-xl border border-red-200 bg-white p-4 shadow-sm hover:bg-red-50/40"><p class="text-sm font-semibold text-red-700">Créditos vencidos</p><p class="mt-1 text-xl font-bold text-slate-900">₡{{ number_format($creditSummary['overdue_amount'], 0, ',', '.') }}</p><p class="text-sm text-slate-500">{{ $creditSummary['overdue_count'] }} cuentas · Ver vencidas</p></a>
                <a href="{{ route('cuentas-por-cobrar.index', ['due' => 'upcoming']) }}" class="rounded-xl border border-amber-200 bg-white p-4 shadow-sm hover:bg-amber-50/40"><p class="text-sm font-semibold text-amber-700">Próximos a vencer</p><p class="mt-1 text-xl font-bold text-slate-900">₡{{ number_format($creditSummary['upcoming_amount'], 0, ',', '.') }}</p><p class="text-sm text-slate-500">{{ $creditSummary['upcoming_count'] }} cuentas · Próximos {{ $creditSummary['alert_days'] }} días</p></a>
            </div>
        </section>
    @endcan

    <h1 class="text-2xl font-bold text-slate-800">
        Mi Dashboard
    </h1>

    <p class="text-slate-500">
        Panel de trabajo del vendedor.
    </p>


    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">


        <x-card>

            <div class="text-sm text-slate-500">
                Mis Ventas
            </div>

            <div class="mt-2 text-3xl font-bold text-slate-800">
                0
            </div>

        </x-card>


        <x-card>

            <div class="text-sm text-slate-500">
                Clientes
            </div>

            <div class="mt-2 text-3xl font-bold text-slate-800">
                0
            </div>

        </x-card>


        <x-card>

            <div class="text-sm text-slate-500">
                Pendientes
            </div>

            <div class="mt-2 text-3xl font-bold text-slate-800">
                0
            </div>

        </x-card>


    </div>


</div>

@endsection
