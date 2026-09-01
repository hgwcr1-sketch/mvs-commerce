@extends('layouts.app')

@section('title', 'Revisar productos')
@section('description', 'Vista previa de migración de productos')

@section('content')
@php
    $validCount = collect($rows)->where('valid', true)->count();
    $errorCount = collect($rows)->where('valid', false)->count();
@endphp
<div class="mx-auto max-w-7xl space-y-6" data-product-import-preview>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">P33 · Vista previa</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Revisar productos</h1>
            <p class="mt-2 text-sm text-slate-600">Todavía no se ha creado ningún producto ni se ha modificado inventario.</p>
        </div>
        <a href="{{ route('importaciones.productos') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Cargar otro archivo</a>
    </header>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Filas <strong class="block text-2xl text-slate-900">{{ count($rows) }}</strong></div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">Listas <strong class="block text-2xl">{{ $validCount }}</strong></div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Con errores <strong class="block text-2xl">{{ $errorCount }}</strong></div>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-[1100px] w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-600">
                    <tr><th class="px-4 py-3">Fila</th><th class="px-4 py-3">Código</th><th class="px-4 py-3">Producto</th><th class="px-4 py-3">Catálogo</th><th class="px-4 py-3">Barras</th><th class="px-4 py-3 text-right">Costo / precio</th><th class="px-4 py-3">Estado</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($rows as $row)
                        <tr class="align-top {{ $row['valid'] ? '' : 'bg-red-50/50' }}">
                            <td class="px-4 py-3 font-semibold text-slate-700">{{ $row['row_number'] }}</td>
                            <td class="px-4 py-3 font-mono text-slate-700">{{ $row['internal_code'] ?: '—' }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $row['name'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $row['category_name'] ?: '—' }}
                                @if($row['category_will_create'] ?? false)<span class="text-xs font-semibold text-amber-700">(se creará al confirmar)</span>@endif
                                · {{ $row['unit_name'] ?: '—' }}
                                @if($row['unit_will_create'] ?? false)<span class="text-xs font-semibold text-amber-700">(se creará al confirmar)</span>@endif
                                <br>{{ $row['brand_name'] ?: 'Sin marca' }}
                                @if($row['brand_will_create'] ?? false)<span class="text-xs font-semibold text-amber-700">(se creará al confirmar)</span>@endif
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ implode(' · ', $row['barcodes']) ?: '—' }}</td>
                            <td class="px-4 py-3 text-right text-slate-700">{{ $row['cost'] ?? '—' }} / {{ $row['sale_price'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if($row['valid'])
                                    <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Lista</span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Error</span>
                                    <ul class="mt-2 space-y-1 text-xs text-red-700">
                                        @foreach($row['errors'] as $error)<li><strong>{{ $error['field'] }}:</strong> {{ $error['message'] }}</li>@endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-600">La confirmación revalida todo y usa una sola transacción. No escribe `branch_product` ni Kardex.</p>
        @if($errorCount === 0 && count($rows) > 0)
            <form action="{{ route('importaciones.productos.import') }}" method="POST" onsubmit="return confirm('¿Confirmar la importación de {{ count($rows) }} productos?');">
                @csrf
                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white sm:w-auto">Confirmar importación</button>
            </form>
        @else
            <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">Corrija todas las filas antes de confirmar.</p>
        @endif
    </div>
</div>
@endsection
