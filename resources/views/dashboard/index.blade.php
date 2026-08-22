@extends('layouts.app')

@section('title', 'Dashboard')

@section('description', 'Resumen general del sistema')

@section('content')

<div class="space-y-8">
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

    <!-- Tarjetas de Resumen -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <p class="text-sm text-slate-500">
                Ventas del Día
            </p>

            <h2 class="text-3xl font-bold text-slate-800 mt-3">
                ₡0.00
            </h2>

            <p class="text-sm text-green-600 mt-2">
                Sin ventas registradas
            </p>

        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <p class="text-sm text-slate-500">
                Facturas Emitidas
            </p>

            <h2 class="text-3xl font-bold text-slate-800 mt-3">
                0
            </h2>

            <p class="text-sm text-slate-500 mt-2">
                Hoy
            </p>

        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <p class="text-sm text-slate-500">
                Productos
            </p>

            <h2 class="text-3xl font-bold text-slate-800 mt-3">
                0
            </h2>

            <p class="text-sm text-slate-500 mt-2">
                En inventario
            </p>

        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <p class="text-sm text-slate-500">
                Clientes
            </p>

            <h2 class="text-3xl font-bold text-slate-800 mt-3">
                0
            </h2>

            <p class="text-sm text-slate-500 mt-2">
                Registrados
            </p>

        </div>

    </div>

    <!-- Segunda fila -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- Ventas -->
        <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <div class="flex items-center justify-between mb-6">

                <h3 class="text-lg font-semibold text-slate-800">
                    Ventas de los últimos 7 días
                </h3>

                <span class="text-sm text-slate-500">
                    Próximamente
                </span>

            </div>

            <div class="h-72 rounded-xl border-2 border-dashed border-slate-300 flex items-center justify-center">

                <p class="text-slate-400">
                    Aquí se mostrará el gráfico de ventas.
                </p>

            </div>

        </div>

        <!-- Acciones -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <h3 class="text-lg font-semibold text-slate-800 mb-6">
                Acciones rápidas
            </h3>

            <div class="space-y-4">

                <button class="w-full rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-semibold py-3 transition">
                    Nueva Venta
                </button>

                <button class="w-full rounded-xl bg-slate-800 hover:bg-slate-900 text-white font-semibold py-3 transition">
                    Nuevo Producto
                </button>

                <button class="w-full rounded-xl bg-slate-700 hover:bg-slate-800 text-white font-semibold py-3 transition">
                    Nuevo Cliente
                </button>

                <button class="w-full rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 transition">
                    Ver Reportes
                </button>

            </div>

        </div>

    </div>

    <!-- Últimas ventas -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200">

        <div class="border-b border-slate-200 px-6 py-4">

            <h3 class="text-lg font-semibold text-slate-800">
                Últimas Ventas
            </h3>

        </div>

        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead class="bg-slate-50">

                    <tr>

                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-600">
                            Factura
                        </th>

                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-600">
                            Cliente
                        </th>

                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-600">
                            Total
                        </th>

                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-600">
                            Estado
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <tr>

                        <td colspan="4" class="px-6 py-12 text-center text-slate-400">

                            Aún no existen ventas registradas.

                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

    </div>

</div>

@endsection
