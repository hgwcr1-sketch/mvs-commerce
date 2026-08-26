@extends('layouts.app')

@section('title', $definition['label'].' — Reportes')
@section('description', $definition['description'])

@section('content')
<div class="mx-auto max-w-7xl space-y-5" data-essential-report="{{ $category }}">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos · Reportes</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">{{ $definition['label'] }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ $definition['description'] }}</p>
        </div>
        <a href="{{ route('data-center.reports') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver a Reportes</a>
    </header>

    <form method="GET" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="text-sm font-semibold text-slate-700">Sucursal
                <select name="branch_id" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2">
                    @foreach($options['branches'] as $option)<option value="{{ $option->id }}" @selected($filters['branch_id'] === $option->id)>{{ $option->name }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Desde<input type="date" name="from" value="{{ $filters['from'] }}" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
            <label class="text-sm font-semibold text-slate-700">Hasta<input type="date" name="to" value="{{ $filters['to'] }}" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
            <label class="text-sm font-semibold text-slate-700">Producto
                <select name="product_id" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Todos</option>@foreach($options['products'] as $option)<option value="{{ $option->id }}" @selected($filters['product_id'] === $option->id)>{{ $option->name }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Cliente
                <select name="customer_id" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Todos</option>@foreach($options['customers'] as $option)<option value="{{ $option->id }}" @selected($filters['customer_id'] === $option->id)>{{ $option->name }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Proveedor
                <select name="supplier_id" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Todos</option>@foreach($options['suppliers'] as $option)<option value="{{ $option->id }}" @selected($filters['supplier_id'] === $option->id)>{{ $option->name }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Vendedor / usuario
                <select name="user_id" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Todos</option>@foreach($options['users'] as $option)<option value="{{ $option->id }}" @selected($filters['user_id'] === $option->id)>{{ $option->name }}</option>@endforeach</select>
            </label>
            <div class="flex items-end"><button class="min-h-11 w-full rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Aplicar filtros</button></div>
        </div>
    </form>

    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" aria-label="Indicadores">
        @foreach($report['metrics'] as $metric)
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $metric['label'] }}</p><p class="mt-2 text-xl font-bold text-slate-900">{{ $metric['value'] }}</p></article>
        @endforeach
    </section>

    @if($exportDatasets->isNotEmpty())
        <section class="flex flex-col gap-2 rounded-2xl border border-blue-200 bg-blue-50 p-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-blue-900">Descargue el conjunto base verificable de D09 para conciliación.</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach($exportDatasets as $dataset => $label)
                    <a href="{{ route('data-center.exports.download', [$dataset, 'xlsx']) }}?branch_id={{ $filters['branch_id'] }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white">{{ $label }} XLSX</a>
                    <a href="{{ route('data-center.exports.download', [$dataset, 'csv']) }}?branch_id={{ $filters['branch_id'] }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-800">{{ $label }} CSV</a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        @foreach($report['sections'] as $section)
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-4 py-3 text-base font-bold text-slate-800">{{ $section['title'] }}</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50"><tr>@foreach($section['headers'] as $header)<th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">{{ $header }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($section['rows'] as $row)<tr>@foreach($row as $cell)<td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($section['headers']) }}" class="px-4 py-8 text-center text-slate-500">Sin datos para los filtros seleccionados.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    </div>
</div>
@endsection
