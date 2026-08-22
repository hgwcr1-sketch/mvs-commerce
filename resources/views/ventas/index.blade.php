@extends('layouts.app')

@section('title', 'Historial de ventas')

@section('description', 'Consulta de ventas registradas en la sucursal activa.')

@section('content')

<div class="space-y-6">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Historial de ventas
            </h2>

            <p class="text-sm text-slate-500">
                Consulte ventas, documentos emitidos y comprobantes de la sucursal activa.
            </p>
        </div>

        <a
            href="{{ route('pos.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
            Volver al POS
        </a>
    </div>

    <x-card>

        <form method="GET" action="{{ route('ventas.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-5">

            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700">
                    Buscar
                </label>

                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="Número o cliente"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700">
                    Documento
                </label>

                <select
                    name="document_type"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2">

                    <option value="">Todos</option>

                    <option
                        value="{{ \App\Models\Sale::DOCUMENT_ELECTRONIC_TICKET }}"
                        @selected(request('document_type') === \App\Models\Sale::DOCUMENT_ELECTRONIC_TICKET)>
                        Tiquete electrónico
                    </option>

                    <option
                        value="{{ \App\Models\Sale::DOCUMENT_ELECTRONIC_INVOICE }}"
                        @selected(request('document_type') === \App\Models\Sale::DOCUMENT_ELECTRONIC_INVOICE)>
                        Factura electrónica
                    </option>

                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700">
                    Estado
                </label>

                <select
                    name="status"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2">

                    <option value="">Todos</option>

                    <option
                        value="{{ \App\Models\Sale::STATUS_COMPLETED }}"
                        @selected(request('status') === \App\Models\Sale::STATUS_COMPLETED)>
                        Completada
                    </option>

                    <option
                        value="{{ \App\Models\Sale::STATUS_VOIDED }}"
                        @selected(request('status') === \App\Models\Sale::STATUS_VOIDED)>
                        Anulada
                    </option>

                    <option
                        value="{{ \App\Models\Sale::STATUS_PARTIALLY_RETURNED }}"
                        @selected(request('status') === \App\Models\Sale::STATUS_PARTIALLY_RETURNED)>
                        Devolución parcial
                    </option>

                    <option
                        value="{{ \App\Models\Sale::STATUS_RETURNED }}"
                        @selected(request('status') === \App\Models\Sale::STATUS_RETURNED)>
                        Devuelta
                    </option>

                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700">
                    Desde
                </label>

                <input
                    type="date"
                    name="date_from"
                    value="{{ request('date_from') }}"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700">
                    Hasta
                </label>

                <input
                    type="date"
                    name="date_to"
                    value="{{ request('date_to') }}"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>

            <div class="flex items-end gap-2 md:col-span-2 lg:col-span-5">
                <button
                    type="submit"
                    class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-black hover:bg-amber-600">
                    Filtrar
                </button>

                <a
                    href="{{ route('ventas.index') }}"
                    class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                    Limpiar
                </a>
            </div>

        </form>

    </x-card>

    <x-card>

        @if($sales->count())

            <div class="overflow-x-auto">

                <table class="min-w-full divide-y divide-slate-200">

                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Número
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Fecha
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Cliente
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Cajero
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Documento
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Condición
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Total
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-600">
                                Estado
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-600">
                                Acciones
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100 bg-white">

                        @foreach($sales as $sale)

                            <tr>

                                <td class="px-4 py-3 font-medium text-slate-800">
                                    {{ $sale->sale_number }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-600">
                                    {{ $sale->completed_at?->format('d/m/Y H:i') ?: '—' }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-700">
                                    {{ $sale->customer?->name ?: 'Consumidor Final' }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-700">
                                    {{ $sale->user?->name ?: '—' }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-700">
                                    @if($sale->document_type === \App\Models\Sale::DOCUMENT_ELECTRONIC_INVOICE)
                                        Factura electrónica
                                    @else
                                        Tiquete electrónico
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-sm font-medium text-slate-700">
                                    {{ $sale->sale_condition === \App\Models\Sale::CONDITION_CREDIT ? 'Crédito' : 'Contado' }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold text-slate-800">
                                    ₡{{ number_format((float) $sale->total, 0, ',', '.') }}
                                </td>

                                <td class="px-4 py-3 text-center">

                                    @if($sale->status === \App\Models\Sale::STATUS_COMPLETED)

                                        <span class="inline-flex rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                                            Completada
                                        </span>

                                    @elseif($sale->status === \App\Models\Sale::STATUS_VOIDED)

                                        <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                                            Anulada
                                        </span>

                                    @elseif($sale->status === \App\Models\Sale::STATUS_PARTIALLY_RETURNED)

                                        <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                                            Devolución parcial
                                        </span>

                                    @elseif($sale->status === \App\Models\Sale::STATUS_RETURNED)

                                        <span class="inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700">
                                            Devuelta
                                        </span>

                                    @else

                                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                            {{ $sale->status }}
                                        </span>

                                    @endif

                                </td>

                                <td class="px-4 py-3 text-center">

                                    <div class="flex justify-center gap-2">

                                        <a
                                            href="{{ route('ventas.show', $sale) }}"
                                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100">
                                            Ver
                                        </a>

                                        <a
                                            href="{{ route('pos.receipt', $sale) }}"
                                            target="_blank"
                                            class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                            Reimprimir
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

            <div class="mt-5">
                {{ $sales->links() }}
            </div>

        @else

            <div class="py-10 text-center text-slate-500">
                No se encontraron ventas para los filtros seleccionados.
            </div>

        @endif

    </x-card>

</div>

@endsection
