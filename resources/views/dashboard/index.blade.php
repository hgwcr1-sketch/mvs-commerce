@extends('layouts.app')

@section('title', 'Dashboard')

@section('description', 'Resumen general del sistema')

@section('content')

<div class="space-y-8">

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